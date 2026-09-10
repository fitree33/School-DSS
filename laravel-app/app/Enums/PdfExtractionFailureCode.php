<?php

namespace App\Enums;

enum PdfExtractionFailureCode: string
{
    case ExtractorUnavailable = 'PDF_EXTRACTOR_UNAVAILABLE';
    case InvalidPdf = 'PDF_INVALID';
    case EncryptedPdfUnsupported = 'ENCRYPTED_PDF_UNSUPPORTED';
    case PageLimitExceeded = 'PDF_PAGE_LIMIT_EXCEEDED';
    case TextLimitExceeded = 'PDF_TEXT_LIMIT_EXCEEDED';
    case TextExtractionUnavailable = 'TEXT_EXTRACTION_UNAVAILABLE';
    case ParserTimeout = 'PDF_PARSER_TIMEOUT';
    case ParserFailed = 'PDF_PARSER_FAILED';
}
