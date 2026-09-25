<?php

namespace Tests\Feature\Concerns;

use Illuminate\Support\Facades\DB;

trait GuardsSignatureAssetMysql
{
    protected function beforeRefreshingDatabase(): void
    {
        $this->guardSignatureAssetDatabase();
    }

    protected function guardSignatureAssetDatabase(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->assertSame(':memory:', DB::getDatabaseName());
            $this->assertSame(1, (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys);

            return;
        }

        $this->assertSame('1', getenv('ALLOW_MYSQL_FOUNDATION_TESTS'));
        $this->assertSame('mysql', DB::getDriverName());
        $server = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS database_name, @@port AS port, @@datadir AS data_directory, @@bind_address AS bind_address');
        $expected = realpath(base_path('../.foundation-runtime/phase5-mysql/data'));
        $actual = realpath($server->data_directory);
        $normalize = static fn (string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));

        $this->assertSame('8.4.11', $server->version);
        $this->assertSame('school_dss_foundation_test', $server->database_name);
        $this->assertSame(33084, (int) $server->port);
        $this->assertSame('127.0.0.1', $server->bind_address);
        $this->assertNotFalse($expected);
        $this->assertNotFalse($actual);
        $this->assertSame($normalize($expected), $normalize($actual));
    }
}
