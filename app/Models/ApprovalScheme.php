<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ApprovalScheme extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function levels()
    {
        return $this->hasMany(ApprovalLevel::class, 'scheme_id')->orderBy('step');
    }
}
