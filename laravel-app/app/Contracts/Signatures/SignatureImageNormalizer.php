<?php

namespace App\Contracts\Signatures;

interface SignatureImageNormalizer
{
    public function assertAvailable(): void;

    /** Return only newly encoded PNG bytes; callers own the private input and work directory. */
    public function normalize(#[\SensitiveParameter] string $inputPath, #[\SensitiveParameter] string $workingDirectory): string;
}
