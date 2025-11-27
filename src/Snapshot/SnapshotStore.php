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
     * @return null|Snapshot
     */
    public function load(AggregateRootId $id): ?Snapshot;

    /**
     * Persist a snapshot for the given aggregate at the given sequence.
     *
     * @param AggregateRootId $id
     * @param int $sequence
     * @param array<string, mixed> $payload Snapshot memento (from toSnapshot()).
     */
    public function save(AggregateRootId $id, int $sequence, array $payload): void;

    /**
     * Remove any stored snapshot for the given aggregate. The class is derived
     * from the AggregateRootId#aggregateClass() method.
     *
     * @param AggregateRootId $id
     */
    public function delete(AggregateRootId $id): void;
}