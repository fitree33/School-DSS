<?php

namespace App\Http\Requests\Api\V2;

use App\Models\EvaluationFramework;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EvaluationFrameworkIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', EvaluationFramework::class) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'fiscal_year_id' => ['nullable', 'integer', Rule::exists('fiscal_years', 'id')],
            'is_active' => ['nullable', 'boolean'],
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
