<?php

namespace App\Exceptions;

use RuntimeException;

final class ApiProblemException extends RuntimeException
{
    /**
     * @param  array<string, array<int, string>>  $errors
     * @param  array<string, string>  $headers
     */
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly array $errors = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    /** @param array<string, array<int, string>> $errors */
    public static function validation(array $errors): self
    {
        return new self(
            'The given data was invalid.',
            'validation_failed',
            422,
            $errors,
        );
    }
}
