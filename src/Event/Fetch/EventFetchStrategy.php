<?php

namespace Pillar\Event\Fetch;

use Generator;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventWindow;

interface EventFetchStrategy
{
    public function load(AggregateRootId $id, ?EventWindow $window = null): Generator;

    public function all(?AggregateRootId $aggregateId = null, ?EventWindow $window = null, ?string $eventType = null):
    Generator;
}