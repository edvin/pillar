<?php

namespace Pillar\Event\Fetch;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\Container;
use Pillar\Aggregate\AggregateRootId;

class EventFetchStrategyResolver
{
    /**
     * @var array<string, EventFetchStrategy>
     */
    private array $strategies = [];


    /** @var array<string,string> */
    private array $byAggregate = [];
    private ?string $defaultStrategyName = null;
    private Container $container;

    public function __construct(
        #[Config('pillar.fetch_strategies')]
        array $config,
        Container $container
    ) {
        $this->container = $container;

        // Cache lookup tables to avoid repeated config traversal in resolve()
        $this->byAggregate = $config['overrides'] ?? [];
        $this->defaultStrategyName = $config['default'] ?? null;

        // Eagerly instantiate the strategies that are referenced by config.
        $strategyNames = array_unique(array_filter(array_merge(
            array_values($this->byAggregate),
            $this->defaultStrategyName ? [$this->defaultStrategyName] : []
        )));

        foreach ($strategyNames as $strategyName) {
            $strategyConfig = $config['available'][$strategyName] ?? null;
            if ($strategyConfig === null) {
                continue;
            }
            $class = $strategyConfig['class'];
            $options = $strategyConfig['options'] ?? [];
            $this->strategies[$strategyName] = $this->container->make($class, ['options' => $options]);
        }
    }

    public function resolve(?AggregateRootId $id = null): EventFetchStrategy
    {
        $aggregateClass = $id?->aggregateClass();

        $strategyName = $aggregateClass && isset($this->byAggregate[$aggregateClass])
            ? $this->byAggregate[$aggregateClass]
            : $this->defaultStrategyName;

        if ($strategyName === null) {
            throw new StrategyNotFoundException(sprintf(
                'No fetch strategy configured for aggregate "%s" (default: %s)',
                $aggregateClass ?? '(unknown)',
                'none'
            ));
        }

        if (!isset($this->strategies[$strategyName])) {
            // Lazy instantiate on first use if it wasn't eagerly created.
            $strategyConfig = $config['available'][$strategyName] ?? null;
            if ($strategyConfig === null) {
                throw new StrategyNotFoundException(sprintf(
                    'Fetch strategy "%s" not found in available strategies',
                    $strategyName
                ));
            }
            $class = $strategyConfig['class'];
            $options = $strategyConfig['options'] ?? [];
            $this->strategies[$strategyName] = $this->container->make($class, ['options' => $options]);
        }

        return $this->strategies[$strategyName];
    }
}