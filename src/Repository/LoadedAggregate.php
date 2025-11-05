<?php

namespace Pillar\Repository;

use Pillar\Aggregate\AggregateRoot;

final class LoadedAggregate
{
    public function __construct(
        public readonly AggregateRoot $aggregate,
        public readonly int           $version,
    )
    {
    }
}
