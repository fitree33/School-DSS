<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;

final class ApiErrorResponse
{
    /**
     * Build the stable error envelope used by version 2 API clients.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    public static function make(
        string $message,
        string $code,
        int $status,
        array $errors = [],
        array $headers = [],
    ): JsonResponse {
        $payload = [
            'message' => $message,
            'code' => $code,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status, $headers);
    }
}
