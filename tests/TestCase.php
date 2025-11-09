<?php

namespace Tests;

use Illuminate\Contracts\Config\Repository as Config;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Pillar\Provider\PillarServiceProvider;
use Tests\Support\Context\DefaultTestContextRegistry;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PillarServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        /** @var Config $config */
        $config = $app['config'];

        $config->set('app.cipher', 'AES-256-CBC');
        $config->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

        $config->set('database.default', 'sqlite');
        $config->set('database.connections.sqlite', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $config->set('cache.default', 'array');

        $config->set('pillar.context_registries', [
            DefaultTestContextRegistry::class,
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(\dirname(__DIR__) . '/database/migrations');
    }
}