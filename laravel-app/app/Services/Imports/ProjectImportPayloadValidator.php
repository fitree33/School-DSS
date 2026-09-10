<?php

namespace App\Services\Imports;

use App\Models\FiscalYear;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProjectImportPayloadValidator
{
    /** @var array<int, string> */
    public const ALLOWED_FIELDS = [
        'name',
        'objective',
        'key_points',
        'budget',
        'responsible_person',
        'monitor_person',
        'evaluation_method',
        'evaluation_tools',
        'start_date',
        'end_date',
        'department_id',
        'project_category_id',
        'academic_year_id',
        'fiscal_year_id',
        'school_plan_id',
        'indicators',
    ];

    /**
     * Return canonical validation errors without rejecting an append-only
     * preview revision. Confirm uses the same validator and rejects errors.
     *
     * @return array<string, array<int, string>>
     */
    public function errors(array $payload, User $actor): array
    {
        $validator = $this->validator($payload, $actor);
        $validator->fails();

        return $validator->errors()->toArray();
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validateForConfirmation(array $payload, User $actor): array
    {
        $validator = $this->validator($payload, $actor);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $validated = Arr::only($validator->validated(), self::ALLOWED_FIELDS);
        $validated['indicators'] = collect($validated['indicators'] ?? [])
            ->map(fn (array $indicator): array => Arr::only($indicator, ['name', 'target_value', 'unit']))
            ->values()
            ->all();

        return $validated;
    }

    private function validator(array $payload, User $actor): ValidatorContract
    {
        $validator = Validator::make($payload, [
            'name' => ['required', 'string', 'max:255'],
            'objective' => ['required', 'string', 'max:16000'],
            'key_points' => ['nullable', 'string'],
            'budget' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'responsible_person' => ['nullable', 'string', 'max:255'],
            'monitor_person' => ['nullable', 'string', 'max:255'],
            'evaluation_method' => ['nullable', 'string', 'max:16000'],
            'evaluation_tools' => ['nullable', 'string', 'max:16000'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'department_id' => ['required', 'integer', Rule::exists('departments', 'id')],
            'project_category_id' => ['required', 'integer', Rule::exists('project_categories', 'id')],
            'academic_year_id' => ['required', 'integer', Rule::exists('academic_years', 'id')],
            'fiscal_year_id' => ['required', 'integer', Rule::exists('fiscal_years', 'id')],
            'school_plan_id' => [
                'nullable',
                'integer',
                Rule::exists('school_plans', 'id')->where(
                    fn ($query) => $query->where('fiscal_year_id', $payload['fiscal_year_id'] ?? null)
                ),
            ],
            'indicators' => ['present', 'array', 'max:20'],
            'indicators.*' => ['array:name,target_value,unit'],
            'indicators.*.name' => ['required', 'string', 'max:255'],
            'indicators.*.target_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'indicators.*.unit' => ['nullable', 'string', 'max:50'],
        ]);

        $validator->after(function (ValidatorContract $validator) use ($payload, $actor): void {
            $unknownFields = array_values(array_diff(array_keys($payload), self::ALLOWED_FIELDS));

            if ($unknownFields !== []) {
                $validator->errors()->add(
                    '_payload',
                    'The preview contains unsupported fields: '.implode(', ', $unknownFields).'.'
                );
            }

            if (! $validator->errors()->has('start_date') && ! $validator->errors()->has('end_date')) {
                $start = isset($payload['start_date']) ? strtotime((string) $payload['start_date']) : false;
                $end = isset($payload['end_date']) ? strtotime((string) $payload['end_date']) : false;

                if ($start !== false && $end !== false && $end < $start) {
                    $validator->errors()->add('end_date', 'The end date must be on or after the start date.');
                }
            }

            if (! $validator->errors()->has('fiscal_year_id')) {
                $fiscalYear = FiscalYear::query()->find((int) ($payload['fiscal_year_id'] ?? 0));

                if ($fiscalYear?->is_locked) {
                    $validator->errors()->add(
                        'fiscal_year_id',
                        'The selected fiscal year is locked and read-only.'
                    );
                }
            }

            if (! $actor->hasPermission('projects.edit_all')
                && (! $actor->department_id
                    || (int) ($payload['department_id'] ?? 0) !== (int) $actor->department_id)) {
                $validator->errors()->add(
                    'department_id',
                    'You may only create projects for your own department.'
                );
            }
        });

        return $validator;
    }
}
