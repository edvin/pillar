<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;

final class OnDemandSnapshotPolicy implements SnapshotPolicy
{
    public function shouldSnapshot(AggregateRoot $aggregate, int $newSeq, int $prevSeq, int $delta): bool
    {
        return false; // only on-demand
    }
}