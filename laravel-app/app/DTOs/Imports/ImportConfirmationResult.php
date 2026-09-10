<?php

namespace App\DTOs\Imports;

use App\Models\DocumentImport;
use App\Models\Project;

final readonly class ImportConfirmationResult
{
    public function __construct(
        public DocumentImport $documentImport,
        public Project $project,
        public bool $created,
    ) {}
}
