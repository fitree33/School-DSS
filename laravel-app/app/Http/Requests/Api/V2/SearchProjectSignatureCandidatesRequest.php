<?php

namespace App\Http\Requests\Api\V2;

use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SearchProjectSignatureCandidatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project && $this->user()?->can('viewSignatures', $project), 404);

        return $this->user()?->can('manageSignatures', $project) === true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q'))) {
            $this->merge(['q' => preg_replace('/^\s+|\s+$/u', '', $this->input('q'))]);
        }
    }

    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:20', 'regex:/\A[1-9][0-9]*\z/'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:2147483647', 'regex:/\A[1-9][0-9]*\z/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['q', 'per_page', 'page']) !== []) {
                $validator->errors()->add('request', 'Unsupported request fields.');
            }
        }];
    }
}
