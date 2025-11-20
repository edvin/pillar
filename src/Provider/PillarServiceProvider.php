<?php

namespace Pillar\Provider;

use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Pillar\Aggregate\AggregateRegistry;
use Pillar\Bus\CommandBusInterface;
use Pillar\Bus\QueryBusInterface;
use Pillar\Console\InstallPillarCommand;
use Pillar\Console\MakeAggregateCommand;
use Pillar\Console\MakeCommandCommand;
use Pillar\Console\MakeContextCommand;
use Pillar\Console\MakeEventCommand;
use Pillar\Console\MakeQueryCommand;
use Pillar\Console\OutboxPartitionSyncCommand;
use Pillar\Console\OutboxWorkCommand;
use Pillar\Console\ReplayEventsCommand;
use Pillar\Context\ContextLoader;
use Pillar\Event\DatabaseEventMapper;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\EventReplayer;
use Pillar\Event\EventStore;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\PublicationPolicy;
use Pillar\Event\UpcasterRegistry;
use Pillar\Http\Middleware\AuthorizePillarUI;
use Pillar\Logging\PillarLogger;
use Pillar\Metrics\Metrics;
use Pillar\Metrics\NullMetrics;
use Pillar\Metrics\Prometheus\CollectorRegistryFactory;
use Pillar\Metrics\Prometheus\PrometheusMetrics;
use Pillar\Metrics\Prometheus\PrometheusNameFactory;
use Pillar\Outbox\DatabaseOutbox;
use Pillar\Outbox\Lease\DatabasePartitionLeaseStore;
use Pillar\Outbox\Lease\PartitionLeaseStore;
use Pillar\Outbox\Outbox;
use Pillar\Outbox\Partitioner;
use Pillar\Repository\EventStoreRepository;
use Pillar\Repository\RepositoryResolver;
use Pillar\Security\EncryptingSerializer;
use Pillar\Serialization\ObjectSerializer;
use Pillar\Snapshot\DelegatingSnapshotPolicy;
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\SnapshotStore;
use Pillar\Support\PillarManager;
use Pillar\Support\Tinker\TinkerSupport;
use Pillar\Support\UI\UISettings;
use Prometheus\CollectorRegistry;
use RuntimeException;

class PillarServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/pillar.php', 'pillar');

        // Configurable implementations
        $this->app->singleton(PillarLogger::class);
        $this->wireMetrics();
        $this->app->singleton(ObjectSerializer::class, EncryptingSerializer::class);
        $this->app->singleton(SnapshotPolicy::class, DelegatingSnapshotPolicy::class);
        $this->app->singleton(SnapshotStore::class, Config::get('pillar.snapshot.store.class'));
        $this->app->singleton(EventStore::class, Config::get('pillar.event_store.class'));
        $this->app->singleton(CommandBusInterface::class, Config::get('pillar.buses.command.class'));
        $this->app->singleton(QueryBusInterface::class, Config::get('pillar.buses.query.class'));
        $this->app->singleton(PublicationPolicy::class, Config::get('pillar.publication_policy.class'));
        $this->app->singleton(Partitioner::class, Config::get('pillar.outbox.partitioner.class'));
        $this->app->singleton(Outbox::class, DatabaseOutbox::class);
        $this->app->singleton(PartitionLeaseStore::class, DatabasePartitionLeaseStore::class);
        $this->app->singleton(PillarManager::class);
        $this->app->singleton(RepositoryResolver::class);
        $this->app->singleton(EventFetchStrategyResolver::class);
        $this->app->singleton(EventStoreRepository::class);
        $this->app->singleton(EventAliasRegistry::class);
        $this->app->singleton(DatabaseEventMapper::class);
        $this->app->singleton(UpcasterRegistry::class);
        $this->app->singleton(AggregateRegistry::class);
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
                MakeAggregateCommand::class,
                MakeEventCommand::class,
                OutboxWorkCommand::class,
                OutboxPartitionSyncCommand::class
            ]);

            /** @var TinkerSupport $tinker */
            $tinker = $this->app->make(TinkerSupport::class);
            $tinker->registerTinkerAliases();
            $tinker->registerTinkerCaster();

            $this->publishMigrations();

            $this->publishes([
                __DIR__ . '/../../config/pillar.php' => $this->app->configPath('pillar.php'),
            ], 'config');
        }

        // Load registered contexts
        $this->app->make(ContextLoader::class)->load();

        $this->mountPillarUI();
    }

    private function mountPillarUI(): void
    {
        /** @var UISettings $settings */
        $settings = $this->app->make(UISettings::class);
        if (!$settings->enabled) {
            return;
        }

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        // Alias access middleware
        $router->aliasMiddleware('pillar.ui', AuthorizePillarUI::class);

        // Mount UI routes
        $router->group([
            'prefix' => $settings->path,
            'as' => 'pillar.ui.',
            'middleware' => ['pillar.ui'],
        ], function () {
            require __DIR__ . '/../../routes/pillar-ui.php';
        });

        // Load UI views
        $this->loadViewsFrom(__DIR__ . '/../../resources/views/ui', 'pillar-ui');
    }

    /**
     * @return void
     */
    public function publishMigrations(): void
    {
        $timestamp = date('Y_m_d_His');

        $names = [
            'create_events_table',
            'create_outbox_table',
            'create_outbox_partitions_table',
            'create_outbox_workers_table',
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

    private function wireMetrics(): void
    {
        $driver = config('pillar.metrics.driver', 'none');

        if ($driver === 'prometheus') {
            if (class_exists(CollectorRegistry::class)) {
                $this->app->singleton(CollectorRegistryFactory::class);
                $this->app->singleton(PrometheusNameFactory::class);
                $this->app->singleton(Metrics::class, PrometheusMetrics::class);
                return;
            }

            // @codeCoverageIgnoreStart
            $this->app->make(PillarLogger::class)->warning(
                "Pillar metrics driver 'prometheus' selected, but promphp/prometheus_client_php " .
                "is not installed. Falling back to NullMetrics."
            );
            // @codeCoverageIgnoreEnd
        }

        $this->app->singleton(Metrics::class, NullMetrics::class);
    }
}
