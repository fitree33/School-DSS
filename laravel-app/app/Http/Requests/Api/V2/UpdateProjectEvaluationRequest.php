<?php

namespace App\Http\Requests\Api\V2;

use App\Models\ProjectEvaluation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProjectEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $evaluation = $this->route('evaluation');

        return $evaluation instanceof ProjectEvaluation
            && $this->user()?->can('update', $evaluation) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'evaluated_at' => ['required', 'date'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'scores' => ['required', 'array', 'min:1', 'max:100'],
            'scores.*.evaluation_criterion_id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('evaluation_criteria', 'id'),
            ],
            'scores.*.score' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:999999.99'],
            'scores.*.comment' => ['nullable', 'string', 'max:3000'],
            'evaluation_framework_id' => ['prohibited'],
            'evaluator_id' => ['prohibited'],
            'round' => ['prohibited'],
            'finalized_by' => ['prohibited'],
            'finalized_at' => ['prohibited'],
            'status' => ['prohibited'],
            'evaluation_status_id' => ['prohibited'],
        ];
    }
}
