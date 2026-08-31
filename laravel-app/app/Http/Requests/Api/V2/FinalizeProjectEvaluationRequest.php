<?php

namespace App\Http\Requests\Api\V2;

use App\Models\ProjectEvaluation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FinalizeProjectEvaluationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $evaluation = $this->route('evaluation');

        return $evaluation instanceof ProjectEvaluation
            && $this->user()?->can('finalize', $evaluation) === true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['pending', 'passed', 'failed'])],
            'decision_note' => ['required', 'string', 'max:5000'],
            'evaluation_status_id' => ['prohibited'],
            'finalized_by' => ['prohibited'],
            'finalized_at' => ['prohibited'],
            'scores' => ['prohibited'],
        ];
    }
}
