<?php

namespace Pillar\Support\Tinker;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\AliasLoader;
use Laravel\Tinker\TinkerServiceProvider;
use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Facade\Pillar;

class TinkerSupport
{
    public function __construct(
        private readonly Application $app,
        #[Config('pillar.context_registries', [])]
        private array                $registries,
        #[Config('tinker.casters', [])]
        private array                $casters,
        private Repository           $config
    )
    {
    }

    public function registerTinkerCaster(): void
    {
        if (!class_exists(TinkerServiceProvider::class)) {
            return;
        }

        $key = EventSourcedAggregateRoot::class;

        // Don’t override a user-defined caster if already added
        if (!isset($this->casters[$key])) {
            $this->casters[$key] = [AggregateCaster::class, 'castAggregate'];
            $this->config->set('tinker.casters', $this->casters);
        }
    }

    public function registerTinkerAliases(): void
    {
        if (!class_exists(AliasLoader::class)) {
            return;
        }

        if (!is_array($this->registries) || $this->registries === []) {
            return;
        }

        $loader = AliasLoader::getInstance();
        $loader->alias('Pillar', Pillar::class);

        foreach ($this->registries as $registryClass) {
            if (!is_string($registryClass) || !class_exists($registryClass)) {
                continue;
            }

            try {
                $registry = $this->app->make($registryClass);
            } catch (BindingResolutionException) {
                continue;
            }

            // Commands
            if (method_exists($registry, 'commands')) {
                foreach ($registry->commands() as $commandClass) {
                    if (!is_string($commandClass) || !class_exists($commandClass)) {
                        continue;
                    }

                    $short = class_basename($commandClass);

                    if (class_exists($short) || interface_exists($short) || trait_exists($short)) {
                        continue; // don’t override existing symbols
                    }

                    $loader->alias($short, $commandClass);
                }
            }

            // Queries
            if (method_exists($registry, 'queries')) {
                foreach ($registry->queries() as $queryClass) {
                    if (!is_string($queryClass) || !class_exists($queryClass)) {
                        continue;
                    }

                    $short = class_basename($queryClass);

                    if (class_exists($short) || interface_exists($short) || trait_exists($short)) {
                        continue;
                    }

                    $loader->alias($short, $queryClass);
                }
            }

            // Aggregate IDs
            if (method_exists($registry, 'aggregateRootIds')) {
                foreach ($registry->aggregateRootIds() as $idClass) {
                    if (!is_string($idClass) || !class_exists($idClass)) {
                        continue;
                    }

                    $short = class_basename($idClass);

                    if (class_exists($short) || interface_exists($short) || trait_exists($short)) {
                        continue;
                    }

                    $loader->alias($short, $idClass);
                }
            }
        }
    }

}