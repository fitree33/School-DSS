<?php

namespace App\Http\Requests\Api\V2;

use App\Models\Project;
use App\Models\ProjectEvaluation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreProjectEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');

        return $project instanceof Project
            && $this->user()?->can('create', [ProjectEvaluation::class, $project]) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'evaluation_framework_id' => [
                'required',
                'integer',
                Rule::exists('evaluation_frameworks', 'id'),
            ],
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
            'evaluator_id' => ['prohibited'],
            'round' => ['prohibited'],
            'finalized_by' => ['prohibited'],
            'finalized_at' => ['prohibited'],
            'status' => ['prohibited'],
            'evaluation_status_id' => ['prohibited'],
        ];
    }
}
