<?php

namespace Pillar\Provider;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Pillar\Bus\CommandBusInterface;
use Pillar\Bus\QueryBusInterface;
use Pillar\Console\InstallPillarCommand;
use Pillar\Console\MakeCommandCommand;
use Pillar\Console\MakeContextCommand;
use Pillar\Console\MakeQueryCommand;
use Pillar\Console\ReplayEventsCommand;
use Pillar\Context\ContextLoader;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\EventReplayer;
use Pillar\Event\EventStore;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\Stream\StreamResolver;
use Pillar\Event\UpcasterRegistry;
use Pillar\Repository\EventStoreRepository;
use Pillar\Repository\RepositoryResolver;
use Pillar\Security\EncryptingSerializer;
use Pillar\Serialization\ObjectSerializer;
use Pillar\Snapshot\DelegatingSnapshotPolicy;
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\SnapshotStore;
use Pillar\Support\PillarManager;

class PillarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/pillar.php', 'pillar');

        $this->app->singleton(ObjectSerializer::class, EncryptingSerializer::class);
        $this->app->singleton(SnapshotPolicy::class, DelegatingSnapshotPolicy::class);
        $this->app->singleton(SnapshotStore::class, Config::get('pillar.snapshot.store.class'));
        $this->app->singleton(EventStore::class, Config::get('pillar.event_store.class'));
        $this->app->singleton(CommandBusInterface::class, Config::get('pillar.buses.command.class'));
        $this->app->singleton(QueryBusInterface::class, Config::get('pillar.buses.query.class'));
        $this->app->singleton(StreamResolver::class, Config::get('pillar.stream_resolver.class'));

        $this->app->singleton(PillarManager::class);
        $this->app->singleton(RepositoryResolver::class);
        $this->app->singleton(EventFetchStrategyResolver::class);
        $this->app->singleton(EventStoreRepository::class);
        $this->app->singleton(EventAliasRegistry::class);
        $this->app->singleton(UpcasterRegistry::class);
        $this->app->singleton(EventReplayer::class);
        $this->app->singleton(ContextLoader::class);
    }

    /**
     * @throws BindingResolutionException
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallPillarCommand::class,
                ReplayEventsCommand::class,
                MakeCommandCommand::class,
                MakeQueryCommand::class,
                MakeContextCommand::class,
            ]);

            $this->publishMigrations();

            $this->publishes([
                __DIR__ . '/../../config/pillar.php' => $this->app->configPath('pillar.php'),
            ], 'config');
        }

        /** @var ContextLoader $contextLoader */
        $this->app->make(ContextLoader::class)->load();
    }

    /**
     * @return void
     */
    public function publishMigrations(): void
    {
        $timestamp = date('Y_m_d_His');

        $names = [
            'create_events_table',
            'create_aggregate_versions_table',
        ];

        $publish = [];
        $base = __DIR__ . '/../../database/migrations';
        foreach ($names as $name) {
            $src = "$base/0000_00_00_000000_$name.php";
            $dest = $this->app->databasePath("migrations/{$timestamp}_$name.php");
            $publish[$src] = $dest;
        }
        $this->publishes($publish, 'migrations');
    }
}
