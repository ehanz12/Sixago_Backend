<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'wbs', 'name', 'department', 'location', 'pm_id', 'start_date', 'end_date', 'budget_used', 'budget_total', 'status', 'trips'];

    public function pm()
    {
        return $this->belongsTo(User::class, 'pm_id');
    }

    public function travelRequests()
    {
        return $this->hasMany(TravelRequest::class);
    }
}
