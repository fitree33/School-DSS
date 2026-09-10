<?php

namespace App\Http\Requests\Api\V2;

use App\Models\DocumentImport;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

final class StoreDocumentImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', DocumentImport::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $maxKilobytes = max(1, (int) ceil($this->maxBytes() / 1024));

        return [
            'document' => [
                'bail',
                'required',
                'file',
                'mimes:pdf',
                'mimetypes:application/pdf,application/x-pdf',
                "max:{$maxKilobytes}",
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $file = $this->file('document');

            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                return;
            }

            if (($file->getSize() ?: 0) > $this->maxBytes()) {
                $validator->errors()->add('document', 'The PDF exceeds the configured upload size limit.');
            }

            if (strtolower($file->getClientOriginalExtension()) !== 'pdf') {
                $validator->errors()->add('document', 'The uploaded document must use the .pdf extension.');
            }

            $handle = @fopen($file->getRealPath(), 'rb');
            $magic = is_resource($handle) ? fread($handle, 5) : false;

            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($magic !== '%PDF-') {
                $validator->errors()->add('document', 'The uploaded document is not a valid PDF file.');
            }
        });
    }

    private function maxBytes(): int
    {
        return max(1, (int) config('project_imports.upload.max_bytes', 10 * 1024 * 1024));
    }
}
