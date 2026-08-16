<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AcademicYear extends Model
{
    protected $fillable = ['year', 'is_active', 'start_date', 'end_date', 'is_locked'];

    protected $casts = [
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
        'start_date' => 'date',
        'end_date' => 'date',
    ];
}
