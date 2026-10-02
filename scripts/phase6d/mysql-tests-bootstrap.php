<?php

declare(strict_types=1);

// Used only by the Phase 6D PHPUnit configuration. Never rebuild a schema.
require dirname(__DIR__).'/phase6b2/mysql-guard-bootstrap.php';

foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1',
    'DB_PORT' => '33084', 'DB_DATABASE' => 'school_dss_foundation_test',
    'DB_USERNAME' => 'root', 'DB_PASSWORD' => '', 'DATABASE_URL' => '', 'DB_SOCKET' => '',
    'CACHE_DRIVER' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__, 2).'/laravel-app/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$configuration = $app['config']->get('database.connections.mysql');
if ($app['config']->get('database.default') !== 'mysql'
    || $configuration['host'] !== '127.0.0.1' || (string) $configuration['port'] !== '33084'
    || $configuration['database'] !== 'school_dss_foundation_test'
    || ! empty($configuration['url']) || ! empty($configuration['unix_socket'])
    || isset($configuration['read']) || isset($configuration['write'])) {
    throw new RuntimeException('Unsafe Phase 6D test configuration.');
}
if ($kernel->call('migrate', ['--force' => true]) !== 0) {
    throw new RuntimeException('Incremental test migration failed: '.$kernel->output());
}
echo $kernel->output();
// Do not leave a pre-test Laravel handler beneath PHPUnit's per-test handler.
Illuminate\Foundation\Bootstrap\HandleExceptions::flushState();
$app->flush();
Illuminate\Support\Facades\Facade::clearResolvedInstances();
// The selected regression suites use rollback transactions on this already
// migrated database. Suites explicitly rebuilding schemas are excluded.
Illuminate\Foundation\Testing\RefreshDatabaseState::$migrated = true;

// Refuse destructive commands even if a future test accidentally adds one.
Illuminate\Console\Application::starting(function ($artisan): void {
    foreach (['migrate:fresh', 'migrate:reset', 'db:wipe'] as $name) {
        $artisan->add(new class($name) extends Symfony\Component\Console\Command\Command
        {
            protected function execute(Symfony\Component\Console\Input\InputInterface $input, Symfony\Component\Console\Output\OutputInterface $output): int
            {
                throw new RuntimeException('Destructive schema commands are forbidden in Phase 6D verification.');
            }
        });
    }
});
