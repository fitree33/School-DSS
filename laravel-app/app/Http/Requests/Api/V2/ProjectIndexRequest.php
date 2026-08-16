<?php

namespace App\Http\Requests\Api\V2;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', Project::class) === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'fiscal_year_id' => ['nullable', 'integer', Rule::exists('fiscal_years', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'execution_status' => [
                'nullable',
                'string',
                'max:50',
                Rule::in(['not_started', 'in_progress', 'completed']),
            ],
            'evaluation_status' => [
                'nullable',
                'string',
                'max:50',
                Rule::in(['pending', 'passed', 'failed']),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('q')) {
            $keyword = trim((string) $this->input('q'));

            $this->merge(['q' => $keyword === '' ? null : $keyword]);
        }
    }
}
