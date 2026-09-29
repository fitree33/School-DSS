<?php

namespace App\Http\Requests\Api\V2;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SaveSignaturePlacementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Nested resource and assigned-signer authorization is repeated under service locks.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $integer = function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_int($value) || $value < 1 || $value > 4294967295) {
                $fail('The value must be a positive JSON integer.');
            }
        };
        $coordinate = function (string $attribute, mixed $value, Closure $fail): void {
            if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)
                || $value < 0 || $value > 1
                || (in_array($attribute, ['width', 'height'], true) && round($value * 100000000) < 1)) {
                $fail('The value must be a normalized JSON number; dimensions must be positive.');
            }
        };

        return [
            'signature_asset_id' => ['required', 'string', 'uuid'],
            'assignment_revision' => ['required', $integer],
            'page' => ['required', $integer],
            'x' => ['required', $coordinate],
            'y' => ['required', $coordinate],
            'width' => ['required', $coordinate],
            'height' => ['required', $coordinate],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), array_keys($this->rules())) !== []) {
                $validator->errors()->add('request', 'Unsupported request fields.');
            }
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            foreach ([['x', 'width'], ['y', 'height']] as [$origin, $dimension]) {
                if (round($this->input($origin) * 100000000) + round($this->input($dimension) * 100000000) > 100000000) {
                    $validator->errors()->add($dimension, 'The entire signature rectangle must fit inside the page.');
                }
            }
        }];
    }
}
