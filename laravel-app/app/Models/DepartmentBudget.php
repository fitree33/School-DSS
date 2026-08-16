<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DepartmentBudget extends Model
{
    protected $fillable = [
        'school_budget_id',
        'department_id',
        'allocated_amount',
        'is_allocated',
        'allocated_at',
        'allocated_by',
        'notes',
    ];

    protected $casts = [
        'allocated_amount' => 'decimal:2',
        'is_allocated' => 'boolean',
        'allocated_at' => 'datetime',
    ];

    public function schoolBudget()
    {
        return $this->belongsTo(SchoolBudget::class);
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function allocatedBy()
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }
}
