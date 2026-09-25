<?php

namespace App\DTOs\Signatures;

/** Internal request namespace. Never serialize or include in exception arguments. */
final class OwnedSignatureWorkspace
{
    public function __construct(private readonly string $path, public mixed $lease)
    {
    }

    public function directory(): string
    {
        return $this->path;
    }
}
