<?php

namespace Tests\Unit\Imports;

use App\Contracts\Imports\ProcessRunner;
use App\Services\Imports\PdfTextExtractionException;
use App\Services\Imports\PopplerPdfTextExtractor;
use App\Services\Imports\ProcessOutputLimitExceeded;
use App\Services\Imports\ProcessResult;
use App\Services\Imports\SymfonyProcessRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class PdfImportInfrastructureTest extends TestCase
{
    private string $sourcePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sourcePath = tempnam(sys_get_temp_dir(), 'phase5-pdf-');
        file_put_contents($this->sourcePath, "%PDF-1.4\nTest source\n%%EOF\n");
    }

    protected function tearDown(): void
    {
        unlink($this->sourcePath);
        parent::tearDown();
    }

    #[DataProvider('rejectedMetadata')]
    public function test_pdf_metadata_rejects_before_text_extraction(string $metadata, string $code): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $runner->expects($this->once())->method('run')->willReturn(new ProcessResult(0, $metadata, ''));

        $this->assertExtractionFailure($this->extractor($runner), $code);
    }

    public static function rejectedMetadata(): array
    {
        return [
            'unknown page count' => ["Encrypted: no\n", PdfTextExtractionException::PDF_INVALID],
            'zero pages' => ["Pages: 0\nEncrypted: no\n", PdfTextExtractionException::PDF_INVALID],
            'page limit' => ["Pages: 11\nEncrypted: no\n", PdfTextExtractionException::PDF_PAGE_LIMIT_EXCEEDED],
            'encrypted PDF' => ["Pages: 1\nEncrypted: yes (print:yes)\n", PdfTextExtractionException::PDF_ENCRYPTED],
        ];
    }

    #[DataProvider('extractionFailures')]
    public function test_pdf_text_failures_have_stable_codes(Throwable|ProcessResult $result, string $code): void
    {
        $runner = $this->createMock(ProcessRunner::class);
        $calls = 0;
        $runner->expects($this->exactly(2))->method('run')->willReturnCallback(
            function (array $command, float $timeout, int $maxBytes) use (&$calls, $result): ProcessResult {
                $calls++;
                $this->assertSame(5.0, $timeout);

                if ($calls === 1) {
                    $this->assertSame(65536, $maxBytes);

                    return new ProcessResult(0, "Pages: 1\nEncrypted: no\n", '');
                }

                $this->assertSame(64, $maxBytes);
                $this->assertSame(['pdftotext-test', '-f', '1', '-l', '1', '-enc', 'UTF-8', '-nopgbrk', $this->sourcePath, '-'], $command);

                if ($result instanceof Throwable) {
                    throw $result;
                }

                return $result;
            },
        );

        $this->assertExtractionFailure($this->extractor($runner), $code);
    }

    public static function extractionFailures(): array
    {
        return [
            'output byte limit' => [new ProcessOutputLimitExceeded(64), PdfTextExtractionException::PDF_TEXT_LIMIT_EXCEEDED],
            'process timeout' => [new ProcessTimedOutException(new Process(['test']), ProcessTimedOutException::TYPE_GENERAL), PdfTextExtractionException::PDF_EXTRACTION_TIMEOUT],
            'tool unavailable' => [new ProcessStartFailedException(new Process(['test']), 'unavailable'), PdfTextExtractionException::PDF_TOOL_UNAVAILABLE],
            'tool failure' => [new ProcessResult(1, '', 'Invalid PDF.'), PdfTextExtractionException::PDF_EXTRACTION_FAILED],
            'invalid UTF-8' => [new ProcessResult(0, "\xC3\x28", ''), PdfTextExtractionException::PDF_EXTRACTION_FAILED],
            'empty text layer' => [new ProcessResult(0, " \n\f\t", ''), PdfTextExtractionException::TEXT_EXTRACTION_UNAVAILABLE],
        ];
    }

    public function test_process_runner_captures_stdout_and_bounds_stderr_without_a_shell_command(): void
    {
        $result = (new SymfonyProcessRunner)->run([
            PHP_BINARY,
            '-r',
            'fwrite(STDOUT, "PDF text"); fwrite(STDERR, str_repeat("e", 32768));',
        ], 5, 64);

        $this->assertTrue($result->successful());
        $this->assertSame('PDF text', $result->stdout);
        $this->assertSame(16384, strlen($result->stderr));
    }

    public function test_process_runner_stops_when_stdout_exceeds_limit(): void
    {
        $this->expectException(ProcessOutputLimitExceeded::class);

        (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', 'echo str_repeat("x", 32768);'], 5, 64);
    }

    public function test_process_runner_stops_on_timeout(): void
    {
        $this->expectException(ProcessTimedOutException::class);

        (new SymfonyProcessRunner)->run([PHP_BINARY, '-r', 'usleep(2000000);'], 0.1, 64);
    }

    private function extractor(ProcessRunner $runner): PopplerPdfTextExtractor
    {
        return new PopplerPdfTextExtractor($runner, 'pdfinfo-test', 'pdftotext-test', 5, 10, 64);
    }

    private function assertExtractionFailure(PopplerPdfTextExtractor $extractor, string $expectedCode): void
    {
        try {
            $extractor->extract($this->sourcePath);
            $this->fail('The PDF extraction must reject this input.');
        } catch (PdfTextExtractionException $exception) {
            $this->assertSame($expectedCode, $exception->errorCode);
        }
    }
}
