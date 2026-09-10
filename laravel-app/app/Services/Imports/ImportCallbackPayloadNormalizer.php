<?php

namespace App\Services\Imports;

use App\Exceptions\ApiProblemException;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class ImportCallbackPayloadNormalizer
{
    /** @var array<int, string> */
    private const AI_FIELDS = [
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
        'indicators',
    ];

    /** @var array<int, string> */
    private const MASTER_DATA_FIELDS = [
        'department_id',
        'project_category_id',
        'academic_year_id',
        'fiscal_year_id',
        'school_plan_id',
    ];

    /** @var array<int, string> */
    private const IGNORED_SCOPE_FIELDS = [
        'activities',
        'sub_activities',
        'subActivities',
    ];

    /**
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $confidence
     * @param  array<int, mixed>  $warnings
     * @return array{payload: array<string, mixed>, confidence: array<string, float|null>, warnings: array<int, array<string, mixed>>}
     */
    public function normalizeSuccess(array $candidate, array $confidence, array $warnings): array
    {
        $keys = array_keys($candidate);
        $forbiddenFields = array_values(array_filter(
            $keys,
            fn (string $field): bool => $field === 'id'
                || str_ends_with($field, '_id')
                || in_array($field, [
                    'project_code',
                    'status',
                    'processing_stage',
                    'actual_spent',
                    'user_id',
                    'uploaded_by',
                    'confirmed_by',
                    'current_preview_revision',
                    'confirmed_preview_revision',
                ], true),
        ));

        if ($forbiddenFields !== []) {
            throw new ApiProblemException(
                'AI callback data cannot provide canonical identifiers or control fields.',
                'callback_payload_forbidden_field',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['payload' => ['Forbidden fields: '.implode(', ', $forbiddenFields).'.']],
            );
        }

        $unknownFields = array_values(array_diff(
            $keys,
            self::AI_FIELDS,
            self::MASTER_DATA_FIELDS,
            self::IGNORED_SCOPE_FIELDS,
        ));

        if ($unknownFields !== []) {
            throw new ApiProblemException(
                'The AI callback payload contains unsupported fields.',
                'invalid_callback_payload',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['payload' => ['Unsupported fields: '.implode(', ', $unknownFields).'.']],
            );
        }

        $validator = Validator::make($candidate, [
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'objective' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'key_points' => ['sometimes', 'nullable', 'string'],
            'budget' => ['sometimes', 'nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'responsible_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'monitor_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'evaluation_method' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'evaluation_tools' => ['sometimes', 'nullable', 'string', 'max:16000'],
            'start_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'end_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'indicators' => ['sometimes', 'array', 'max:20'],
            'indicators.*' => ['array:name,target_value,unit'],
            'indicators.*.name' => ['required', 'string', 'max:255'],
            'indicators.*.target_value' => ['nullable', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'indicators.*.unit' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            throw new ApiProblemException(
                'The AI callback payload has an invalid structure.',
                'invalid_callback_payload',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $validator->errors()->toArray(),
            );
        }

        $normalizedWarnings = $this->normalizeWarnings($warnings);

        foreach (self::IGNORED_SCOPE_FIELDS as $ignoredField) {
            if (! array_key_exists($ignoredField, $candidate)) {
                continue;
            }

            $normalizedWarnings[] = [
                'code' => 'unsupported_scope_ignored',
                'field' => $ignoredField,
                'message' => 'Activity and sub-activity data is outside the import scope and was ignored.',
            ];
        }

        $validated = $validator->validated();
        $payload = [
            'name' => (string) ($validated['name'] ?? ''),
            'fiscal_year_id' => null,
            'department_id' => null,
            'school_plan_id' => null,
            'project_category_id' => null,
            'academic_year_id' => null,
            'responsible_person' => $validated['responsible_person'] ?? null,
            'monitor_person' => $validated['monitor_person'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'budget' => array_key_exists('budget', $validated) && $validated['budget'] !== null
                ? (string) $validated['budget']
                : '',
            'objective' => (string) ($validated['objective'] ?? ''),
            'key_points' => $validated['key_points'] ?? null,
            'evaluation_method' => $validated['evaluation_method'] ?? null,
            'evaluation_tools' => $validated['evaluation_tools'] ?? null,
            'indicators' => collect($validated['indicators'] ?? [])->map(
                fn (array $indicator): array => [
                    'name' => $indicator['name'],
                    'target_value' => isset($indicator['target_value'])
                        ? (string) $indicator['target_value']
                        : null,
                    'unit' => $indicator['unit'] ?? null,
                ]
            )->values()->all(),
        ];

        return [
            'payload' => $payload,
            'confidence' => $this->normalizeConfidence($confidence),
            'warnings' => $normalizedWarnings,
        ];
    }

    /**
     * @param  array<int, mixed>  $warnings
     * @return array<int, array<string, mixed>>
     */
    public function normalizeWarnings(array $warnings): array
    {
        $normalized = [];
        $errors = [];

        foreach ($warnings as $index => $warning) {
            if (is_string($warning) && trim($warning) !== '') {
                $normalized[] = ['message' => $warning];

                continue;
            }

            if (! is_array($warning)
                || array_diff(array_keys($warning), ['code', 'field', 'message']) !== []
                || ! isset($warning['message'])
                || ! is_string($warning['message'])
                || trim($warning['message']) === ''
                || (isset($warning['code']) && (! is_string($warning['code']) || strlen($warning['code']) > 100))
                || (isset($warning['field']) && $warning['field'] !== null
                    && (! is_string($warning['field']) || strlen($warning['field']) > 255))) {
                $errors["warnings.{$index}"] = ['Each warning must be a message or a code/field/message object.'];

                continue;
            }

            $normalized[] = array_filter([
                'code' => $warning['code'] ?? null,
                'field' => $warning['field'] ?? null,
                'message' => $warning['message'],
            ], fn ($value): bool => $value !== null);
        }

        if ($errors !== []) {
            throw new ApiProblemException(
                'The callback warnings have an invalid structure.',
                'invalid_callback_payload',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $errors,
            );
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $confidence
     * @return array<string, float|null>
     */
    private function normalizeConfidence(array $confidence): array
    {
        $normalized = [];
        $errors = [];

        foreach ($confidence as $field => $value) {
            $rootField = explode('.', (string) $field, 2)[0];

            if (! in_array($rootField, self::AI_FIELDS, true)) {
                $errors["confidence.{$field}"] = ['Confidence may only reference AI preview fields.'];

                continue;
            }

            if ($value === null) {
                $normalized[(string) $field] = null;

                continue;
            }

            if (! is_numeric($value) || (float) $value < 0 || (float) $value > 1) {
                $errors["confidence.{$field}"] = ['Confidence values must be between 0 and 1.'];

                continue;
            }

            $normalized[(string) $field] = (float) $value;
        }

        if ($errors !== []) {
            throw new ApiProblemException(
                'The callback confidence map has an invalid structure.',
                'invalid_callback_payload',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $errors,
            );
        }

        return $normalized;
    }
}
