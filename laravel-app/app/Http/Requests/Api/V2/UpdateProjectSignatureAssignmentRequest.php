<?php

namespace App\Http\Requests\Api\V2;

use App\Models\Project;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateProjectSignatureAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $project = $this->route('project');
        abort_unless($project instanceof Project && $this->user()?->can('viewSignatures', $project), 404);

        return $this->user()?->can('manageSignatures', $project) === true;
    }

    public function rules(): array
    {
        return [
            'assigned_user_id' => [
                'present', 'nullable',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) || $value < 1) {
                        $fail('The assigned user must be a positive JSON integer or null.');
                    }
                },
            ],
            'assignment_revision' => [
                'required',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (! is_int($value) || $value < 0 || $value > 4294967295) {
                        $fail('The assignment revision must be a JSON integer between 0 and 4294967295.');
                    }
                },
            ],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['assigned_user_id', 'assignment_revision']) !== []) {
                $validator->errors()->add('request', 'Unsupported request fields.');
            }
        }];
    }
}
