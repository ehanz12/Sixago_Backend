<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TravelDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'travel_request_id', 'type', 'title', 'file_path', 'file_url',
        'mime', 'size_kb', 'ocr_status', 'amount',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function travelRequest()
    {
        return $this->belongsTo(TravelRequest::class);
    }
}
