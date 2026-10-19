<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TravelRequest extends Model
{
    use HasFactory;

    protected $fillable = ['request_no', 'user_id', 'project_id', 'route', 'transport', 'start_date', 'end_date', 'cost', 'status', 'purpose', 'duration', 'policy_status', 'policy_code'];

    /**
     * Label durasi otomatis dari tanggal (inklusif), mis. "5 Hari (4 Malam)".
     * Sumber tunggal label ini: dipakai controller saat store/update dan
     * migration backfill baris lama. nights mengikuti rumus plafon hotel
     * (PolicyCheck::totalPlafon) — minimum 1 meski perjalanan 1 hari.
     */
    public static function durationLabel(?string $start, ?string $end): ?string
    {
        if (! $start || ! $end) {
            return null;
        }
        $days = \App\Support\PolicyCheck::tripDays($start, $end);
        $nights = max(1, $days - 1);

        return $days.' Hari ('.$nights.' Malam)';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function approvals()
    {
        return $this->hasMany(ApprovalQueue::class);
    }

    public function advances()
    {
        return $this->hasMany(Advance::class);
    }

    public function reimbursements()
    {
        return $this->hasMany(Reimbursement::class);
    }

    public function documents()
    {
        return $this->hasMany(TravelDocument::class);
    }
}
