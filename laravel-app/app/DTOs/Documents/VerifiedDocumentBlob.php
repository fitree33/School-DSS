<?php

namespace App\DTOs\Documents;

use App\Enums\DocumentVersionIntegrityBasis;
use Carbon\CarbonImmutable;

final readonly class VerifiedDocumentBlob
{
    public function __construct(
        public string $storageDisk,
        public string $storagePath,
        public string $originalName,
        public string $mimeType,
        public int $sizeBytes,
        public string $sha256,
        public DocumentVersionIntegrityBasis $integrityBasis,
        public CarbonImmutable $verifiedAt,
    ) {}
}
