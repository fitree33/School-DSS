<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SignDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'assignment_revision' => ['required', function ($attribute, $value, $fail): void {
                if (! is_int($value) || $value < 1 || $value > 4294967295) $fail('A positive JSON integer is required.');
            }],
            'placement_fingerprint' => ['required', 'string', 'regex:/\A[0-9a-f]{64}\z/D'],
            'idempotency_key' => ['required', 'string', 'uuid'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'Unsupported request fields.');
            }
        }];
    }
}
