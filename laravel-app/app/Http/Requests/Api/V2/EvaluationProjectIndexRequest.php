<?php

namespace App\Http\Requests\Api\V2;

use App\Models\ProjectEvaluation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluationProjectIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', ProjectEvaluation::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'fiscal_year_id' => ['nullable', 'integer', Rule::exists('fiscal_years', 'id')],
            'department_id' => ['nullable', 'integer', Rule::exists('departments', 'id')],
            'evaluation_status' => [
                'nullable',
                'string',
                Rule::in(['pending', 'passed', 'failed']),
            ],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
