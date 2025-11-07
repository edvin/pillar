<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;

final class AlwaysSnapshotPolicy implements SnapshotPolicy
{
    public function shouldSnapshot(AggregateRoot $aggregate, int $newSeq, int $prevSeq, int $delta): bool
    {
        return $delta > 0; // (snapshot if any new events were recorded)
    }
}
