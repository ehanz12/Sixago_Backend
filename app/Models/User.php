<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use App\Support\Positions;
use Illuminate\Notifications\Notifiable;

use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'nik', 'email', 'password', 'department_id', 'role', 'manager_id', 'grade', 'status', 'avatar', 'whatsapp', 'must_change_password', 'email_notif_enabled'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function manager()
    {
        return $this->belongsTo(User::class, 'manager_id');
    }

    public function subordinates()
    {
        return $this->hasMany(User::class, 'manager_id');
    }

    public function travelRequests()
    {
        return $this->hasMany(TravelRequest::class);
    }

    public function ledDepartment()
    {
        return $this->hasOne(Department::class, 'leader_id');
    }

    public function managedProjects()
    {
        return $this->hasMany(Project::class, 'pm_id');
    }

    public function roleModel()
    {
        return Role::where('name', $this->role)->first();
    }

    /** Daftar permission efektif (Super Admin = ['*']). */
    public function permissions(): array
    {
        if ($this->role === 'Super Admin') {
            return ['*'];
        }

        return $this->roleModel()?->permissions ?? [];
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function isSuperAdmin(): bool
    {
        return $this->role === 'Super Admin';
    }

    public function hasPermission(string $permission): bool
    {
        $perms = $this->permissions();

        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    /**
     * Apakah user boleh memutus antrian approval ini?
     * Line Manager → level 1, Division Head → level 2,
     * Finance Approver → level 3, Super Admin → semua,
     * atau sebagai Plt via delegasi aktif (lihat delegationFor()).
     */
    public function canDecide(ApprovalQueue $queue): bool
    {
        return $this->canDecideOwn($queue) || $this->delegationFor($queue) !== null;
    }

    /** Hak decide dari role sendiri (tanpa delegasi, anti-rekursi). */
    public function canDecideOwn(ApprovalQueue $queue): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        $level = (int) str($queue->level)->before(' ')->toString();

        return match ($this->role) {
            \App\Support\UserRoles::LINE_MANAGER => $level === 1,
            \App\Support\UserRoles::DIVISION_HEAD => $level === 2,
            \App\Support\UserRoles::FINANCE => $level === 3,
            default => false,
        };
    }

    /**
     * Delegasi aktif di mana user ini sebagai penerima (Plt). */
    public function activeReceivedDelegations()
    {
        return Delegation::where('delegatee_id', $this->id)
            ->where('status', 'Aktif')
            ->whereDate('start_date', '<=', now())
            ->whereDate('end_date', '>=', now());
    }

    /**
     * Cari delegasi aktif yang memberi user ini wewenang atas antrean tersebut
     * (delegator berhak atas levelnya + nominal dalam max_amount + scope cocok).
     */
    public function delegationFor(ApprovalQueue $queue): ?Delegation
    {
        if (! Setting::bool('delegation_enabled', true)) {
            return null;
        }

        // Penerima mandat harus role approver. Dicek di RUNTIME, bukan hanya
        // saat delegasi dibuat — tanpa ini delegasi lama yang menunjuk staf
        // biasa tetap memberi wewenang memutus semua level.
        if (! Positions::isApproverCapable($this->role) || $this->status !== 'Aktif') {
            return null;
        }

        $amount = (float) ($queue->amount ?? $queue->travelRequest?->cost ?? 0);

        foreach ($this->activeReceivedDelegations()->with('delegator')->get() as $d) {
            // Konvensi: max_amount 0 = tanpa batas nominal.
            // (Cast decimal Eloquent mengubah null jadi string truthy, jadi baca raw + bandingkan numerik.)
            $max = (float) $d->getRawOriginal('max_amount');
            if ($max > 0 && $amount > $max) {
                continue;
            }
            if (! self::delegationScopeCovers($d->scope, $queue->type)) {
                continue;
            }
            if ($d->delegator && $d->delegator->canDecideOwn($queue)) {
                return $d;
            }
        }

        return null;
    }

    private static function delegationScopeCovers(string $scope, string $type): bool
    {
        $s = mb_strtolower($scope);
        if (str_contains($s, 'penuh') || str_contains($s, 'full')) {
            return true;
        }
        if (str_contains($s, 'tiket') || str_contains($s, 'travel')) {
            return $type === 'Travel';
        }

        return false;
    }

    /** ID user yang datanya boleh dilihat user ini (skop visibilitas). */
    public function visibleUserIds(): ?array
    {
        if ($this->isSuperAdmin() || $this->hasRole('Finance Approver')) {
            return null; // semua
        }

        if ($this->hasRole('Division Head')) {
            return User::where('department_id', $this->department_id)->pluck('id')->all();
        }

        if ($this->hasRole('Line Manager')) {
            return User::where('manager_id', $this->id)->orWhere('id', $this->id)->pluck('id')->all();
        }

        return [$this->id]; // Employee: diri sendiri
    }

    /**
     * Skop visibilitas + skop delegator yang di-Plt-kan
     * (agar Plt bisa melihat antrean milik delegatornya).
     */
    public function visibleUserIdsWithDelegation(): ?array
    {
        $ids = $this->visibleUserIds();
        if ($ids === null) {
            return null;
        }

        foreach ($this->activeReceivedDelegations()->with('delegator')->get() as $d) {
            if (! $d->delegator) {
                continue;
            }
            $delegatorIds = $d->delegator->visibleUserIds();
            if ($delegatorIds === null) {
                return null;
            }
            $ids = array_values(array_unique(array_merge($ids, $delegatorIds)));
        }

        return $ids;
    }
}
