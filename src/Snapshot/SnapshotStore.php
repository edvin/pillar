<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;

interface SnapshotStore
{
    public function load(string $aggregateClass, AggregateRootId $id): ?array;

    public function save(AggregateRoot $aggregate, int $sequence): void;

    public function delete(string $aggregateClass, AggregateRootId $id): void;
}