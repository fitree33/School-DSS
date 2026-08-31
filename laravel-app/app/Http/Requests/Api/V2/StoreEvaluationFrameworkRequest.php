<?php

namespace App\Http\Requests\Api\V2;

use App\Http\Requests\Api\V2\Concerns\ValidatesEvaluationFrameworkPayload;
use App\Models\EvaluationFramework;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreEvaluationFrameworkRequest extends FormRequest
{
    use ValidatesEvaluationFrameworkPayload;

    public function authorize(): bool
    {
        return $this->user()?->can('create', EvaluationFramework::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:100'],
            'version' => [
                'required',
                'string',
                'max:50',
                Rule::unique('evaluation_frameworks', 'version')->where(
                    fn ($query) => $query->where('code', $this->input('code')),
                ),
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:16000'],
            'fiscal_year_id' => ['nullable', 'integer', Rule::exists('fiscal_years', 'id')],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date'],
            'criteria' => ['required', 'array', 'min:1', 'max:100'],
            'criteria.*.name' => ['required', 'string', 'max:255'],
            'criteria.*.description' => ['nullable', 'string', 'max:16000'],
            'criteria.*.max_score' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:999999.99'],
            'criteria.*.weight' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:999.99'],
            'criteria.*.sort_order' => ['sometimes', 'integer', 'min:0', 'max:65535'],
            'criteria.*.evaluation_method' => ['nullable', 'string', 'max:16000'],
            'criteria.*.evaluation_tools' => ['nullable', 'string', 'max:16000'],
            'criteria.*.is_active' => ['sometimes', 'boolean'],
            'is_active' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
        }

        if ($this->has('version')) {
            $this->merge(['version' => trim((string) $this->input('version'))]);
        }
    }
}
