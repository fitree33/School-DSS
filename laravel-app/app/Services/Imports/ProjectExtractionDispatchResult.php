<?php

namespace App\Services\Imports;

final readonly class ProjectExtractionDispatchResult
{
    public function __construct(public ?string $providerJobId = null) {}
}
