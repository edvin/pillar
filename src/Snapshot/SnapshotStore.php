<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;

/**
 * @template T of AggregateRoot
 */
interface SnapshotStore
{
    /**
     * Load a snapshot for a specific aggregate class and identifier. The class
     * is derived from the AggregateRootId#aggregateClass() method.
     *
     * @param AggregateRootId $id
     *
     * @return null|array{aggregate: T, snapshot_version: int}
     */
    public function load(AggregateRootId $id): ?array;

    /**
     * Persist a snapshot for the given aggregate at the given sequence.
     *
     * @param T $aggregate
     * @param int $sequence
     */
    public function save(AggregateRoot $aggregate, int $sequence): void;

    /**
     * Remove any stored snapshot for the given aggregate. The class is derived
     * from the AggregateRootId#aggregateClass() method.
     *
     * @param AggregateRootId $id
     */
    public function delete(AggregateRootId $id): void;
}