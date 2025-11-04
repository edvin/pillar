<?php

namespace Pillar\Context;

use Pillar\Bus\CommandBusInterface;
use Pillar\Bus\QueryBusInterface;
use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Events\Dispatcher;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\UpcasterRegistry;

/**
 * Loads and wires up a set of ContextRegistry classes:
 *
 *  - maps commands and queries
 *  - registers event listeners
 *  - registers event aliases
 *  - registers upcasters
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
        #[Config('pillar.context_registries')]
        private readonly array $registryClasses = []
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
        foreach ($aliases as $eventClass => $alias) {
            if ($alias !== null && $alias !== '') {
                $this->aliases->register($eventClass, $alias);
            }
        }

        // Register listeners
        foreach ($listeners as $eventClass => $listenerClasses) {
            foreach ($listenerClasses as $listenerClass) {
                $listener = $this->app->make($listenerClass);
                $this->events->listen($eventClass, [$listener, '__invoke']);
            }
        }
        
        // Register upcasters
        foreach ($upcasters as $eventClass => $classes) {
            foreach ($classes as $uc) {
                $this->upcasters->register($eventClass, $this->app->make($uc));
            }
        }
    }
}
