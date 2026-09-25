<?php

namespace App\DTOs\Signatures;

/** Internal ownership receipt, deliberately without a destructor or serialization contract. */
final class OwnedSignatureFile
{
    public bool $released = false;

    public function __construct(
        public readonly string $storageKey,
        public readonly string $sha256,
        public readonly int $sizeBytes,
        public readonly int $width,
        public readonly int $height,
        public readonly string $publicId,
        public readonly string $root,
        public readonly array $identity,
        public mixed $handle,
        public mixed $lease,
    ) {}
}
