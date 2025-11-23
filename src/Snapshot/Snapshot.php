<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;

/**
 * Immutable value object representing a snapshot of an aggregate.
 */
final readonly class Snapshot
{
    public function __construct(
        public AggregateRoot $aggregate,
        public int           $version,
    )
    {
    }
}