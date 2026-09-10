<?php

namespace App\Http\Requests\Api\V2;

use App\Enums\DocumentImportStatus;
use App\Models\DocumentImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class DocumentImportIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', DocumentImport::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'status' => [
                'nullable',
                'string',
                Rule::in(array_column(DocumentImportStatus::cases(), 'value')),
            ],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
