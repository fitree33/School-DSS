<?php

namespace App\Services\Documents;

use RuntimeException;
use Symfony\Component\Process\Process;

/** Host privacy checks shared by signed files and verified download snapshots. */
final class DocumentPrivateFilesystem
{
    public function prepareDirectory(string $directory): void
    {
        $this->assertCanonicalPath($directory, allowMissing: true);
        if (! is_dir($directory)) {
            if (! @mkdir($directory, 0700, true)) {
                throw new RuntimeException('Private storage is unavailable.');
            }
            if (PHP_OS_FAMILY === 'Windows') {
                // Set only the new directory's DACL. Existing directories are
                // verified, never repaired; owner and SACL remain untouched.
                $this->powershell(<<<'POWERSHELL'
                    $access=[Security.AccessControl.AccessControlSections]::Access
                    $acl=New-Object Security.AccessControl.DirectorySecurity
                    $ids=@([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,"S-1-5-18","S-1-5-32-544")
                    $sddl="D:P"
                    foreach($id in $ids){$sddl+="(A;OICI;FA;;;"+$id+")"}
                    $acl.SetSecurityDescriptorSddlForm($sddl,$access)
                    [IO.Directory]::SetAccessControl($env:DOCUMENT_PRIVATE_PATH,$acl)
                    POWERSHELL, $directory);
            } elseif (! @chmod($directory, 0700)) {
                throw new RuntimeException('Private directory creation failed.');
            }
        }
        $this->assertCanonicalPath($directory);
        $this->assertPrivateDirectory($directory);
    }

    public function assertCanonicalPath(string $path, bool $allowMissing = false): void
    {
        $normal = str_replace('\\', '/', $path);
        if ($path === '' || preg_match('/[\x00-\x1f\x7f*?"<>|]/', $normal)
            || (PHP_OS_FAMILY === 'Windows' ? preg_match('/\A[A-Za-z]:\//', $normal) !== 1 : ! str_starts_with($normal, '/'))
            || str_starts_with($normal, '//')) {
            throw new RuntimeException('An absolute private storage root is required.');
        }
        foreach (explode('/', preg_replace('/\A[A-Za-z]:\//', '', ltrim($normal, '/'))) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')
                || preg_match('/[. ]$/', $segment) || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])(?:\.|$)/i', $segment)) {
                throw new RuntimeException('An unaliased storage root is required.');
            }
        }
        $cursor = $path;
        do {
            clearstatcache(true, $cursor);
            if (file_exists($cursor) || is_link($cursor)) {
                $resolved = realpath($cursor);
                if ($resolved === false || is_link($cursor) || $this->normalize($cursor) !== $this->normalize($resolved)) {
                    throw new RuntimeException('Aliased storage is forbidden.');
                }
            } elseif (! $allowMissing) {
                throw new RuntimeException('Storage is unavailable.');
            }
            $parent = dirname($cursor);
            if ($parent === $cursor) break;
            $cursor = $parent;
        } while (true);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->powershell('$p=$env:DOCUMENT_PRIVATE_PATH; while($p){ if(Test-Path -LiteralPath $p){ $i=Get-Item -Force -LiteralPath $p -ErrorAction Stop; if(($i.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0){ exit 9 } }; $q=[IO.Path]::GetDirectoryName($p); if($q -eq $p){ break }; $p=$q }', $path);
        }
    }

    public function assertPrivateDirectory(string $directory): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertWindowsAccess($directory, requireProtected: true);
        } elseif ((fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException('Existing storage directory is not private.');
        }
    }

    /** Check the actual file ACL before any sensitive bytes are written. */
    public function assertPrivateFile(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->assertWindowsAccess($path, requireProtected: false);
        } elseif ((fileperms($path) & 0077) !== 0) {
            throw new RuntimeException('The stored file is not private.');
        }
    }

    private function assertWindowsAccess(string $path, bool $requireProtected): void
    {
        $protected = $requireProtected ? 'if(-not $acl.AreAccessRulesProtected){exit 8}; ' : '';
        $this->powershell('$ids=@([Security.Principal.WindowsIdentity]::GetCurrent().User.Value,"S-1-5-18","S-1-5-32-544"); '
            .'$acl=Get-Acl -LiteralPath $env:DOCUMENT_PRIVATE_PATH -ErrorAction Stop; '.$protected
            .'$rules=@($acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier])); '
            .'if($rules.Count -eq 0){exit 8}; '
            .'foreach($r in $rules){if($r.AccessControlType -eq "Allow" -and $ids -notcontains $r.IdentityReference.Value){exit 9}}', $path);
    }

    private function powershell(string $script, string $path): void
    {
        $process = new Process(['powershell.exe', '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', '$ErrorActionPreference="Stop"; '.$script], null, ['DOCUMENT_PRIVATE_PATH' => $path]);
        $process->setTimeout(10);
        $process->disableOutput();
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('Private storage policy could not be verified.');
        }
    }

    private function normalize(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($normalized) : $normalized;
    }
}
