<?php

namespace App\Contracts\Imports;

use App\Services\Imports\PdfTextExtractionResult;

interface PdfTextExtractor
{
    public function extract(string $absolutePath): PdfTextExtractionResult;
}
