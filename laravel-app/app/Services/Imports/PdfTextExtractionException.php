<?php

namespace App\Services\Imports;

use RuntimeException;
use Throwable;

final class PdfTextExtractionException extends RuntimeException
{
    public const SOURCE_FILE_UNAVAILABLE = 'SOURCE_FILE_UNAVAILABLE';

    public const SOURCE_FILE_LIMIT_EXCEEDED = 'SOURCE_FILE_LIMIT_EXCEEDED';

    public const SOURCE_INTEGRITY_MISMATCH = 'SOURCE_INTEGRITY_MISMATCH';

    public const PDF_TOOL_UNAVAILABLE = 'PDF_TOOL_UNAVAILABLE';

    public const PDF_INVALID = 'PDF_INVALID';

    public const PDF_ENCRYPTED = 'PDF_ENCRYPTED';

    public const PDF_PAGE_LIMIT_EXCEEDED = 'PDF_PAGE_LIMIT_EXCEEDED';

    public const PDF_TEXT_LIMIT_EXCEEDED = 'PDF_TEXT_LIMIT_EXCEEDED';

    public const PDF_EXTRACTION_TIMEOUT = 'PDF_EXTRACTION_TIMEOUT';

    public const PDF_EXTRACTION_FAILED = 'PDF_EXTRACTION_FAILED';

    public const TEXT_EXTRACTION_UNAVAILABLE = 'TEXT_EXTRACTION_UNAVAILABLE';

    public function __construct(
        public readonly string $errorCode,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
