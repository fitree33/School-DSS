<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\ValidatesEvaluationFrameworkPayload;
use App\Models\EvaluationFramework;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateEvaluationFrameworkRequest extends FormRequest
{
    use ValidatesEvaluationFrameworkPayload;

    public function authorize(): bool
    {
        $framework = $this->route('framework');

        return $framework instanceof EvaluationFramework
            && $this->user()?->can('update', $framework) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'fiscal_year_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('fiscal_years', 'id'),
            ],
            'effective_from' => ['sometimes', 'nullable', 'date'],
            'effective_to' => ['sometimes', 'nullable', 'date'],
            'criteria' => ['sometimes', 'required', 'array', 'min:1', 'max:100'],
            'criteria.*.name' => ['required_with:criteria', 'string', 'max:255'],
            'criteria.*.description' => ['nullable', 'string', 'max:16000'],
            'criteria.*.max_score' => ['required_with:criteria', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'],
            'criteria.*.weight' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999.99'],
            'criteria.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'criteria.*.evaluation_method' => ['nullable', 'string', 'max:16000'],
            'criteria.*.evaluation_tools' => ['nullable', 'string', 'max:16000'],
            'criteria.*.is_active' => ['sometimes', 'boolean'],
            'code' => ['prohibited'],
            'version' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];
    }
}
