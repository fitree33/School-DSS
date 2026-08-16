<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolPlan extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'code',
        'name',
        'description',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }
}
