<?php

namespace Pillar\Event\Fetch;

use Generator;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventWindow;

interface EventFetchStrategy
{
    public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator;

    public function stream(?EventWindow $window = null, ?string $eventType = null):
    Generator;
}