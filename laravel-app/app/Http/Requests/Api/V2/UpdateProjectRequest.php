<?php

namespace App\Http\Requests\Api\V2;

use App\Models\FiscalYear;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        $user = $this->user();

        return $user !== null
            && $project instanceof Project
            && $user->can('update', $project);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var Project|null $project */
        $project = $this->route('project');
        $effectiveFiscalYearId = $this->input('fiscal_year_id', $project?->fiscal_year_id);

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'project_code' => [
                'sometimes',
                'nullable',
                'string',
                'max:50',
                Rule::unique('projects', 'project_code')->ignore($project?->id),
            ],
            'objective' => ['sometimes', 'required', 'string', 'max:16000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'rationale' => ['sometimes', 'nullable', 'string'],
            'target_group' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'strategy' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'key_points' => ['sometimes', 'nullable', 'string'],
            'budget' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'actual_spent' => ['sometimes', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'budget_source' => ['sometimes', 'nullable', 'string', 'max:120'],
            'responsible_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'monitor_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'evaluation_method' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'evaluation_tools' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date'],
            'department_id' => ['sometimes', 'required', 'integer', Rule::exists('departments', 'id')],
            'project_category_id' => [
                'sometimes',
                'required',
                'integer',
                Rule::exists('project_categories', 'id'),
            ],
            'academic_year_id' => ['sometimes', 'required', 'integer', Rule::exists('academic_years', 'id')],
            'fiscal_year_id' => ['sometimes', 'required', 'integer', Rule::exists('fiscal_years', 'id')],
            'school_plan_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('school_plans', 'id')->where(
                    fn ($query) => $query->where('fiscal_year_id', $effectiveFiscalYearId)
                ),
            ],
            'execution_status' => [
                'sometimes',
                'required',
                'string',
                Rule::in(['not_started', 'in_progress', 'completed']),
            ],
            'execution_status_comment' => [
                'sometimes',
                'nullable',
                'string',
                'max:2000',
            ],
            'evaluation_status' => ['prohibited'],
            'user_id' => ['prohibited'],
            'project_status_id' => ['prohibited'],
            'project_execution_status_id' => ['prohibited'],
            'evaluation_status_id' => ['prohibited'],
            'ai_summary' => ['prohibited'],
            'ai_summarized_at' => ['prohibited'],
            'submitted_at' => ['prohibited'],
            'screened_at' => ['prohibited'],
            'approved_at' => ['prohibited'],
            'completed_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->validateProjectCodeUniqueness($validator),
            function (Validator $validator): void {
                if ($this->exists('execution_status_comment') && ! $this->exists('execution_status')) {
                    $validator->errors()->add(
                        'execution_status_comment',
                        'An execution status is required when providing an execution status comment.'
                    );
                }
            },
            fn (Validator $validator) => $this->validateDateRange($validator),
            fn (Validator $validator) => $this->validateFiscalYearIsWritable($validator),
            fn (Validator $validator) => $this->validateExecutionTransition($validator),
            function (Validator $validator): void {
                $user = $this->user();

                if (! $user || ! $this->exists('department_id') || $user->hasPermission('projects.edit_all')) {
                    return;
                }

                if (! $user->department_id || (int) $this->input('department_id') !== (int) $user->department_id) {
                    $validator->errors()->add(
                        'department_id',
                        'You may only move projects within your own department.'
                    );
                }
            },
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('project_code') && is_string($this->input('project_code'))) {
            $projectCode = mb_strtoupper(trim($this->input('project_code')));

            $this->merge(['project_code' => $projectCode === '' ? null : $projectCode]);
        }
    }

    private function validateDateRange(Validator $validator): void
    {
        if ($validator->errors()->has('start_date') || $validator->errors()->has('end_date')) {
            return;
        }

        /** @var Project|null $project */
        $project = $this->route('project');
        $startDate = $this->exists('start_date')
            ? $this->date('start_date')
            : $project?->start_date;
        $endDate = $this->exists('end_date')
            ? $this->date('end_date')
            : $project?->end_date;

        if ($startDate && $endDate && $endDate->lt($startDate)) {
            $validator->errors()->add('end_date', 'The end date must be on or after the start date.');
        }
    }

    private function validateProjectCodeUniqueness(Validator $validator): void
    {
        $projectCode = $this->input('project_code');

        if (! is_string($projectCode) || $projectCode === '' || $validator->errors()->has('project_code')) {
            return;
        }

        /** @var Project|null $project */
        $project = $this->route('project');
        $duplicateExists = Project::withTrashed()
            ->when($project, fn ($query) => $query->whereKeyNot($project->id))
            ->whereRaw('UPPER(TRIM(project_code)) = ?', [$projectCode])
            ->exists();

        if ($duplicateExists) {
            $validator->errors()->add('project_code', 'The project code has already been taken.');
        }
    }

    private function validateFiscalYearIsWritable(Validator $validator): void
    {
        if (! $this->exists('fiscal_year_id') || $validator->errors()->has('fiscal_year_id')) {
            return;
        }

        $fiscalYear = FiscalYear::query()->find($this->integer('fiscal_year_id'));

        if ($fiscalYear?->is_locked) {
            $validator->errors()->add(
                'fiscal_year_id',
                'The selected fiscal year is locked and read-only.'
            );
        }
    }

    private function validateExecutionTransition(Validator $validator): void
    {
        if (! $this->exists('execution_status') || $validator->errors()->has('execution_status')) {
            return;
        }

        /** @var Project|null $project */
        $project = $this->route('project');
        $current = $project?->executionStatus?->code;
        $requested = $this->string('execution_status')->toString();
        $allowed = match ($current) {
            'not_started' => ['not_started', 'in_progress'],
            'in_progress' => ['in_progress', 'completed'],
            'completed' => ['completed'],
            default => ['not_started'],
        };

        if (! in_array($requested, $allowed, true)) {
            $validator->errors()->add(
                'execution_status',
                'The requested execution status is not a permitted next step.'
            );
        }
    }
}
