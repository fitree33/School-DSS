<?php

namespace App\Jobs;

use App\Services\Imports\DocumentImportProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class ProcessDocumentImport implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    public int $uniqueFor;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $documentImportId,
        public readonly ?int $attemptNo = null,
    ) {
        $this->tries = max(1, (int) config('project_imports.processing.tries', 1));
        $this->timeout = max(1, (int) config('project_imports.processing.timeout_seconds', 120));
        $this->uniqueFor = $this->timeout + 60;

        $queue = config('project_imports.processing.queue');

        if (is_string($queue) && $queue !== '') {
            $this->onQueue($queue);
        }
    }

    public function uniqueId(): string
    {
        return $this->attemptNo === null
            ? (string) $this->documentImportId
            : $this->documentImportId.':'.$this->attemptNo;
    }

    public function handle(DocumentImportProcessor $processor): void
    {
        $processor->process($this->documentImportId, $this->attemptNo);
    }

    public function failed(?Throwable $exception): void
    {
        DocumentImportProcessor::failJob($this->documentImportId, $this->attemptNo);
    }
}
