<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Reimbursement extends Model
{
    use HasFactory;

    protected $fillable = [
        'travel_request_id', 'request_no', 'actual_expense', 'advance',
        'reconciliation', 'ocr_status', 'receipt_count', 'status',
        'decided_by', 'decided_at',
    ];

    protected $casts = [
        'actual_expense' => 'decimal:2',
        'advance' => 'decimal:2',
        'decided_at' => 'datetime',
    ];

    /** Finance yang terakhir memutuskan (verify/settlement/reject). */
    public function decidedBy()
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function travelRequest()
    {
        return $this->belongsTo(TravelRequest::class);
    }
}
