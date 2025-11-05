<?php

namespace Pillar\Event\Fetch;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\Container;
use Pillar\Aggregate\AggregateRootId;

class EventFetchStrategyResolver
{
    /** @var array<string, EventFetchStrategy> */
    private array $strategies = [];

    /** @var array<string,string> */
    private array $byAggregate = [];

    private ?string $defaultStrategyName = null;

    /** @var array<string, array{class: class-string, options?: array}> */
    private array $available = [];

    public function __construct(
        #[Config('pillar.fetch_strategies')]
        array             $config,
        private Container $container
    )
    {
        $this->byAggregate = $config['overrides'] ?? [];
        $this->defaultStrategyName = $config['default'] ?? null;
        $this->available = $config['available'] ?? [];

        // Eager instantiate referenced strategies…
        $strategyNames = array_unique(array_filter(array_merge(
            array_values($this->byAggregate),
            $this->defaultStrategyName ? [$this->defaultStrategyName] : [],
        )));
        foreach ($strategyNames as $name) {
            $this->instantiate($name);
        }
    }

    public function resolve(?AggregateRootId $id = null): EventFetchStrategy
    {
        $aggregateClass = $id?->aggregateClass();
        $name = $aggregateClass && isset($this->byAggregate[$aggregateClass])
            ? $this->byAggregate[$aggregateClass]
            : $this->defaultStrategyName;

        if ($name === null) {
            throw new StrategyNotFoundException('No fetch strategy configured.');
        }
        return $this->strategies[$name] ?? $this->instantiate($name);
    }

    private function instantiate(string $name): EventFetchStrategy
    {
        $cfg = $this->available[$name] ?? null;
        if ($cfg === null) {
            throw new StrategyNotFoundException("Fetch strategy \"$name\" not found.");
        }
        return $this->strategies[$name] = $this->container->make(
            $cfg['class'],
            ['options' => $cfg['options'] ?? []],
        );
    }
}