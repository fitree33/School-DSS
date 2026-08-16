<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProjectKpi extends Model
{
    protected $fillable = [
        'project_id',
        'name',
        'target_value',
        'unit',
        'actual_value',
    ];

    protected $casts = [
        'target_value' => 'decimal:2',
        'actual_value' => 'decimal:2',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
