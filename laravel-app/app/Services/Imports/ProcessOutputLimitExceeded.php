<?php

namespace App\Services\Imports;

use RuntimeException;

final class ProcessOutputLimitExceeded extends RuntimeException
{
    public function __construct(public readonly int $maxBytes)
    {
        parent::__construct("Process output exceeded the configured {$maxBytes}-byte limit.");
    }
}
