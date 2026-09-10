<?php

declare(strict_types=1);
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

$workspace = dirname(__DIR__, 2);
$public = realpath($workspace.'/laravel-app/public');
$uri = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = realpath($public.$uri);
// Never execute public/index.php: it would bootstrap the normal application .env.
// Only serve static assets resolved beneath public; symlinked normal storage is excluded.
if ($file !== false && is_file($file)
    && preg_match('/\.(?:css|js|map|svg|ico|png|jpe?g|webp|gif|woff2?|ttf)$/i', $file) === 1
    && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', $public).'/')) {
    return false;
}
require __DIR__.'/bootstrap.php';
$app = phaseFiveBootstrap($workspace.'/.foundation-runtime/phase5-qa');
$kernel = $app->make(Kernel::class);
$response = $kernel->handle($request = Request::capture())->send();
$kernel->terminate($request, $response);
