<?php

namespace Tests\Feature\Concerns;

use App\DTOs\Signatures\OwnedSignatureFile;
use App\DTOs\Signatures\OwnedSignatureWorkspace;
use App\Exceptions\ApiProblemException;
use App\Models\SignatureAsset;
use App\Services\Signatures\PngStructureValidator;
use App\Services\Signatures\SignatureAssetStorage;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

trait UsesPrivateSignatureStorage
{
    protected string $signatureTestDirectory;

    private string $signatureTestParent;

    protected SignatureAssetStorage $signatureStorage;

    /** @var list<OwnedSignatureWorkspace> */
    private array $signatureWorkspaces = [];

    /** @var list<OwnedSignatureFile> */
    private array $signatureReceipts = [];

    protected function setUpPrivateSignatureStorage(): void
    {
        $preprovisioned = getenv('SIGNATURE_STORAGE_TEST_ROOT');
        $parent = is_string($preprovisioned) && $preprovisioned !== ''
            ? $preprovisioned
            : base_path('../.foundation-runtime/phase6b2-20260915/storage-tests');
        if ($preprovisioned === false || $preprovisioned === '') {
            if (! is_dir($parent)) {
                mkdir($parent, 0700, true);
            }
        }
        $this->assertDirectoryExists($parent, 'The isolated signature test pool must be provisioned first.');
        $this->signatureTestParent = realpath($parent);
        $directory = $this->signatureTestParent.DIRECTORY_SEPARATOR.(is_string($preprovisioned) && $preprovisioned !== ''
            ? hash('sha256', static::class.'::'.$this->nameWithDataSet())
            : bin2hex(random_bytes(16)));
        if (is_string($preprovisioned) && $preprovisioned !== '') {
            $this->assertSame($directory, realpath($directory), 'The preprovisioned case directory must be canonical.');
            $this->assertSame(['assets', 'php-uploads', 'temporary'], array_values(array_diff(scandir($directory), ['.', '..'])));
            foreach (['assets', 'temporary', 'php-uploads'] as $name) {
                $path = $directory.DIRECTORY_SEPARATOR.$name;
                $this->assertDirectoryExists($path);
                $this->assertSame($path, realpath($path));
                $this->assertSame([], array_values(array_diff(scandir($path), ['.', '..'])), 'Preprovisioned fixture directories must be empty.');
            }
        } else {
            mkdir($directory, 0700);
            foreach (['assets', 'temporary', 'php-uploads'] as $name) {
                $path = $directory.DIRECTORY_SEPARATOR.$name;
                mkdir($path, 0700);
                $this->protectSignatureFixtureDirectory($path);
            }
        }
        $this->signatureTestDirectory = $directory;
        $uploads = $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'php-uploads';
        config()->set([
            'signature_assets.storage_root' => $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'assets',
            'signature_assets.temporary_directory' => $this->signatureTestDirectory.DIRECTORY_SEPARATOR.'temporary',
            'signature_assets.php_upload_directory' => $uploads,
            'signature_assets.php_upload_allowed_service_sids' => [],
        ]);
        $this->signatureStorage = new SignatureAssetStorage(new PngStructureValidator);
    }

    protected function tearDownPrivateSignatureStorage(): void
    {
        foreach ($this->signatureReceipts as $receipt) {
            $this->signatureStorage->preserve($receipt);
        }
        foreach ($this->signatureWorkspaces as $workspace) {
            $this->signatureStorage->cleanup($workspace);
        }
        if (isset($this->signatureTestDirectory) && is_dir($this->signatureTestDirectory)) {
            $this->assertSame($this->signatureTestParent, realpath(dirname($this->signatureTestDirectory)));
            $this->assertSame($this->signatureTestDirectory, realpath($this->signatureTestDirectory));
            (new Filesystem)->deleteDirectory($this->signatureTestDirectory);
        }
    }

    protected function privateSignatureWorkspace(): OwnedSignatureWorkspace
    {
        return $this->signatureWorkspaces[] = $this->signatureStorage->beginRequest();
    }

