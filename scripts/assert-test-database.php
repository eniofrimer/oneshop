<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! $app->environment('testing')) {
    throw new RuntimeException('Database reset refused: APP_ENV must be testing.');
}

$connection = $app->make(DatabaseManager::class)->connection();
$driver = $connection->getDriverName();
$hosts = ['pgsql' => 'postgres', 'mysql' => 'mariadb', 'mariadb' => 'mariadb'];
if (! isset($hosts[$driver]) || $connection->getConfig('host') !== $hosts[$driver]) {
    throw new RuntimeException('Database reset refused: use the matching isolated Compose test service.');
}
if ($connection->getDatabaseName() !== 'oneshop_test') {
    throw new RuntimeException('Database reset refused: the configured database must be oneshop_test.');
}
$database = $connection->selectOne($driver === 'pgsql'
    ? 'select current_database() as database_name'
    : 'select database() as database_name');
if (($database->database_name ?? null) !== 'oneshop_test') {
    throw new RuntimeException('Database reset refused: the active database must be oneshop_test.');
}

fwrite(STDOUT, "Verified isolated {$driver} test database: oneshop_test.\n");
