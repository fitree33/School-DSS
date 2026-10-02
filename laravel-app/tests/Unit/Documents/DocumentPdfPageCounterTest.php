<?php

namespace Tests\Unit\Documents;

use App\Contracts\Imports\ProcessRunner;
use App\Exceptions\ApiProblemException;
use App\Models\DocumentVersion;
use App\Services\Documents\DocumentBlobVerifier;
use App\Services\Documents\DocumentPdfPageCounter;
use App\Services\Imports\ProcessOutputLimitExceeded;
use App\Services\Imports\ProcessResult;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use Tests\Feature\Concerns\UsesPrivateSignatureStorage;
use Throwable;

class DocumentPdfPageCounterTest extends TestCase
{
    use UsesPrivateSignatureStorage;
    private const PDF = "%PDF-1.4\nVerified page count source\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('page-counter-temporary');
        $this->protectSignatureFixtureDirectory(rtrim(Storage::disk('page-counter-temporary')->path(''), '/\\'));
        config()->set('document_versions.allowed_disks', ['local']);
        config()->set('document_versions.temporary_directory', Storage::disk('page-counter-temporary')->path(''));
        config()->set('project_imports.pdf.pdfinfo_binary', 'fixture-pdfinfo');
        config()->set('project_imports.pdf.timeout_seconds', 5.0);
    }

    public function test_counts_only_verified_snapshot_with_bounded_process_and_releases_temporary_file(): void
    {
        $version = $this->version();
        $snapshotPath = null;
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())->method('run')->willReturnCallback(
            function (array $command, float $timeout, int $maxBytes) use (&$snapshotPath): ProcessResult {
                $this->assertCount(2, $command);
                $this->assertSame('fixture-pdfinfo', $command[0]);
                $this->assertSame(5.0, $timeout);
                $this->assertSame(65536, $maxBytes);
                $snapshotPath = $command[1];
                $this->assertStringStartsWith(str_replace('\\', '/', Storage::disk('page-counter-temporary')->path('')), str_replace('\\', '/', $snapshotPath));
                Storage::disk('local')->put('source.pdf', 'source changed after verification');
                $this->assertSame(self::PDF, file_get_contents($snapshotPath));

                return new ProcessResult(0, "Pages: 12\nEncrypted: no\n", '');
            },
        );

        $this->assertSame(12, $this->counter($runner)->count($version));
        $this->assertFileDoesNotExist($snapshotPath);
        $this->assertSame([], Storage::disk('page-counter-temporary')->allFiles());
        $this->assertSame('source changed after verification', Storage::disk('local')->get('source.pdf'));
    }

    #[DataProvider('invalidMetadata')]
    public function test_malformed_or_encrypted_metadata_fails_closed_without_leaking_tool_output(string $metadata, int $exitCode = 0): void
    {
        $version = $this->version();
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())->method('run')->willReturn(new ProcessResult($exitCode, $metadata, 'private-path-and-tool-error'));

        $this->assertFailure($this->counter($runner), $version, 422, 'signature_placement_pdf_invalid');
        $this->assertSame([], Storage::disk('page-counter-temporary')->allFiles());
    }

    public static function invalidMetadata(): array
    {
        return [
            'missing count' => ["Encrypted: no\n"],
            'zero pages' => ["Pages: 0\nEncrypted: no\n"],
            'negative pages' => ["Pages: -1\nEncrypted: no\n"],
            'fractional pages' => ["Pages: 1.5\nEncrypted: no\n"],
            'overflow' => ["Pages: 999999999999999999999999999999999999\n"],
            'duplicate metadata' => ["Pages: 1\nPages: 2\n"],
            'encrypted PDF' => ["Pages: 1\nEncrypted: yes (print:yes)\n"],
            'tool failed' => ["Pages: 1\nEncrypted: no\n", 1],
        ];
    }

    #[DataProvider('processFailures')]
    public function test_unavailable_timeout_and_output_limit_failures_are_sanitized_and_cleanup(Throwable $failure): void
    {
        $version = $this->version();
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())->method('run')->willThrowException($failure);

        $this->assertFailure($this->counter($runner), $version, 503, 'signature_placement_pdf_unavailable');
        $this->assertSame([], Storage::disk('page-counter-temporary')->allFiles());
    }

    public static function processFailures(): array
    {
        return [
            'unavailable' => [new RuntimeException('private-path-and-tool-error')],
            'timeout' => [new ProcessTimedOutException(new Process(['private-path-and-tool-error']), ProcessTimedOutException::TYPE_GENERAL)],
            'output limit' => [new ProcessOutputLimitExceeded(65536)],
        ];
    }

    public function test_non_pdf_is_rejected_without_running_a_tool(): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->never())->method('run');
        $version = $this->version();
        $version->mime_type = 'text/plain';

        $this->assertFailure($this->counter($runner), $version, 422, 'signature_placement_pdf_invalid');
        $this->assertSame([], Storage::disk('page-counter-temporary')->allFiles());
    }

    public function test_modified_source_is_rejected_before_pdfinfo_and_preserves_integrity_error(): void
    {
        $version = $this->version();
        Storage::disk('local')->put('source.pdf', str_replace('source', 'change', self::PDF));
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->never())->method('run');

        $this->assertFailure($this->counter($runner), $version, 409, 'document_version_integrity_failed');
        $this->assertSame([], Storage::disk('page-counter-temporary')->allFiles());
    }

    private function counter(ProcessRunner $runner): DocumentPdfPageCounter
    {
        return new DocumentPdfPageCounter(app(DocumentBlobVerifier::class), $runner);
    }

    private function version(): DocumentVersion
    {
        Storage::disk('local')->put('source.pdf', self::PDF);

        return new DocumentVersion([
            'storage_disk' => 'local', 'storage_path' => 'source.pdf',
            'original_name' => 'source.pdf', 'mime_type' => 'application/pdf',
            'size_bytes' => strlen(self::PDF), 'sha256' => hash('sha256', self::PDF),
        ]);
    }

    private function assertFailure(DocumentPdfPageCounter $counter, DocumentVersion $version, int $status, string $code): void
    {
        try {
            $counter->count($version);
            $this->fail('Untrusted page count was accepted.');
        } catch (ApiProblemException $exception) {
            $this->assertSame($status, $exception->status);
            $this->assertSame($code, $exception->errorCode);
            $this->assertStringNotContainsString('private-path-and-tool-error', $exception->getMessage());
            $this->assertStringNotContainsString(Storage::disk('local')->path(''), $exception->getMessage());
        }
    }
}
