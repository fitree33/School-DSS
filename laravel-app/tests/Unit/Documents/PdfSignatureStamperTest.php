<?php

namespace Tests\Unit\Documents;

use App\Contracts\Imports\ProcessRunner;
use App\Exceptions\ApiProblemException;
use App\Services\Documents\PdfSignatureStamper;
use App\Services\Imports\ProcessOutputLimitExceeded;
use App\Services\Imports\ProcessResult;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PdfSignatureStamperTest extends TestCase
{
    private const RECTANGLE = ['page' => 2, 'x' => 0.2, 'y' => 0.25, 'width' => 0.25, 'height' => 0.125];

    private const PDF = "%PDF-1.7\nGenerated fixture\n%%EOF\n";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('signing-worker');
        config()->set('document_signing.node_binary', 'fixture-node');
        config()->set('document_signing.timeout_seconds', 7);
        config()->set('document_signing.max_pdf_bytes', 1024);
    }

    public function test_bounded_array_command_receives_only_owned_paths_and_canonical_coordinates(): void
    {
        $output = Storage::disk('signing-worker')->path('output.pdf');
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())->method('run')->willReturnCallback(function (array $command, float $timeout, int $maxBytes) use ($output): ProcessResult {
            $this->assertSame([
                'fixture-node', '--max-old-space-size=512', base_path('scripts/pdf/sign-pdf.mjs'),
                'private-source.pdf', 'private-signature.png', $output, '2', '0.2', '0.25', '0.25', '0.125',
            ], $command);
            $this->assertSame(7.0, $timeout);
            $this->assertSame(4096, $maxBytes);
            file_put_contents($output, self::PDF);

            return new ProcessResult(0, '{"page_count":2}', '');
        });

        $this->assertSame(2, (new PdfSignatureStamper($runner))->stamp('private-source.pdf', 'private-signature.png', $output, self::RECTANGLE));
    }

    #[DataProvider('invalidOutputs')]
    public function test_invalid_or_missing_generated_output_is_rejected(string $stdout, ?string $bytes, int $exitCode = 0): void
    {
        $output = Storage::disk('signing-worker')->path('output.pdf');
        if ($bytes !== null) file_put_contents($output, $bytes);
        $runner = $this->createStub(ProcessRunner::class);
        $runner->method('run')->willReturn(new ProcessResult($exitCode, $stdout, 'sensitive-parser-detail'));
        $this->assertSanitizedFailure(new PdfSignatureStamper($runner), $output);
    }

    public static function invalidOutputs(): array
    {
        return [
            'invalid metadata' => ['not-json', self::PDF],
            'missing count' => ['{}', self::PDF],
            'string count' => ['{"page_count":"2"}', self::PDF],
            'zero count' => ['{"page_count":0}', self::PDF],
            'missing selected page' => ['{"page_count":1}', self::PDF],
            'process failed' => ['{"page_count":2}', self::PDF, 1],
            'missing bytes' => ['{"page_count":2}', null],
            'empty bytes' => ['{"page_count":2}', ''],
            'not PDF bytes' => ['{"page_count":2}', 'This is not a PDF file.'],
            'oversized PDF' => ['{"page_count":2}', self::PDF.str_repeat('x', 1024)],
        ];
    }

    public function test_process_unavailability_and_output_overflow_are_sanitized(): void
    {
        foreach ([new RuntimeException('sensitive-parser-detail'), new ProcessOutputLimitExceeded(4096)] as $exception) {
            $runner = $this->createStub(ProcessRunner::class);
            $runner->method('run')->willThrowException($exception);
            $this->assertSanitizedFailure(new PdfSignatureStamper($runner), 'private-output.pdf');
        }
    }

    private function assertSanitizedFailure(PdfSignatureStamper $stamper, string $output): void
    {
        try {
            $stamper->stamp('private-source.pdf', 'private-signature.png', $output, self::RECTANGLE);
            $this->fail('Invalid generated PDF was accepted.');
        } catch (ApiProblemException $exception) {
            $this->assertSame(503, $exception->status);
            $this->assertSame('document_signing_generation_failed', $exception->errorCode);
            $this->assertSame('The signed PDF could not be generated.', $exception->getMessage());
        }
    }
}
