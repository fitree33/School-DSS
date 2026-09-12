<?php

namespace App\Services\Documents;

use App\Models\DocumentVersion;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class DocumentVersionDownloadService
{
    public function __construct(private readonly DocumentBlobVerifier $verifier) {}

    public function download(DocumentVersion $version): StreamedResponse
    {
        // Verification reads the source once into a private, bounded-memory temporary file.
        // Only these verified bytes may be sent after the success headers are committed.
        $file = $this->verifier->verifyVersionToTemporaryFile($version);

        try {
            $name = $this->safeFilename($file->originalName);
            $fallback = preg_replace('/[^\x20-\x7E]|[%\\\\\/]/', '_', Str::ascii($name));
            $disposition = HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $name,
                $fallback !== '' ? $fallback : 'document',
            );

            return new StreamedResponse(function () use ($file): void {
                try {
                    $stream = $file->stream();

                    while (! feof($stream)) {
                        $chunk = fread($stream, 65536);

                        if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                            throw new \RuntimeException('The verified download could not be read.');
                        }

                        echo $chunk;
                    }
                } finally {
                    $file->close();
                }
            }, Response::HTTP_OK, [
                'Content-Type' => $file->mimeType,
                'Content-Length' => (string) $file->sizeBytes,
                'Content-Disposition' => $disposition,
                'Content-Security-Policy' => "sandbox; default-src 'none'",
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
            ]);
        } catch (Throwable $exception) {
            $file->close();

            throw $exception;
        }
    }

    private function safeFilename(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\\\\\/]/u', '_', $name) ?? '';
        $name = trim($name);

        return $name !== '' && $name !== '.' && $name !== '..' ? $name : 'document';
    }
}
