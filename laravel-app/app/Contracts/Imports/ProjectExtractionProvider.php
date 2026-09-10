<?php

namespace App\Contracts\Imports;

use App\Models\AiExtractionRun;
use App\Models\DocumentImport;
use App\Services\Imports\PdfTextExtractionResult;
use App\Services\Imports\ProjectExtractionDispatchResult;

interface ProjectExtractionProvider
{
    public function dispatch(
        DocumentImport $documentImport,
        AiExtractionRun $run,
        PdfTextExtractionResult $extraction,
    ): ProjectExtractionDispatchResult;
}
