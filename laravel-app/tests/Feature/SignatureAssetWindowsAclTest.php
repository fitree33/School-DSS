<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Concerns\UsesPrivateSignatureStorage;
use Tests\TestCase;

class SignatureAssetWindowsAclTest extends TestCase
{
    use UsesPrivateSignatureStorage;

    // A deliberately synthetic fixture SID; no local account identifier is stored here.
    private const FIXTURE_RUNTIME_SID = 'S-1-5-21-111111111-222222222-333333333-1234';

    protected function setUp(): void
    {
        parent::setUp();
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('BLOCKED / NOT PASSED: Windows ACL validation requires Windows.');
        }
        $this->setUpPrivateSignatureStorage();
    }

    protected function tearDown(): void
    {
        $this->tearDownPrivateSignatureStorage();
        parent::tearDown();
    }

    #[DataProvider('unapprovedIdentities')]
    public function test_unapproved_allow_sid_is_rejected_in_each_private_layer(string $configuration, string $sid): void
    {
        $source = $this->signatureUploadFixture($this->signaturePng());
        $this->addFixtureReadAllow(config($configuration), $sid);
        $this->assertSignatureProblem(fn () => $configuration === 'signature_assets.php_upload_directory'
            ? $this->signatureStorage->assertUploadSource($source)
            : $this->signatureStorage->beginRequest());
    }

    public static function unapprovedIdentities(): array
    {
        $cases = [];
        foreach (self::privateLayers() as $name => [$configuration]) {
            foreach (['everyone' => 'S-1-1-0', 'users' => 'S-1-5-32-545', 'authenticated-users' => 'S-1-5-11', 'unapproved-runtime' => self::FIXTURE_RUNTIME_SID] as $identity => $sid) {
                $cases[$name.'-'.$identity] = [$configuration, $sid];
            }
        }

        return $cases;
    }

    #[DataProvider('privateLayers')]
    public function test_inherited_acl_is_rejected_in_each_private_layer(string $configuration): void
    {
        $source = $this->signatureUploadFixture($this->signaturePng());
        $this->signatureFixturePowershell('$acl=[IO.Directory]::GetAccessControl($env:SIGNATURE_FIXTURE_PATH,[Security.AccessControl.AccessControlSections]::Access); $acl.SetAccessRuleProtection($false,$false); [IO.Directory]::SetAccessControl($env:SIGNATURE_FIXTURE_PATH,$acl); $actual=[IO.Directory]::GetAccessControl($env:SIGNATURE_FIXTURE_PATH,[Security.AccessControl.AccessControlSections]::Access); if($actual.AreAccessRulesProtected){throw "Fixture inheritance was not enabled"}; if(@($actual.GetAccessRules($false,$true,[Security.Principal.SecurityIdentifier])).Count -eq 0){throw "Fixture has no inherited entries"}', ['SIGNATURE_FIXTURE_PATH' => config($configuration)], true);
        $this->assertSignatureProblem(fn () => $configuration === 'signature_assets.php_upload_directory'
            ? $this->signatureStorage->assertUploadSource($source)
            : $this->signatureStorage->beginRequest());
    }

    public static function privateLayers(): array
    {
        return [
            'assets' => ['signature_assets.storage_root'],
            'temporary' => ['signature_assets.temporary_directory'],
            'php-upload' => ['signature_assets.php_upload_directory'],
        ];
    }

    public function test_explicit_runtime_sid_configuration_allows_upload_and_empty_default_denies_it(): void
    {
        $source = $this->signatureUploadFixture($this->signaturePng());
        $this->addFixtureReadAllow(config('signature_assets.php_upload_directory'), self::FIXTURE_RUNTIME_SID);
        $this->assertSignatureProblem(fn () => $this->signatureStorage->assertUploadSource($source));
        config()->set('signature_assets.php_upload_allowed_service_sids', [self::FIXTURE_RUNTIME_SID]);
        $this->signatureStorage->assertUploadSource($source);
        $this->assertFileExists($source);
        config()->set('signature_assets.php_upload_allowed_service_sids', []);
        $this->assertSignatureProblem(fn () => $this->signatureStorage->assertUploadSource($source));
    }

    public function test_upload_service_allowlist_refuses_broad_sids_names_and_injected_values(): void
    {
        $source = $this->signatureUploadFixture($this->signaturePng());
        foreach ([['S-1-1-0'], ['S-1-5-11'], ['BUILTIN\\Users'], ['S-1-5-32-545'], ['S-1-5-21-1-2-3-4";exit 0;#']] as $allowlist) {
            config()->set('signature_assets.php_upload_allowed_service_sids', $allowlist);
            $this->assertSignatureProblem(fn () => $this->signatureStorage->assertUploadSource($source));
        }
    }

    public function test_dacl_only_fixture_change_preserves_owner_and_effective_inheritance_policy(): void
    {
        $path = config('signature_assets.storage_root');
        $inventory = '$acl=[IO.Directory]::GetAccessControl($env:SIGNATURE_FIXTURE_PATH,[Security.AccessControl.AccessControlSections]::Access -bor [Security.AccessControl.AccessControlSections]::Owner); @{owner=$acl.GetOwner([Security.Principal.SecurityIdentifier]).Value; protected=$acl.AreAccessRulesProtected} | ConvertTo-Json -Compress';
        $before = json_decode($this->signatureFixturePowershell($inventory, ['SIGNATURE_FIXTURE_PATH' => $path]), true, flags: JSON_THROW_ON_ERROR);
        $this->addFixtureReadAllow($path, self::FIXTURE_RUNTIME_SID);
        $after = json_decode($this->signatureFixturePowershell($inventory, ['SIGNATURE_FIXTURE_PATH' => $path]), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($before['owner'], $after['owner']);
        $this->assertTrue($before['protected']);
        $this->assertTrue($after['protected']);
    }

    private function addFixtureReadAllow(string $path, string $sid): void
    {
        $this->assertSame($this->signatureTestDirectory, dirname($path));
        $this->signatureFixturePowershell('$acl=[IO.Directory]::GetAccessControl($env:SIGNATURE_FIXTURE_PATH,[Security.AccessControl.AccessControlSections]::Access); $sid=New-Object Security.Principal.SecurityIdentifier($env:SIGNATURE_FIXTURE_SID); $rule=New-Object Security.AccessControl.FileSystemAccessRule($sid,"ReadAndExecute","ContainerInherit,ObjectInherit","None","Allow"); $acl.AddAccessRule($rule); [IO.Directory]::SetAccessControl($env:SIGNATURE_FIXTURE_PATH,$acl); $actual=[IO.Directory]::GetAccessControl($env:SIGNATURE_FIXTURE_PATH,[Security.AccessControl.AccessControlSections]::Access); $found=@($actual.GetAccessRules($true,$false,[Security.Principal.SecurityIdentifier]) | Where-Object {$_.IdentityReference.Value -eq $env:SIGNATURE_FIXTURE_SID -and $_.AccessControlType -eq "Allow"}); if($found.Count -eq 0){throw "Fixture Allow entry was not persisted"}', ['SIGNATURE_FIXTURE_PATH' => $path, 'SIGNATURE_FIXTURE_SID' => $sid], true);
    }
}
