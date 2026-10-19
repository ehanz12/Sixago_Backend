<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Role;
use App\Models\User;
use App\Support\UserRoles;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    /** Berapa anggota yang tampil sebagai preview di kartu departemen. */
    private const PREVIEW_LIMIT = 4;

    public function index(Request $request)
    {
        $query = Department::with(['leader.department'])->withCount('users');

        // Scope sesuai jangkauan user (Super Admin & Finance → semua). Tanpa ini
        // departemen — beserta nama Kepala Departemennya — terbuka ke semua user
        // yang sekadar login, karena route ini tidak dibatasi middleware role.
        $visibleIds = $request->user()->visibleUserIds();
        if ($visibleIds !== null) {
            // Alias tabel di dalam whereHas('users') adalah `users`, bukan `u`.
            $query->whereHas('users', fn ($u) => $u->whereIn('users.id', $visibleIds));
        }

        if ($request->filled('search')) {
            $q = $request->search;
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%")
                ->orWhereHas('leader', fn ($l) => $l->where('name', 'like', "%{$q}%")));
        }

        $page = $query->latest()->paginate((int) $request->input('per_page', 10));

        $page->getCollection()->transform(function (Department $dept) {
            // Kolom `members` tidak pernah diisi (selalu 0). Sumber kebenaran
            // adalah relasi users() → timpa agar semua konsumen ikut benar.
            $dept->setAttribute('members', (int) $dept->users_count);
            $dept->setAttribute('member_preview', $this->previewFor($dept));

            return $dept;
        });

        return response()->json($page);
    }

    /** Potongan anggota untuk kartu: nama, role, grade, avatar. */
    private function previewFor(Department $dept): array
    {
        return $dept->users()
            ->orderByRaw(
                "CASE role WHEN 'Division Head' THEN 1 WHEN 'Line Manager' THEN 2"
                ." WHEN 'Finance Approver' THEN 3 WHEN 'Super Admin' THEN 4 ELSE 9 END"
            )
            ->orderBy('name')
            ->limit(self::PREVIEW_LIMIT)
            ->get()
            ->map(fn ($u) => [
                'id' => $u->id,
                'name' => $u->name,
                'role' => $u->role,
                'grade' => $u->grade,
                'avatar' => $u->avatar,
                'status' => $u->status,
            ])
            ->all();
    }

    /**
     * Daftar lengkap anggota satu departemen.
     *
     * Route dibatasi `role:Super Admin` (departments.php middleware), dan
     * scoping visibleUserIds() tetap dipasang sebagai jaring kedua supaya
     * daftar ini tidak bocor ke role lain seandainya batasan middleware
     * suatu saat dilonggarkan.
     */
    public function members(Request $request, Department $department)
    {
        $visibleIds = $request->user()->visibleUserIds();

        $query = $department->users()->with('manager');
        if ($visibleIds !== null) {
            $query->whereIn('users.id', $visibleIds);
        }
        if ($request->filled('search')) {
            $q = trim((string) $request->search);
            $query->where(fn ($w) => $w->where('name', 'like', "%{$q}%")
                ->orWhere('nik', 'like', "%{$q}%"));
        }

        $page = $query->orderBy('name')->paginate((int) $request->input('per_page', 50));

        $page->getCollection()->transform(fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'nik' => $u->nik,
            'role' => $u->role,
            'grade' => $u->grade,
            'status' => $u->status,
            'avatar' => $u->avatar,
            'manager' => $u->manager?->name ?? null,
        ]);

        return response()->json($page);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:50|unique:departments,code',
            'name' => 'required|string|max:255',
            'budget_total' => 'nullable|numeric|min:0',
            'sla' => 'required|string|max:255',
            'leader_id' => 'nullable|integer|exists:users,id',
        ]);
        $this->assertLeaderIsValid($data['leader_id'] ?? null, null);

        return response()->json(Department::create($data)->load('leader'), 201);
    }

    /**
     * Kepala Divisi struktural harus ber-role Division Head di departemen itu,
     * atau Super Admin (organisasi kecil sering memangkai peran ini).
     *
     * Tanpa ini leader_id bisa menunjuk siapa saja, dan level 2 approval
     * jadi milik orang yang tidak berwenang approve.
     */
    private function assertLeaderIsValid(?int $leaderId, ?Department $department): void
    {
        if ($leaderId === null) {
            return;
        }
        $leader = \App\Models\User::find($leaderId);
        if ($leader === null) {
            return; // sudah tertangkap validasi exists
        }

        $errors = [];
        if ($leader->isSuperAdmin()) {
            return;
        }
        if (! $leader->hasRole(\App\Support\UserRoles::DIVISION_HEAD)) {
            $errors['leader_id'] = ['Kepala Divisi harus ber-role Division Head (atau Super Admin).'];
        } elseif ($department !== null && (int) $leader->department_id !== (int) $department->id) {
            $errors['leader_id'] = ['Kepala Divisi harus anggota departemen ini.'];
        }

        if ($errors !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages($errors);
        }
    }

    public function show(Request $request, Department $department)
    {
        $visibleIds = $request->user()->visibleUserIds();
        if ($visibleIds !== null && ! $department->users()->whereIn('users.id', $visibleIds)->exists()) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        $department->load('leader')->loadCount('users');
        $department->setAttribute('members', (int) $department->users_count);
        $department->setAttribute('member_preview', $this->previewFor($department));

        return response()->json($department);
    }

    public function update(Request $request, Department $department)
    {
        // Super Admin bebas; Division Head hanya departemennya sendiri
        $me = $request->user();
        if (! $me->isSuperAdmin() && ! ($me->hasRole('Division Head') && $me->department_id === $department->id)) {
            return response()->json(['message' => 'Akses ditolak untuk role Anda.'], 403);
        }

        $old = $department->only('name', 'budget_total', 'budget_used', 'sla', 'leader_id');
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'budget_total' => 'nullable|numeric|min:0',
            'budget_used' => 'sometimes|numeric|min:0',
            'sla' => 'sometimes|string|max:255',
            'leader_id' => 'nullable|integer|exists:users,id',
        ]);
        $this->assertLeaderIsValid(array_key_exists('leader_id', $data) ? $data['leader_id'] : null, $department);

        $department->update($data);
        \App\Support\Audits::record('department.update', $department, $old, $department->only('name', 'budget_total', 'budget_used', 'sla', 'leader_id'), $request);

        return response()->json($department->load('leader'));
    }

    public function destroy(Department $department)
    {
        if ($department->users()->exists()) {
            return response()->json(['message' => 'Tidak dapat dihapus: departemen masih memiliki anggota. Pindahkan anggota terlebih dahulu.'], 409);
        }

        $department->delete();

        return response()->noContent();
    }

    /** Matriks hak akses — tab "Definisi Hak Akses & Matriks Role". */
    public function roles()
    {
        return response()->json(Role::latest()->get());
    }

    /** Buat custom role baru dari modal departments. */
    public function storeRole(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:roles,name',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
        ]);

        if ($this->isCanonicalRoleName($data['name'])) {
            return response()->json([
                'message' => 'Nama tersebut sudah dipakai role sistem. Gunakan nama lain.',
                'errors' => ['name' => ['Nama role sistem tidak dapat dipakai ulang.']],
            ], 422);
        }

        return response()->json(Role::create($data), 201);
    }

    public function updateRole(Request $request, Role $role)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:100|unique:roles,name,'.$role->id,
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
        ]);

        if (isset($data['name']) && $this->isCanonicalRoleName($data['name'])) {
            return response()->json([
                'message' => 'Nama tersebut sudah dipakai role sistem. Gunakan nama lain.',
                'errors' => ['name' => ['Nama role sistem tidak dapat dipakai ulang.']],
            ], 422);
        }

        $role->update($data);

        return response()->json($role->fresh());
    }

    public function destroyRole(Request $request, Role $role)
    {
        // users.role itu kolom string, bukan foreign key ke tabel roles. Menghapus
        // role karena itu TIDAK merusak integritas referensial — hanya diam-diam
        // membuat user kehilangan rolenya: hasRole() jadi false, dan
        // user dari filter, tanpa jejak error. Jadi harus dicek eksplisit.
        $affected = User::where('role', $role->name)->count();

        if ($affected > 0) {
            return response()->json([
                'message' => 'Tidak dapat dihapus: masih ada '.$affected
                    .' user yang memakai role ini. Pindahkan mereka dulu.',
            ], 409);
        }

        $role->delete();

        return response()->noContent();
    }

    /** Nama role kanonik sistem hanya boleh datang dari UserRoles, bukan dari input. */
    private function isCanonicalRoleName(string $name): bool
    {
        return in_array(trim($name), UserRoles::all(), true);
    }
}
