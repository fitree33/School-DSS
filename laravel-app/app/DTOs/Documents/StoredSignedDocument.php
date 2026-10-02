<?php

namespace App\DTOs\Documents;

/** Internal receipt for one exclusively created file. Never serialize its locator. */
final readonly class StoredSignedDocument
{
    public function __construct(
        public string $disk,
        public string $storagePath,
        public string $path,
        public int $sizeBytes,
        public string $sha256,
        public array $identity,
    ) {}
}
