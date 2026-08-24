<?php

namespace App\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSchoolBudgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('budgets.manage') === true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'total_amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
