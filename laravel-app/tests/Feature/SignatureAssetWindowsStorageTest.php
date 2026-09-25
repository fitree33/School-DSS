<?php

namespace Tests\Feature;

use Tests\Feature\Concerns\UsesPrivateSignatureStorage;
use Tests\TestCase;

class SignatureAssetWindowsStorageTest extends TestCase
{
    use UsesPrivateSignatureStorage;

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('BLOCKED / NOT PASSED: Windows junction/reparse gate requires Windows.');
        }
        $this->setUpPrivateSignatureStorage();
    }

    protected function tearDown(): void
    {
        $this->tearDownPrivateSignatureStorage();
        parent::tearDown();
    }

    public function test_windows_junction_asset_root_is_rejected_without_touching_target(): void
    {
        $this->assertJunctionRejected('signature_assets.storage_root');
    }

    public function test_windows_junction_temporary_root_is_rejected_without_touching_target(): void
    {
        $this->assertJunctionRejected('signature_assets.temporary_directory');
    }

    public function test_windows_junction_ancestor_is_rejected_before_private_directories_are_created(): void
    {
        $target = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'target';
        $alias = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'alias';
        mkdir($target, 0700);
        $this->createFixtureJunction($alias, $target);
        try {
            config()->set('signature_assets.storage_root', $alias.DIRECTORY_SEPARATOR.'assets');
            $this->assertSignatureProblem(fn () => $this->signatureStorage->beginRequest());
            $this->assertDirectoryDoesNotExist($target.DIRECTORY_SEPARATOR.'assets');
        } finally {
            $this->removeFixtureJunction($alias);
        }
    }

    public function test_windows_php_upload_junction_is_rejected_without_copying_outside_bytes(): void
    {
        $workspace = $this->privateSignatureWorkspace();
        $actual = config('signature_assets.php_upload_directory');
        $source = $this->signatureUploadFixture($this->signaturePng());
        $alias = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'upload-alias';
        $this->createFixtureJunction($alias, $actual);
        try {
            config()->set('signature_assets.php_upload_directory', $alias);
            $this->assertSignatureProblem(fn () => $this->signatureStorage->stageUpload($alias.DIRECTORY_SEPARATOR.basename($source), $workspace));
            $this->assertSame(['.lease'], array_values(array_diff(scandir($workspace->directory()), ['.', '..'])));
            $this->assertFileExists($source);
        } finally {
            $this->removeFixtureJunction($alias);
        }
    }

    private function assertJunctionRejected(string $configuration): void
    {
        $target = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'target';
        mkdir($target, 0700);
        $sentinel = $target.DIRECTORY_SEPARATOR.'sentinel';
        file_put_contents($sentinel, 'outside bytes');
        $alias = config($configuration);
        $this->assertSame([], array_values(array_diff(scandir($alias), ['.', '..'])));
        $this->assertTrue(rmdir($alias));
        $this->createFixtureJunction($alias, $target);
        try {
            $this->assertSignatureProblem(fn () => $this->signatureStorage->beginRequest());
            $this->assertSame('outside bytes', file_get_contents($sentinel));
            $this->assertSame(['sentinel'], array_values(array_diff(scandir($target), ['.', '..'])));
        } finally {
            $this->removeFixtureJunction($alias);
        }
    }

    private function createFixtureJunction(string $alias, string $target): void
    {
        $this->assertSame($this->signatureTestDirectory, dirname($alias));
        $this->assertSame($this->signatureTestDirectory, dirname($target));
        $this->signatureFixturePowershell('$item=New-Item -ItemType Junction -Path $env:SIGNATURE_FIXTURE_ALIAS -Target $env:SIGNATURE_FIXTURE_TARGET; if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0){throw "Fixture is not a reparse point"}', ['SIGNATURE_FIXTURE_ALIAS' => $alias, 'SIGNATURE_FIXTURE_TARGET' => $target], true);
    }

    private function removeFixtureJunction(string $alias): void
    {
        $this->assertSame($this->signatureTestDirectory, dirname($alias));
        $this->signatureFixturePowershell('$item=Get-Item -Force -LiteralPath $env:SIGNATURE_FIXTURE_ALIAS; if(($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0){throw "Refusing non-junction cleanup"}; [IO.Directory]::Delete($env:SIGNATURE_FIXTURE_ALIAS)', ['SIGNATURE_FIXTURE_ALIAS' => $alias]);
    }
}
