<?php

declare(strict_types=1);

require dirname(__DIR__, 2).'/laravel-app/vendor/autoload.php';

if (getenv('ALLOW_MYSQL_FOUNDATION_TESTS') !== '1') {
    throw new RuntimeException('Isolated MySQL test opt-in is required.');
}

$probe = new PDO('mysql:host=127.0.0.1;port=33084;dbname=school_dss_foundation_test', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_TIMEOUT => 3,
]);
$target = $probe->query('SELECT VERSION() AS version, DATABASE() AS database_name, @@port AS port, @@datadir AS data_directory, @@bind_address AS bind_address')->fetch(PDO::FETCH_ASSOC);
$expectedDirectory = realpath(dirname(__DIR__, 2).'/.foundation-runtime/phase5-mysql/data');
$actualDirectory = realpath($target['data_directory']);
$normalize = static fn (string $path): string => strtolower(rtrim(str_replace('\\', '/', $path), '/'));
if ($target['version'] !== '8.4.11'
    || $target['database_name'] !== 'school_dss_foundation_test'
    || (int) $target['port'] !== 33084
    || $target['bind_address'] !== '127.0.0.1'
    || $expectedDirectory === false || $actualDirectory === false
    || $normalize($expectedDirectory) !== $normalize($actualDirectory)) {
    throw new RuntimeException('Refusing MySQL tests: server identity or disposable data directory mismatch.');
}
echo 'Verified isolated MySQL: '.json_encode($target, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL;
$probe = null;
