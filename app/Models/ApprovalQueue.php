<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalQueue extends Model
{
    use HasFactory;

    protected $fillable = [
        'travel_request_id', 'type', 'level', 'sequence', 'approver_id', 'status',
        'urgent', 'amount', 'justification',
        'sla_due_at', 'escalated', 'decided_by', 'on_behalf_of', 'decided_at', 'note',
    ];

    protected $casts = [
        'urgent' => 'boolean',
        'escalated' => 'boolean',
        'amount' => 'decimal:2',
        'sla_due_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

    public function travelRequest()
    {
        return $this->belongsTo(TravelRequest::class);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approver_id');
    }

    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function onBehalfOf()
    {
        return $this->belongsTo(User::class, 'on_behalf_of');
    }
}
