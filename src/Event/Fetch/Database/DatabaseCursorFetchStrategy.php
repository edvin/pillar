<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventWindow;
use Pillar\Event\Fetch\EventFetchStrategy;

class DatabaseCursorFetchStrategy extends AbstractDatabaseFetchStrategy implements EventFetchStrategy
{
    public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator
    {
        $qb = $this->perAggregateBase($id);
        $this->applyPerAggregateWindow($qb, $window);
        $qb = $this->orderPerAggregateAsc($qb);

        yield from $this->mapToStoredEvents($qb->cursor());
    }

    public function stream(?EventWindow $window = null, ?string $eventType = null): Generator
    {
        // Global scan (optionally filtered by type) with global window & ordering
        $qb = $this->globalBase();

        if ($eventType) {
            $qb->where('event_type', $eventType);
        }

        $this->applyGlobalWindow($qb, $window);
        $qb = $this->orderGlobalAsc($qb);

        yield from $this->mapToStoredEvents($qb->cursor());
    }
}