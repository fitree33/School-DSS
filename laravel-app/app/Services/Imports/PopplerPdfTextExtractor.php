<?php

namespace App\Services\Imports;

use App\Contracts\Imports\PdfTextExtractor;
use App\Contracts\Imports\ProcessRunner;
use InvalidArgumentException;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Throwable;

final class PopplerPdfTextExtractor implements PdfTextExtractor
{
    private const MAX_PDFINFO_BYTES = 65_536;

    public function __construct(
        private readonly ProcessRunner $processes,
        private readonly string $pdfInfoBinary,
        private readonly string $pdfToTextBinary,
        private readonly float $timeoutSeconds,
        private readonly int $maxPages,
        private readonly int $maxTextBytes,
        private readonly bool $rejectEncrypted = true,
    ) {
        if ($pdfInfoBinary === '' || $pdfToTextBinary === '') {
            throw new InvalidArgumentException('Both Poppler binary paths are required.');
        }

        if ($timeoutSeconds <= 0 || $maxPages < 1 || $maxTextBytes < 1) {
            throw new InvalidArgumentException('PDF extraction limits must be positive.');
        }
    }

    public function extract(string $absolutePath): PdfTextExtractionResult
    {
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::SOURCE_FILE_UNAVAILABLE,
                'The imported PDF source file is unavailable.',
            );
        }

        $metadata = $this->run(
            [$this->pdfInfoBinary, $absolutePath],
            self::MAX_PDFINFO_BYTES,
            PdfTextExtractionException::PDF_INVALID,
            PdfTextExtractionException::PDF_INVALID,
            'Unable to inspect the imported PDF.',
        );

        if (! preg_match('/^Pages:\s*(\d+)\s*$/mi', $metadata, $pageMatch)) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::PDF_INVALID,
                'The PDF page count could not be determined.',
            );
        }

        $pageCount = (int) $pageMatch[1];

        if ($pageCount < 1) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::PDF_INVALID,
                'The PDF does not contain any pages.',
            );
        }

        if ($pageCount > $this->maxPages) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::PDF_PAGE_LIMIT_EXCEEDED,
                "The PDF contains {$pageCount} pages; the configured limit is {$this->maxPages}.",
            );
        }

        if ($this->rejectEncrypted
            && preg_match('/^Encrypted:\s*yes\b/im', $metadata) === 1) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::PDF_ENCRYPTED,
                'Encrypted PDFs are not accepted for project import.',
            );
        }

        $text = $this->run(
            [
                $this->pdfToTextBinary,
                '-f',
                '1',
                '-l',
                (string) $pageCount,
                '-enc',
                'UTF-8',
                '-nopgbrk',
                $absolutePath,
                '-',
            ],
            $this->maxTextBytes,
            PdfTextExtractionException::PDF_EXTRACTION_FAILED,
            PdfTextExtractionException::PDF_TEXT_LIMIT_EXCEEDED,
            'Unable to extract text from the imported PDF.',
        );

        $text = trim($text, " \t\n\r\0\x0B\x0C");

        if ($text === '') {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::TEXT_EXTRACTION_UNAVAILABLE,
                'The PDF has no extractable text layer; OCR is not enabled.',
            );
        }

        if (preg_match('//u', $text) !== 1) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::PDF_EXTRACTION_FAILED,
                'The extracted PDF text is not valid UTF-8.',
            );
        }

        return new PdfTextExtractionResult($text, $pageCount);
    }

    private function run(
        array $command,
        int $maxOutputBytes,
        string $failureCode,
        string $outputLimitCode,
        string $failureMessage,
    ): string {
        try {
            $result = $this->processes->run($command, $this->timeoutSeconds, $maxOutputBytes);
        } catch (ProcessOutputLimitExceeded $exception) {
            throw new PdfTextExtractionException(
                $outputLimitCode,
                "Extracted PDF output exceeds the configured {$exception->maxBytes}-byte limit.",
                $exception,
            );
        } catch (ProcessTimedOutException $exception) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::PDF_EXTRACTION_TIMEOUT,
                'PDF text extraction exceeded the configured timeout.',
                $exception,
            );
        } catch (ProcessStartFailedException $exception) {
            throw new PdfTextExtractionException(
                PdfTextExtractionException::PDF_TOOL_UNAVAILABLE,
                'The configured PDF extraction tool could not be started.',
                $exception,
            );
        } catch (Throwable $exception) {
            throw new PdfTextExtractionException(
                $failureCode,
                $failureMessage,
                $exception,
            );
        }

        if (! $result->successful()) {
            throw new PdfTextExtractionException(
                $failureCode,
                $failureMessage.' '.$this->safeError($result->stderr),
            );
        }

        return $result->stdout;
    }

    private function safeError(string $stderr): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $stderr) ?? '');

        return $message === '' ? '' : 'Tool output: '.substr($message, 0, 500);
    }
}
