<?php

namespace App\Services\Imports;

use App\Contracts\Imports\ProjectExtractionProvider;
use App\Models\AiExtractionRun;
use App\Models\DocumentImport;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

final class N8nProjectExtractionProvider implements ProjectExtractionProvider
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly ?string $url,
        private readonly ?string $token,
        private readonly ?string $callbackUrl,
        private readonly float $timeoutSeconds,
        private readonly float $connectTimeoutSeconds,
    ) {}

    public function dispatch(
        DocumentImport $documentImport,
        AiExtractionRun $run,
        PdfTextExtractionResult $extraction,
    ): ProjectExtractionDispatchResult {
        if (blank($this->url)) {
            throw new ProjectExtractionProviderException(
                ProjectExtractionProviderException::NOT_CONFIGURED,
                'The project import AI provider URL is not configured.',
            );
        }

        try {
            $request = $this->http
                ->acceptJson()
                ->asJson()
                ->withoutRedirecting()
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds);

            if (filled($this->token)) {
                $request = $request->withToken($this->token);
            }

            $payload = [
                'document_import_public_id' => $documentImport->public_id,
                'extraction_run_id' => $run->public_id,
                'attempt_no' => $run->attempt_no,
                'schema_version' => $run->schema_version,
                'source_sha256' => $documentImport->sha256,
                'page_count' => $extraction->pageCount,
                'extracted_text_sha256' => $extraction->textSha256,
                'extracted_text' => $extraction->text,
            ];

            if (filled($this->callbackUrl)) {
                $payload['callback_url'] = $this->callbackUrl;
            }

            $response = $request->post($this->url, $payload)->throw();

            if (! $response->successful()) {
                throw new ProjectExtractionProviderException(
                    ProjectExtractionProviderException::DISPATCH_FAILED,
                    'The AI provider did not accept the extraction request.',
                );
            }
        } catch (Throwable $exception) {
            throw new ProjectExtractionProviderException(
                ProjectExtractionProviderException::DISPATCH_FAILED,
                'The project import could not be dispatched to the AI provider.',
                $exception,
            );
        }

        $providerJobId = $response->json('job_id');

        if ($providerJobId !== null
            && (! is_string($providerJobId) || $providerJobId === '' || mb_strlen($providerJobId) > 191)) {
            throw new ProjectExtractionProviderException(
                ProjectExtractionProviderException::DISPATCH_FAILED,
                'The AI provider returned an invalid extraction job identifier.',
            );
        }

        return new ProjectExtractionDispatchResult($providerJobId);
    }
}
