<?php

namespace App\Http\Requests\Api\V2\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesEvaluationFrameworkPayload
{
    /** @return array<int, callable> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $this->validateEffectivePeriod($validator);
            $this->validateCriteriaConfiguration($validator);
        }];
    }

    private function validateEffectivePeriod(Validator $validator): void
    {
        if (! $this->filled('effective_from')
            || ! $this->filled('effective_to')
            || $validator->errors()->has('effective_from')
            || $validator->errors()->has('effective_to')) {
            return;
        }

        if ($this->date('effective_to')?->lt($this->date('effective_from'))) {
            $validator->errors()->add(
                'effective_to',
                'The effective end date must be on or after the effective start date.',
            );
        }
    }

    private function validateCriteriaConfiguration(Validator $validator): void
    {
        if (! $this->exists('criteria') || $validator->errors()->has('criteria')) {
            return;
        }

        $criteria = collect($this->input('criteria', []));
        $active = $criteria->filter(
            fn ($criterion): bool => (bool) ($criterion['is_active'] ?? true),
        );

        if ($active->isEmpty()) {
            $validator->errors()->add(
                'criteria',
                'A framework must have at least one active criterion.',
            );

            return;
        }

        $sortOrders = $criteria
            ->map(fn ($criterion, int $index): int => (int) ($criterion['sort_order'] ?? (($index + 1) * 10)));

        if ($sortOrders->duplicates()->isNotEmpty()) {
            $validator->errors()->add(
                'criteria',
                'Each criterion must have a unique display order within the framework.',
            );
        }
    }
}
