<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app->make('db')->connection();
        if ($app->configurationIsCached() || ! $app->environment('testing') || $connection->getDatabaseName() !== 'oneshop_test') {
            throw new RuntimeException('Tests require uncached testing configuration and the dedicated oneshop_test database.');
        }
        if (str_contains((string) $connection->getConfig('unix_socket'), '/preview-database/')) {
            throw new RuntimeException('RefreshDatabase tests must use an isolated database server, outside the preview socket.');
        }

        return $app;
    }
}