    protected function privateSignatureReceipt(?OwnedSignatureWorkspace $workspace = null): OwnedSignatureFile
    {
        return $this->signatureReceipts[] = $this->signatureStorage->storeNormalized(
            $this->signaturePng(), (string) Str::uuid(), $workspace ?? $this->privateSignatureWorkspace(),
        );
    }

    protected function signatureAssetForReceipt(OwnedSignatureFile $receipt): SignatureAsset
    {
        return new SignatureAsset([
            'public_id' => $receipt->publicId,
            'storage_key' => $receipt->storageKey,
            'sha256' => $receipt->sha256,
            'size_bytes' => $receipt->sizeBytes,
            'width' => $receipt->width,
            'height' => $receipt->height,
            'mime_type' => 'image/png',
            'normalization_version' => SignatureAsset::NORMALIZATION_VERSION,
        ]);
    }

    protected function signaturePng(): string
    {
        $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.hash('crc32b', $type.$data, true);

        return PngStructureValidator::MAGIC
            .$chunk('IHDR', pack('NNCCCCC', 2, 1, 8, 6, 0, 0, 0))
            .$chunk('IDAT', gzcompress("\x00\x17\x2a\x5c\xff\x00\x00\x00\x00"))
            .$chunk('IEND', '');
    }

    protected function signatureUploadFixture(string $bytes): string
    {
        $path = config('signature_assets.php_upload_directory').DIRECTORY_SEPARATOR.bin2hex(random_bytes(12)).'.upload';
        file_put_contents($path, $bytes);

        return $path;
    }

    protected function assertSignatureProblem(callable $operation, string $code = 'signature_asset_unavailable', int $status = 503): void
    {
        try {
            $operation();
            $this->fail('An invalid signature storage operation was accepted.');
        } catch (ApiProblemException $exception) {
            $this->assertSame($code, $exception->errorCode);
            $this->assertSame($status, $exception->status);
            $this->assertStringNotContainsString($this->signatureTestDirectory, $exception->getMessage());
        }
    }

    protected function protectSignatureFixtureDirectory(string $path): void
    {
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($path, 0700);

            return;
        }
        $this->signatureFixturePowershell('$ids=@([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,"S-1-5-18","S-1-5-32-544"); $sddl="D:P"; foreach($id in $ids){$sddl+="(A;OICI;FA;;;"+$id+")"}; $acl=New-Object Security.AccessControl.DirectorySecurity; $acl.SetSecurityDescriptorSddlForm($sddl,[Security.AccessControl.AccessControlSections]::Access); [IO.Directory]::SetAccessControl($env:SIGNATURE_FIXTURE_PATH,$acl)', ['SIGNATURE_FIXTURE_PATH' => $path]);
    }

    protected function signatureFixturePowershell(string $script, array $environment, bool $allowEnvironmentBlock = false): string
    {
        // Only an OS access/privilege denial may block an ACL or junction fixture.
        // Script errors and failed security assertions must remain test failures.
        $wrapped = '$ErrorActionPreference="Stop"; try { & { '.$script.' } } catch { $e=$_.Exception; while($null -ne $e){ $native=$e.HResult -band 65535; if($e -is [UnauthorizedAccessException] -or $e -is [Security.SecurityException] -or $e -is [Security.AccessControl.PrivilegeNotHeldException] -or $native -in @(5,1300,1314)){ [Console]::Error.WriteLine("SIGNATURE_FIXTURE_ACCESS_DENIED: "+$_.Exception.Message); exit 77 }; $e=$e.InnerException }; [Console]::Error.WriteLine($_.ToString()); exit 1 }';
        $process = new Process(['powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', $wrapped], null, $environment);
        $process->setTimeout(15);
        $process->run();
        if ($allowEnvironmentBlock && $process->getExitCode() === 77 && str_contains($process->getErrorOutput(), 'SIGNATURE_FIXTURE_ACCESS_DENIED:')) {
            $this->markTestSkipped('BLOCKED / NOT PASSED: Windows denied the required test-owned filesystem fixture operation: '.$process->getErrorOutput());
        }
        $this->assertTrue($process->isSuccessful(), 'Private filesystem fixture setup failed: '.$process->getErrorOutput());

        return trim($process->getOutput());
    }
}
