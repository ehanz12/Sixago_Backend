<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Advance extends Model
{
    use HasFactory;

    protected $fillable = [
        'travel_request_id', 'request_no', 'amount', 'plafon', 'account',
        'account_name', 'method', 'status', 'purpose', 'destination',
        'transfer_ref', 'proof_path', 'disbursed_at', 'decided_by', 'decided_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'plafon' => 'decimal:2',
        'disbursed_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    /** Finance yang terakhir memutuskan (approve/reject/disburse). */
    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function travelRequest()
    {
        return $this->belongsTo(TravelRequest::class);
    }

    public function user()
    {
        return $this->hasOneThrough(User::class, TravelRequest::class, 'id', 'id', 'travel_request_id', 'user_id');
    }
}
