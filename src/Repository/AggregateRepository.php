<?php

namespace Pillar\Repository;

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventWindow;

interface AggregateRepository
{
    public function find(AggregateRootId $id, ?EventWindow $window = null): ?LoadedAggregate;

    public function save(AggregateRoot $aggregate, ?int $expectedVersion = null): void;
}