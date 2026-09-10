<?php

namespace App\Http\Requests\Api\V2;

use App\Exceptions\ApiProblemException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

final class ImportExtractionCallbackRequest extends FormRequest
{
    /** @var array<int, string> */
    private const ENVELOPE_FIELDS = [
        'status',
        'payload',
        'normalized_result',
        'raw_result',
        'confidence',
        'warnings',
        'provider_job_id',
        'model_name',
        'failure',
        'failure_code',
        'failure_message',
        'activities',
        'sub_activities',
        'subActivities',
    ];

    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->json()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(['succeeded', 'failed'])],
            'payload' => ['nullable', 'array'],
            'normalized_result' => ['nullable', 'array'],
            'raw_result' => ['nullable', 'array'],
            'confidence' => ['nullable', 'array'],
            'warnings' => ['nullable', 'array', 'max:100'],
            'provider_job_id' => ['nullable', 'string', 'max:191'],
            'model_name' => ['nullable', 'string', 'max:100'],
            'failure' => ['nullable', 'array:code,message'],
            'failure.code' => ['nullable', 'string', 'max:100'],
            'failure.message' => ['nullable', 'string', 'max:65535'],
            'failure_code' => ['nullable', 'string', 'max:100'],
            'failure_message' => ['nullable', 'string', 'max:65535'],
            'activities' => ['nullable', 'array'],
            'sub_activities' => ['nullable', 'array'],
            'subActivities' => ['nullable', 'array'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $status = $this->json('status');
            $hasPayload = $this->json('payload') !== null;
            $hasNormalizedResult = $this->json('normalized_result') !== null;

            if ($status === 'succeeded' && $hasPayload === $hasNormalizedResult) {
                $validator->errors()->add(
                    'payload',
                    'A successful callback must contain exactly one of payload or normalized_result.'
                );
            }

            if ($status === 'failed' && ($hasPayload || $hasNormalizedResult)) {
                $validator->errors()->add('payload', 'A failed callback cannot contain a success payload.');
            }

            $failure = $this->json('failure');
            $failureCode = $this->json('failure_code')
                ?? (is_array($failure) ? ($failure['code'] ?? null) : null);
            $failureMessage = $this->json('failure_message')
                ?? (is_array($failure) ? ($failure['message'] ?? null) : null);

            if ($status === 'failed' && (! is_string($failureCode) || trim($failureCode) === '')) {
                $validator->errors()->add('failure_code', 'A failed callback must include a failure code.');
            }

            if ($status === 'failed' && (! is_string($failureMessage) || trim($failureMessage) === '')) {
                $validator->errors()->add('failure_message', 'A failed callback must include a failure message.');
            }
        });
    }

    protected function passedValidation(): void
    {
        $unknownFields = array_values(array_diff(array_keys($this->json()->all()), self::ENVELOPE_FIELDS));

        if ($unknownFields !== []) {
            throw new ApiProblemException(
                'The callback contains unsupported control fields.',
                'callback_payload_forbidden_field',
                Response::HTTP_UNPROCESSABLE_ENTITY,
                ['_callback' => ['Unsupported fields: '.implode(', ', $unknownFields).'.']],
            );
        }
    }

    /**
     * Return a server-controlled callback shape. Route, event, provider, import,
     * and run identifiers are deliberately never accepted from JSON.
     *
     * @return array<string, mixed>
     */
    public function callbackData(): array
    {
        $validated = $this->validated();
        $failure = $validated['failure'] ?? [];
        $candidate = $validated['payload'] ?? $validated['normalized_result'] ?? null;

        if (is_array($candidate)) {
            foreach (['activities', 'sub_activities', 'subActivities'] as $ignoredField) {
                if (array_key_exists($ignoredField, $validated)) {
                    $candidate[$ignoredField] = $validated[$ignoredField];
                }
            }
        }

        return [
            'status' => $validated['status'],
            'candidate_payload' => $candidate,
            'raw_result' => $validated['raw_result'] ?? null,
            'confidence' => $validated['confidence'] ?? [],
            'warnings' => $validated['warnings'] ?? [],
            'provider_job_id' => $validated['provider_job_id'] ?? null,
            'model_name' => $validated['model_name'] ?? null,
            'failure_code' => $validated['failure_code'] ?? ($failure['code'] ?? null),
            'failure_message' => $validated['failure_message'] ?? ($failure['message'] ?? null),
        ];
    }
}
