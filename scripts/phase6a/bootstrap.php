<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;

require_once dirname(__DIR__).'/phase5/bootstrap.php';

/** Refuse all database writes until the disposable server itself is identified. */
function phaseSixBootstrap(string $runtime): Application
{
    $app = phaseFiveBootstrap($runtime, true);
    $server = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS database_name, @@port AS port, @@datadir AS data_directory, @@bind_address AS bind_address');
    $expected = realpath(dirname(__DIR__, 2).'/.foundation-runtime/phase5-mysql/data');
    $actual = realpath($server->data_directory);
    $normalize = static fn (string $path): string => DIRECTORY_SEPARATOR === '\\'
        ? strtolower(rtrim(str_replace('\\', '/', $path), '/'))
        : rtrim(str_replace('\\', '/', $path), '/');

    if ($server->version !== '8.4.11'
        || $server->database_name !== 'school_dss_foundation_test'
        || (int) $server->port !== 33084
        || $server->bind_address !== '127.0.0.1'
        || $expected === false || $actual === false
        || $normalize($expected) !== $normalize($actual)) {
        throw new RuntimeException('Phase 6A requires MySQL 8.4.11 at 127.0.0.1:33084 / school_dss_foundation_test with the workspace Phase 5 MySQL data directory.');
    }

    return $app;
}
