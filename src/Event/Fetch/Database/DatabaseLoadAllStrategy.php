<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventWindow;
use Pillar\Event\Fetch\EventFetchStrategy;

class DatabaseLoadAllStrategy extends AbstractDatabaseFetchStrategy implements EventFetchStrategy
{
    public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator
    {
        $qb = $this->perAggregateBase($id);
        $this->applyPerAggregateWindow($qb, $window);
        $qb = $this->orderPerAggregateAsc($qb);

        yield from $this->mapToStoredEvents($qb->get());
    }

    public function stream(?EventWindow $window = null, ?string $eventType = null): Generator
    {
        // Global path: scan across the entire store using global sequence ordering.
        $qb = $this->globalBase();

        if ($window) {
            $this->applyGlobalWindow($qb, $window);
        }

        if ($eventType) {
            $qb->where('event_type', $eventType);
        }

        $qb = $this->orderGlobalAsc($qb);
        yield from $this->mapToStoredEvents($qb->get());
    }
}