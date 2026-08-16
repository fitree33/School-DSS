<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SchoolBudget extends Model
{
    protected $fillable = [
        'fiscal_year_id',
        'total_amount',
        'notes',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
    ];

    public function fiscalYear()
    {
        return $this->belongsTo(FiscalYear::class);
    }

    public function departmentBudgets()
    {
        return $this->hasMany(DepartmentBudget::class);
    }
}
