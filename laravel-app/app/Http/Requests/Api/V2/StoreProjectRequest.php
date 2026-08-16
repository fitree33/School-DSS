<?php

namespace App\Http\Requests\Api\V2;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Project::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'project_code' => ['nullable', 'string', 'max:50', Rule::unique('projects', 'project_code')],
            'objective' => ['required', 'string', 'max:16000'],
            'description' => ['nullable', 'string', 'max:16000'],
            'rationale' => ['nullable', 'string'],
            'target_group' => ['nullable', 'string', 'max:2000'],
            'strategy' => ['nullable', 'string', 'max:2000'],
            'key_points' => ['nullable', 'string'],
            'budget' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'budget_source' => ['nullable', 'string', 'max:120'],
            'responsible_person' => ['nullable', 'string', 'max:255'],
            'monitor_person' => ['nullable', 'string', 'max:255'],
            'evaluation_method' => ['nullable', 'string', 'max:16000'],
            'evaluation_tools' => ['nullable', 'string', 'max:16000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'project_category_id' => ['required', 'integer', Rule::exists('project_categories', 'id')],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'fiscal_year_id' => ['required', 'integer', Rule::exists('fiscal_years', 'id')],
            'school_plan_id' => [
                'nullable',
                'integer',
                Rule::exists('school_plans', 'id')->where(
                    fn ($query) => $query->where('fiscal_year_id', $this->input('fiscal_year_id'))
                ),
            ],
            'user_id' => ['prohibited'],
            'actual_spent' => ['prohibited'],
            'project_status_id' => ['prohibited'],
            'project_execution_status_id' => ['prohibited'],
            'evaluation_status_id' => ['prohibited'],
            'execution_status' => ['prohibited'],
            'evaluation_status' => ['prohibited'],
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
            fn (Validator $validator) => $this->validateDateRange($validator),
            function (Validator $validator): void {
                $user = $this->user();

                if (! $user || $user->hasPermission('projects.edit_all')) {
                    return;
                }

                if (! $user->department_id || (int) $this->input('department_id') !== (int) $user->department_id) {
                    $validator->errors()->add(
                        'department_id',
                        'You may only create projects for your own department.'
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

        $startDate = $this->date('start_date');
        $endDate = $this->date('end_date');

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

        if (Project::withTrashed()
            ->whereRaw('UPPER(TRIM(project_code)) = ?', [$projectCode])
            ->exists()) {
            $validator->errors()->add('project_code', 'The project code has already been taken.');
        }
    }
}
