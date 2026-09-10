<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Vite;

/** Bootstrap only a disposable Phase 5 runtime, without reading the application's .env. */
function phaseFiveBootstrap(string $runtime, bool $mysql = false): Application
{
    $workspace = dirname(__DIR__, 2);
    $allowed = realpath($workspace.'/.foundation-runtime');
    $resolved = realpath($runtime);
    if ($allowed === false || $resolved === false || ! str_starts_with(str_replace('\\', '/', $resolved).'/', str_replace('\\', '/', $allowed).'/phase5-')) {
        throw new RuntimeException('Phase 5 runtime must be inside the ignored workspace runtime directory.');
    }
    $settings = is_file($runtime.'/settings.json') ? json_decode(file_get_contents($runtime.'/settings.json'), true, 512, JSON_THROW_ON_ERROR) : [];
    $environment = [
        'APP_ENV' => $mysql ? 'testing' : 'local',
        'APP_NAME' => 'School DSS Phase 5 QA',
        'APP_DEBUG' => 'false',
        'APP_KEY' => $settings['app_key'] ?? 'base64:'.base64_encode(str_repeat('q', 32)),
        'APP_URL' => 'http://127.0.0.1:8015',
        'FRONTEND_URL' => 'http://127.0.0.1:8015',
        'SANCTUM_STATEFUL_DOMAINS' => '127.0.0.1:8015',
        'DB_CONNECTION' => $mysql ? 'mysql' : 'sqlite',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '33084',
        'DB_DATABASE' => $mysql ? 'school_dss_foundation_test' : $runtime.'/database.sqlite',
        'DB_USERNAME' => 'root',
        'DB_PASSWORD' => '',
        'DB_SOCKET' => '',
        'DATABASE_URL' => '',
        'DB_FOREIGN_KEYS' => 'true',
        'CACHE_DRIVER' => 'file',
        'CACHE_PREFIX' => 'phase5_qa_',
        'SESSION_DRIVER' => 'file',
        'SESSION_COOKIE' => 'phase5_qa_session',
        'SESSION_DOMAIN' => '',
        'SESSION_SECURE_COOKIE' => 'false',
        'QUEUE_CONNECTION' => $mysql ? 'sync' : 'database',
        'DB_QUEUE_CONNECTION' => $mysql ? 'mysql' : 'sqlite',
        'FILESYSTEM_DISK' => 'local',
        'PROJECT_IMPORTS_DISK' => 'project-imports',
        'MAIL_MAILER' => 'array',
        'LOG_CHANNEL' => 'single',
        'BCRYPT_ROUNDS' => '4',
        'PULSE_ENABLED' => 'false',
        'TELESCOPE_ENABLED' => 'false',
        'PROJECT_IMPORT_PROVIDER' => 'n8n',
        'PROJECT_IMPORT_N8N_URL' => 'http://127.0.0.1:8016/fixture-extract',
        'PROJECT_IMPORT_N8N_TOKEN' => '',
        'PROJECT_IMPORT_CALLBACK_URL' => '',
        'PROJECT_IMPORT_CALLBACK_SECRET' => $settings['callback_secret'] ?? '',
        'PROJECT_IMPORT_CALLBACK_PREVIOUS_SECRET' => '',
        'PROJECT_IMPORT_QUEUE' => 'document-imports',
        'PROJECT_IMPORT_PDFINFO_BINARY' => $settings['pdfinfo'] ?? 'pdfinfo',
        'PROJECT_IMPORT_PDFTOTEXT_BINARY' => $settings['pdftotext'] ?? 'pdftotext',
        'APP_CONFIG_CACHE' => $runtime.'/bootstrap/config.php',
        'APP_ROUTES_CACHE' => $runtime.'/bootstrap/routes.php',
        'APP_EVENTS_CACHE' => $runtime.'/bootstrap/events.php',
        'APP_SERVICES_CACHE' => $runtime.'/bootstrap/services.php',
        'APP_PACKAGES_CACHE' => $runtime.'/bootstrap/packages.php',
        'VIEW_COMPILED_PATH' => $runtime.'/storage/framework/views',
    ];
    foreach ($environment as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
    foreach (['bootstrap', 'storage/app/private/project-imports', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs'] as $directory) {
        if (! is_dir($runtime.'/'.$directory)) {
            mkdir($runtime.'/'.$directory, 0700, true);
        }
    }
    require_once $workspace.'/laravel-app/vendor/autoload.php';
    $app = require $workspace.'/laravel-app/bootstrap/app.php';
    $app->addAbsoluteCachePathPrefix(substr($runtime, 0, 3));
    $app->useEnvironmentPath($runtime);
    $app->useStoragePath($runtime.'/storage');
    $app->make(Kernel::class)->bootstrap();
    // A normal development server's public/hot must never redirect this runtime.
    $app->make(Vite::class)->useHotFile($runtime.'/vite.hot');
    if ($mysql) {
        $database = $app['config']->get('database.connections.mysql');
        if (getenv('ALLOW_MYSQL_FOUNDATION_TESTS') !== '1' || $database['host'] !== '127.0.0.1' || (string) $database['port'] !== '33084' || $database['database'] !== 'school_dss_foundation_test' || ! empty($database['url']) || ! empty($database['unix_socket']) || isset($database['read']) || isset($database['write'])) {
            throw new RuntimeException('Isolated Foundation MySQL opt-in and exact target are required.');
        }
    }

    return $app;
}
