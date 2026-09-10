<?php

namespace App\Services\Imports;

use RuntimeException;
use Throwable;

final class ProjectExtractionProviderException extends RuntimeException
{
    public const NOT_CONFIGURED = 'AI_PROVIDER_NOT_CONFIGURED';

    public const DISPATCH_FAILED = 'AI_PROVIDER_DISPATCH_FAILED';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
