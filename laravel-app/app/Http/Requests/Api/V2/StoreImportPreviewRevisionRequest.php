<?php

namespace App\Http\Requests\Api\V2;

use App\Models\DocumentImport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class StoreImportPreviewRevisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $documentImport = $this->route('documentImport');

        return $documentImport instanceof DocumentImport
            && ($this->user()?->can('review', $documentImport) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'base_revision_id' => ['required', 'integer', 'min:1'],
            'payload' => ['required', 'array'],
            'idempotency_key' => [
                'required',
                'string',
                'max:100',
                'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknown = array_diff(array_keys($this->all()), [
                'base_revision_id',
                'payload',
                'idempotency_key',
            ]);

            if ($unknown !== []) {
                $validator->errors()->add('_request', 'The request contains unsupported fields.');
            }
        });
    }
}
