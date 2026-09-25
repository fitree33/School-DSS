param(
    [Parameter(Mandatory = $true)]
    [string] $TestList
)

$ErrorActionPreference = 'Stop'
$workspace = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..\..'))
$evidenceParent = Join-Path $workspace '.foundation-runtime\phase6b2-20260915'
$access = [Security.AccessControl.AccessControlSections]::Access
$allowedSids = @([Security.Principal.WindowsIdentity]::GetCurrent().User.Value, 'S-1-5-18', 'S-1-5-32-544')
$identityHash = [Security.Cryptography.SHA256]::Create()
try { $identityKey = [BitConverter]::ToString($identityHash.ComputeHash([Text.Encoding]::UTF8.GetBytes($allowedSids[0]))).Replace('-', '').Substring(0, 16).ToLowerInvariant() }
finally { $identityHash.Dispose() }
$parent = Join-Path $evidenceParent ('storage-tests-' + $identityKey)

function Assert-CanonicalChain([string] $Path) {
    $cursor = $Path
    while ($cursor) {
        $item = Get-Item -Force -LiteralPath $cursor
        if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) { throw 'Reparse-point fixture parent refused.' }
        $cursor = [IO.Path]::GetDirectoryName($cursor)
    }
}

function Assert-PrivateDacl([string] $Path) {
    $actual = [IO.Directory]::GetAccessControl($Path, $access)
    $entries = @($actual.GetAccessRules($true, $true, [Security.Principal.SecurityIdentifier]))
    if (-not $actual.AreAccessRulesProtected -or $entries.Count -ne 3) { throw 'Fixture DACL is not private.' }
    foreach ($entry in $entries) {
        if ($entry.IdentityReference.Value -notin $allowedSids -or $entry.AccessControlType -ne 'Allow' -or $entry.IsInherited -or $entry.FileSystemRights -ne 'FullControl') { throw 'Fixture DACL contains an unexpected ACE.' }
    }
}

function New-PrivateDirectory([string] $Path) {
    if (Test-Path -LiteralPath $Path) { throw 'Refusing to reuse a fixture directory.' }
    $descriptor = New-Object Security.AccessControl.DirectorySecurity
    $sddl = 'D:P'
    foreach ($sid in $allowedSids) { $sddl += '(A;OICI;FA;;;' + $sid + ')' }
    # Only access rules are supplied; never owner, group or SACL/auditing.
    $descriptor.SetSecurityDescriptorSddlForm($sddl, $access)
    [void][IO.Directory]::CreateDirectory($Path, $descriptor)
    Assert-CanonicalChain $Path
    Assert-PrivateDacl $Path
}

Assert-CanonicalChain $evidenceParent
if (-not (Test-Path -LiteralPath $parent)) { New-PrivateDirectory $parent }
Assert-CanonicalChain $parent
Assert-PrivateDacl $parent
[xml]$tests = Get-Content -Raw -LiteralPath $TestList
$classes = @($tests.SelectNodes('//*[local-name()="testClass"]'))
$permitted = @(
    'Tests\Feature\SignatureAssetStorageTest',
    'Tests\Feature\SignatureAssetWindowsAclTest',
    'Tests\Feature\SignatureAssetWindowsStorageTest',
    'Tests\Feature\Api\V2\SignatureAssetApiTest',
    'Tests\Feature\SignatureAssetReconciliationTest',
    'Tests\Feature\SignatureAssetFailureCompensationTest',
    'Tests\Feature\SignatureAssetDocumentIsolationTest'
)
$cases = @()
foreach ($class in $classes) {
    if ($class.name -notin $permitted) { throw 'Only explicitly listed signature fixture test classes may be provisioned.' }
    foreach ($method in $class.testMethod) {
        $name = [string]$class.name + '::' + [string]$method.name
        $id = [string]$method.id
        if (-not $id.StartsWith($name, [StringComparison]::Ordinal)) { throw 'Unexpected PHPUnit test ID.' }
        $suffix = $id.Substring($name.Length)
        if ($suffix.StartsWith('#')) {
            # PHPUnit XML uses # for both numeric and named dataset IDs.
            $dataSet = $suffix.Substring(1)
            if ($dataSet -match '^-?(0|[1-9][0-9]*)$') {
                $name += ' with data set #' + $dataSet
            } else {
                $name += ' with data set "' + $dataSet + '"'
            }
        } elseif ($suffix.StartsWith('@')) {
            $name += ' with data set "' + $suffix.Substring(1) + '"'
        } elseif ($suffix -ne '') { throw 'Unexpected PHPUnit dataset ID.' }
        $hash = [Security.Cryptography.SHA256]::Create()
        try { $key = [BitConverter]::ToString($hash.ComputeHash([Text.Encoding]::UTF8.GetBytes($name))).Replace('-', '').ToLowerInvariant() }
        finally { $hash.Dispose() }
        $cases += $key
    }
}
if ($cases.Count -eq 0 -or @($cases | Select-Object -Unique).Count -ne $cases.Count) { throw 'Empty or duplicate fixture IDs.' }
$pool = Join-Path $parent ('gate-' + [Guid]::NewGuid().ToString('N'))
New-PrivateDirectory $pool
foreach ($key in $cases) {
    $directory = Join-Path $pool $key
    New-PrivateDirectory $directory
    foreach ($leaf in @('assets', 'temporary', 'php-uploads')) { New-PrivateDirectory (Join-Path $directory $leaf) }
}
[pscustomobject]@{pool=$pool; cases=$cases.Count; dacl_only=$true; fixture_creation='complete'; instructions='Set SIGNATURE_STORAGE_TEST_ROOT to pool only for the test process.'} | ConvertTo-Json -Compress
