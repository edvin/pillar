<?php

namespace Pillar\Provider;

use Pillar\Bus\CommandBusInterface;
use Pillar\Bus\QueryBusInterface;
use Pillar\Context\ContextLoader;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\UpcasterRegistry;
use Pillar\Console\InstallPillarCommand;
use Pillar\Event\EventStore;
use Pillar\Repository\RepositoryResolver;
use Pillar\Repository\EventStoreRepository;
use Pillar\Serialization\JsonObjectSerializer;
use Pillar\Serialization\ObjectSerializer;
use Pillar\Snapshot\CacheSnapshotStore;
use Pillar\Snapshot\SnapshotStore;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class PillarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/pillar.php', 'pillar');

        $this->app->singleton(ObjectSerializer::class, function ($app) {
            $serializerClass = Config::get('pillar.serializer.class', JsonObjectSerializer::class);
            return $app->make($serializerClass);
        });

        $this->app->singleton(SnapshotStore::class, function ($app) {
            $storeClass = Config::get('pillar.snapshot.store', CacheSnapshotStore::class);
            return $app->make($storeClass);
        });

        $storeClass = Config::get('pillar.event_store.class');
        $this->app->singleton(EventStore::class, $storeClass);;

        $this->app->singleton(RepositoryResolver::class);

        $this->app->singleton(CommandBusInterface::class, function ($app) {
            $class = Config::get('pillar.buses.command');
            return $app->make($class);
        });

        $this->app->singleton(QueryBusInterface::class, function ($app) {
            $class = Config::get('pillar.buses.query');
            return $app->make($class);
        });

        $this->app->singleton(EventStoreRepository::class);
        $this->app->singleton(EventAliasRegistry::class);
        $this->app->singleton(UpcasterRegistry::class);
        $this->app->singleton(ContextLoader::class);
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([InstallPillarCommand::class]);

            $this->publishes([
                __DIR__ . '/../../database/migrations/0000_00_00_000000_create_events_table.php' =>
                    $this->app->databasePath('migrations/' . date('Y_m_d_His') . '_create_events_table.php'),
            ], 'migrations');

            $this->publishes([
                __DIR__ . '/../../config/pillar.php' => $this->app->configPath('pillar.php'),
            ], 'config');
        }

        /** @var ContextLoader $contextLoader */
        $this->app->make(ContextLoader::class)->load();
    }
}
