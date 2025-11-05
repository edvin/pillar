<?php

namespace Pillar\Event\Fetch;

use Generator;
use Pillar\Aggregate\AggregateRootId;

interface EventFetchStrategy
{
    public function load(AggregateRootId $id, int $afterAggregateSequence = 0): Generator;

    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): Generator;
}