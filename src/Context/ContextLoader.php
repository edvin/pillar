<?php

namespace Pillar\Context;

use Pillar\Aggregate\AggregateRegistry;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Bus\CommandBusInterface;
use Pillar\Bus\QueryBusInterface;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Events\Dispatcher;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\EventReplayer;
use Pillar\Event\Projector;
use Pillar\Event\UpcasterRegistry;

/**
 * Loads and wires up a set of ContextRegistry classes:
 *
 *  - Maps commands and queries
 *  - Registers event listeners
 *  - Registers event aliases
 *  - Registers upcasters
 */
final class ContextLoader
{
    public function __construct(
        private readonly Container           $app,
        private readonly CommandBusInterface $commands,
        private readonly QueryBusInterface   $queries,
        private readonly EventAliasRegistry  $aliases,
        private readonly UpcasterRegistry    $upcasters,
        private readonly Dispatcher          $events,
        private readonly EventReplayer       $replayer,
        private readonly AggregateRegistry   $aggregates,
        #[Config('pillar.context_registries')]
        private readonly array               $registryClasses = []
    )
    {
    }

    /**
     * @throws BindingResolutionException
     */
    public function load(): void
    {
        foreach ($this->registryClasses as $registryClass) {
            /** @var ContextRegistry $registry */
            $registry = $this->app->make($registryClass);

            $this->registerCommands($registry);
            $this->registerQueries($registry);
            $this->registerEvents($registry);
            $this->registerAggregateRootIds($registry);
        }
    }

    private function registerCommands(ContextRegistry $registry): void
    {
        $map = $registry->commands();
        if (!empty($map)) {
            $this->commands->map($map);
        }
    }

    private function registerQueries(ContextRegistry $registry): void
    {
        $map = $registry->queries();
        if (!empty($map)) {
            $this->queries->map($map);
        }
    }

    /**
     * @throws BindingResolutionException
     */
    private function registerEvents(ContextRegistry $registry): void
    {
        $events = $registry->events();

        $listeners = $events->getListeners();
        $aliases = $events->getAliases();
        $upcasters = $events->getUpcasters();

        // Register aliases
        foreach ($aliases as $alias => $eventClass) {
            if ($alias !== null && $alias !== '') {
                $this->aliases->register($alias, $eventClass);
            }
        }

        // Register listeners
        foreach ($listeners as $eventClass => $listenerClasses) {
            foreach ($listenerClasses as $listenerClass) {
                $listener = $this->app->make($listenerClass);
                $this->events->listen($eventClass, [$listener, '__invoke']);

                // If the listener is a Projector, register it for EventReplayer
                if (is_string($listenerClass) && is_subclass_of($listenerClass, Projector::class)) {
                    $this->replayer->registerProjector($eventClass, $listenerClass);
                }
            }
        }

        // Register upcasters
        foreach ($upcasters as $eventClass => $classes) {
            foreach ($classes as $uc) {
                $this->upcasters->register($eventClass, $this->app->make($uc));
            }
        }
    }

    private function registerAggregateRootIds(ContextRegistry $registry): void
    {
        $ids = $registry->aggregateRootIds();

        foreach ($ids as $idClass) {
            /** @var class-string<AggregateRootId> $idClass */
            $this->aggregates->register($idClass);
        }
    }
}
