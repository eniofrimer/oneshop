<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\PaginatedCatalog;

if (PHP_SAPI !== 'cli' || getenv('ONESHOP_BROWSER_PAGINATION_FIXTURE') !== '1' || getenv('GITHUB_ACTIONS') !== 'true') {
    throw new RuntimeException('Use only the explicitly enabled disposable CI pagination fixture.');
}
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
if ($app->configurationIsCached()) {
    throw new RuntimeException('The fixture requires uncached isolated configuration.');
}
$app->make(Kernel::class)->bootstrap();
$connection = DB::connection();
if (! $app->environment('local', 'testing') || $connection->getConfig('host') !== 'postgres' || $connection->getDatabaseName() !== 'oneshop') {
    throw new RuntimeException('Refusing a database outside the isolated browser Compose job.');
}
$ids = PaginatedCatalog::seed();
echo json_encode(['result' => 'seeded', 'products' => count($ids)], JSON_THROW_ON_ERROR).PHP_EOL;
