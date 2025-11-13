<?php

namespace Pillar\Outbox;

interface Partitioner
{
    /**
     * Map an aggregate id → partition key (e.g. "p07"), or null when count<=1.
     */
    public function partitionKeyForAggregate(string $aggregateId): ?string;

    /**
     * Deterministic label for partition index [0..partition_count-1], or null when count<=1.
     */
    public function labelForIndex(int $index): ?string;

}
