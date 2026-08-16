<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DssResult extends Model
{
    protected $fillable = [
        'project_id',
        'calculation_version',
        'total_score',
        'rank',
        'recommendation',
        'explanation',
        'generated_by',
        'generated_at',
    ];

    protected $casts = [
        'total_score' => 'decimal:2',
        'generated_at' => 'datetime',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function generator()
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
