<?php

namespace App\Http\Requests\Api\V2;

use App\Models\DocumentImport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class ConfirmDocumentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        $documentImport = $this->route('documentImport');

        return $documentImport instanceof DocumentImport
            && ($this->user()?->can('confirm', $documentImport) ?? false);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'preview_revision_id' => ['required', 'integer', 'min:1'],
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
                'preview_revision_id',
                'idempotency_key',
            ]);

            if ($unknown !== []) {
                $validator->errors()->add('_request', 'Confirm accepts only a preview revision and idempotency key.');
            }
        });
    }
}
