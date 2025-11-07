<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;

interface SnapshotPolicy
{
    /**
     * @param AggregateRoot $aggregate The aggregate being saved.
     * @param int $newSeq Last persisted aggregate sequence after this commit.
     * @param int $prevSeq Persisted version at load time (0 if new).
     * @param int $delta New events persisted in this commit (newSeq - prevSeq).
     */
    public function shouldSnapshot(AggregateRoot $aggregate, int $newSeq, int $prevSeq, int $delta): bool;
}