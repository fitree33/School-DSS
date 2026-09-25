<?php

// Required only inside an isolated PHPUnit worker; never installed in the app
// or other tests. Match the production scanner's unqualified native calls.
namespace App\Services\Signatures;

function reconciliationEntryTargetAccess(string $operation): never
{
    \Tests\Unit\Signatures\SignatureEntryDirectoryStream::$targetOperations[] = [$operation];
    throw new \RuntimeException('Directory-entry filtering must precede '.$operation.'.');
}

function realpath(...$arguments): never
{
    reconciliationEntryTargetAccess(__FUNCTION__);
}

function stat(...$arguments): never
{
    reconciliationEntryTargetAccess(__FUNCTION__);
}

function lstat(...$arguments): never
{
    reconciliationEntryTargetAccess(__FUNCTION__);
}

function is_link(...$arguments): never
{
    reconciliationEntryTargetAccess(__FUNCTION__);
}

function readlink(...$arguments): never
{
    reconciliationEntryTargetAccess(__FUNCTION__);
}

function fopen(...$arguments): never
{
    reconciliationEntryTargetAccess(__FUNCTION__);
}

function file_get_contents(...$arguments): never
{
    reconciliationEntryTargetAccess(__FUNCTION__);
}

function flock(...$arguments): never
{
    reconciliationEntryTargetAccess(__FUNCTION__);
}

function unlink(...$arguments): never
{
    reconciliationEntryTargetAccess(__FUNCTION__);
}
