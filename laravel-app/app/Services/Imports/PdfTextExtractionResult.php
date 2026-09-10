<?php

namespace App\Services\Imports;

final readonly class PdfTextExtractionResult
{
    public string $textSha256;

    public int $textBytes;

    public function __construct(
        public string $text,
        public int $pageCount,
    ) {
        $this->textSha256 = hash('sha256', $text);
        $this->textBytes = strlen($text);
    }
}
