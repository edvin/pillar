<?php

namespace Pillar\Repository;

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;

interface AggregateRepository
{
    public function find(AggregateRootId $id): ?LoadedAggregate;

    public function save(AggregateRoot $aggregate, ?int $expectedVersion = null): void;
}