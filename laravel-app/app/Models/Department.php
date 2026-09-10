<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Department extends Model
{
    protected $fillable = ['name', 'description'];

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function documentImports()
    {
        return $this->hasMany(DocumentImport::class, 'uploader_department_id');
    }

    public function departmentBudgets()
    {
        return $this->hasMany(DepartmentBudget::class);
    }
}
