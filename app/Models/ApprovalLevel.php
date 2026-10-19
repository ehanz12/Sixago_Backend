<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalLevel extends Model
{
    use HasFactory;

    protected $fillable = [
        'scheme_id', 'step', 'title', 'role', 'trigger', 'sla', 'locked',
    ];

    protected $casts = [
        'locked' => 'boolean',
    ];

    public function scheme()
    {
        return $this->belongsTo(ApprovalScheme::class, 'scheme_id');
    }
}
