<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TravelPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'code', 'grade', 'transport_plafon', 'hotel_plafon',
        'per_diem', 'local_transport', 'sla', 'enabled', 'region',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];
}
