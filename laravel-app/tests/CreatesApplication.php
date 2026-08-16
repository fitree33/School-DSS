<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\CachedState;
use Illuminate\Foundation\Testing\WithCachedConfig;
use Illuminate\Foundation\Testing\WithCachedRoutes;

trait CreatesApplication
{
    /**
     * Creates the application.
     */
    public function createApplication(): Application
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $this->traitsUsedByTest = class_uses_recursive(static::class);

        if (isset(CachedState::$cachedConfig, $this->traitsUsedByTest[WithCachedConfig::class])) {
            $this->markConfigCached($app);
        }

        if (isset(CachedState::$cachedRoutes, $this->traitsUsedByTest[WithCachedRoutes::class])) {
            $app->booting(fn () => $this->markRoutesCached($app));
        }

        $app->beforeBootstrapping(
            RegisterProviders::class,
            fn (Application $app) => $this->ensureSafeDatabaseConfiguration($app)
        );

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Refuse every database target except the default in-memory database and
     * the explicitly enabled, isolated Foundation compatibility database.
     */
    private function ensureSafeDatabaseConfiguration(Application $app): void
    {
        $connection = $app['config']->get('database.default');
        $configuration = $app['config']->get("database.connections.{$connection}", []);
        $driver = $configuration['driver'] ?? null;
        $host = $configuration['host'] ?? null;
        $port = $configuration['port'] ?? null;
        $database = $configuration['database'] ?? null;
        $url = $configuration['url'] ?? null;
        $unixSocket = $configuration['unix_socket'] ?? null;
        $hasConnectionOverrides = array_key_exists('read', $configuration)
            || array_key_exists('write', $configuration);
        $hasDirectConnectionTarget = in_array($url, [null, ''], true)
            && ! $hasConnectionOverrides;

        $usesInMemorySqlite = $app->environment('testing')
            && $connection === 'sqlite'
            && $driver === 'sqlite'
            && $database === ':memory:'
            && $hasDirectConnectionTarget;
        $usesIsolatedFoundationMysql = getenv('ALLOW_MYSQL_FOUNDATION_TESTS') === '1'
            && $app->environment('testing')
            && $connection === 'mysql'
            && $driver === 'mysql'
            && $host === '127.0.0.1'
            && (string) $port === '33084'
            && $database === 'school_dss_foundation_test'
            && in_array($unixSocket, [null, ''], true)
            && $hasDirectConnectionTarget;

        if (! $usesInMemorySqlite && ! $usesIsolatedFoundationMysql) {
            throw new \RuntimeException(
                "Unsafe test database configuration: {$connection}://{$host}:{$port}/{$database}. "
                .'Tests require in-memory SQLite unless the isolated Foundation MySQL target is explicitly enabled.'
            );
        }
    }
}
