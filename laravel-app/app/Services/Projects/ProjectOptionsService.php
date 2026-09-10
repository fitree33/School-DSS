<?php

namespace App\Services\Projects;

use App\Models\AcademicYear;
use App\Models\Department;
use App\Models\EvaluationStatus;
use App\Models\FiscalYear;
use App\Models\Project;
use App\Models\ProjectCategory;
use App\Models\ProjectExecutionStatus;
use App\Models\SchoolPlan;

final class ProjectOptionsService
{
    public function all(): array
    {
        return [
            'fiscal_years' => FiscalYear::query()
                ->orderByDesc('year')
                ->get()
                ->map(fn (FiscalYear $year) => [
                    'id' => $year->id,
                    'year' => $year->year,
                    'is_active' => $year->is_active,
                    'is_locked' => $year->is_locked,
                ]),
            'academic_years' => AcademicYear::query()
                ->orderByDesc('year')
                ->get()
                ->map(fn (AcademicYear $year) => [
                    'id' => $year->id,
                    'year' => $year->year,
                    'is_active' => $year->is_active,
                    'is_locked' => $year->is_locked,
                ]),
            'departments' => Department::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'project_categories' => ProjectCategory::query()
                ->orderBy('name')
                ->get(['id', 'name']),
            'school_plans' => SchoolPlan::query()
                ->orderBy('fiscal_year_id')
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (SchoolPlan $plan) => [
                    'id' => $plan->id,
                    'fiscal_year_id' => $plan->fiscal_year_id,
                    'code' => $plan->code,
                    'name' => $plan->name,
                    'is_active' => $plan->is_active,
                ]),
            'execution_statuses' => ProjectExecutionStatus::query()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (ProjectExecutionStatus $status) => [
                    'id' => $status->id,
                    'code' => $status->code,
                    'name' => $status->name,
                    'color' => $status->color,
                    'is_terminal' => $status->is_terminal,
                ]),
            'evaluation_statuses' => EvaluationStatus::query()
                ->orderBy('sort_order')
                ->get()
                ->map(fn (EvaluationStatus $status) => [
                    'id' => $status->id,
                    'code' => $status->code,
                    'name' => $status->name,
                    'color' => $status->color,
                    'is_terminal' => $status->is_terminal,
                ]),
            'budget_sources' => Project::BUDGET_SOURCES,
        ];
    }
}
