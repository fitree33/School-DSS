<?php

namespace App\Services\Documents;

use App\Contracts\Imports\ProcessRunner;
use App\Exceptions\ApiProblemException;
use Throwable;

class PdfSignatureStamper
{
    public function __construct(private readonly ProcessRunner $processes) {}

    /** All paths are server-owned private snapshots; no request input becomes a command/path. */
    public function stamp(string $source, string $image, string $output, array $rectangle): int
    {
        try {
            $result = $this->processes->run([
                (string) config('document_signing.node_binary'), '--max-old-space-size=512', base_path('scripts/pdf/sign-pdf.mjs'),
                $source, $image, $output,
                ...array_map(fn (string $key): string => (string) $rectangle[$key], ['page', 'x', 'y', 'width', 'height']),
            ], (float) config('document_signing.timeout_seconds'), 4096);
            $metadata = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            clearstatcache(true, $output);
            if (! $result->successful() || ! is_int($metadata['page_count'] ?? null) || $metadata['page_count'] < $rectangle['page']
                || ! is_file($output) || is_link($output) || filesize($output) < 8 || filesize($output) > config('document_signing.max_pdf_bytes')
                || (new \finfo(FILEINFO_MIME_TYPE))->file($output) !== 'application/pdf') {
                throw new \RuntimeException('Invalid PDF generation result.');
            }

            return $metadata['page_count'];
        } catch (Throwable) {
            throw new ApiProblemException('The signed PDF could not be generated.', 'document_signing_generation_failed', 503);
        }
    }
}
