<?php

namespace App\DTOs\Imports;

use App\Models\ImportPreviewRevision;

final readonly class PreviewRevisionResult
{
    public function __construct(
        public ImportPreviewRevision $revision,
        public bool $created,
    ) {}
}
