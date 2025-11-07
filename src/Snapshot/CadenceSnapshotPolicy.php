<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;

final class CadenceSnapshotPolicy implements SnapshotPolicy
{
    public function __construct(
        private int $threshold = 100,  // snapshot every N events
        private int $offset = 0        // snapshot when (newSeq - offset) % N === 0
    )
    {
    }

    public function shouldSnapshot(AggregateRoot $aggregate, int $newSeq, int $prevSeq, int $delta): bool
    {
        if ($delta <= 0) return false;
        if ($this->threshold <= 0) return false;

        return (($newSeq - $this->offset) % $this->threshold) === 0;
    }
}
