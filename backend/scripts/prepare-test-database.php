<?php

use Illuminate\Contracts\Console\Kernel;

// Membuat database kosong khusus tes; tidak mengubah database aplikasi.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$config = config('database.connections.mysql');
$testDatabase = 'kpi_management_test';
if ($config['database'] === $testDatabase) {
    throw new RuntimeException('Jalankan persiapan menggunakan konfigurasi database aplikasi.');
}
$pdo = new PDO("mysql:host={$config['host']};port={$config['port']};charset=utf8mb4", $config['username'], $config['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$testDatabase` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$environment = file_get_contents(__DIR__.'/../.env');
foreach (['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $testDatabase, 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'BROADCAST_CONNECTION' => 'null'] as $key => $value) {
    $line = "$key=$value";
    $environment = preg_match('/^'.preg_quote($key, '/').'=/m', $environment)
        ? preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $line, $environment)
        : $environment."\n".$line;
}
file_put_contents(__DIR__.'/../.env.testing', $environment);
echo "Database tes siap: $testDatabase. Jalankan php artisan migrate:fresh --seed --env=testing.\n";
