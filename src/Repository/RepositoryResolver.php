<?php

namespace Pillar\Repository;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Pillar\Aggregate\AggregateRootId;
use RuntimeException;

final class RepositoryResolver
{
    public function __construct(
        private readonly Container $container,
        #[Config("pillar.repositories")]
        /** @var array<class-string, class-string> map aggregate class → repository class */
        private readonly array $repoMap
    ) {}

    /**
     * @throws BindingResolutionException
     */
    public function forAggregateClass(string $aggregateClass): AggregateRepository
    {
        $repoClass = $this->repoMap[$aggregateClass]
            ?? $this->repoMap['default']
            ?? throw new RuntimeException("No repository mapping for {$aggregateClass} and no default.");

        return $this->container->make($repoClass);
    }

    /**
     * @throws BindingResolutionException
     */
    public function forId(AggregateRootId $id): AggregateRepository
    {
        return $this->forAggregateClass($id->aggregateClass());
    }

}