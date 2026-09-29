<?php

namespace App\Services\Documents;

use App\Contracts\Imports\ProcessRunner;
use App\Exceptions\ApiProblemException;
use App\Models\DocumentVersion;
use Throwable;

final class DocumentPdfPageCounter
{
    public function __construct(
        private readonly DocumentBlobVerifier $verifier,
        private readonly ProcessRunner $processes,
    ) {}

    public function count(DocumentVersion $version): int
    {
        if ($version->mime_type !== 'application/pdf') {
            throw $this->invalid();
        }
        $file = $this->verifier->verifyVersionToTemporaryFile($version);
        try {
            try {
                $result = $this->processes->run(
                    [(string) config('project_imports.pdf.pdfinfo_binary', 'pdfinfo'), $file->path],
                    (float) config('project_imports.pdf.timeout_seconds', 30),
                    65_536,
                );
            } catch (Throwable) {
                // Tool output, exception arguments and private paths must never enter the API.
                throw new ApiProblemException('PDF page inspection is unavailable.', 'signature_placement_pdf_unavailable', 503);
            }
            if (! $result->successful()
                || preg_match('/^Encrypted:\s*yes\b/im', $result->stdout) === 1
                || preg_match_all('/^Pages:\s*([0-9]+)\s*$/mi', $result->stdout, $matches) !== 1) {
                throw $this->invalid();
            }
            $pages = filter_var($matches[1][0], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 4294967295]]);
            if ($pages === false) {
                throw $this->invalid();
            }

            return $pages;
        } finally {
            $file->close();
        }
    }

    private function invalid(): ApiProblemException
    {
        return new ApiProblemException('A readable, unencrypted PDF is required for placement.', 'signature_placement_pdf_invalid', 422);
    }
}
