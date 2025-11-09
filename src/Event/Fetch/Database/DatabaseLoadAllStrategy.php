<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventWindow;
use Pillar\Event\Fetch\EventFetchStrategy;

class DatabaseLoadAllStrategy extends AbstractDatabaseFetchStrategy implements EventFetchStrategy
{
    public function load(AggregateRootId $id, ?EventWindow $window = null): Generator
    {
        $qb = $this->perAggregateBase($id);
        $this->applyPerAggregateWindow($qb, $window);
        $qb = $this->orderPerAggregateAsc($qb);

        yield from $this->mapToStoredEvents($qb->get());
    }

    public function all(?AggregateRootId $aggregateId = null, ?EventWindow $window = null, ?string $eventType = null): Generator
    {
        if ($aggregateId) {
            // Per-aggregate path
            $qb = $this->perAggregateBase($aggregateId);
            $this->applyPerAggregateWindow($qb, $window);

            if ($eventType) {
                $qb->where('event_type', $eventType);
            }

            $qb = $this->orderPerAggregateAsc($qb);
            yield from $this->mapToStoredEvents($qb->get());
            return;
        }

        // Global path
        $qb = $this->globalBase();
        $this->applyGlobalWindow($qb, $window);

        if ($eventType) {
            $qb->where('event_type', $eventType);
        }

        $qb = $this->orderGlobalAsc($qb);
        yield from $this->mapToStoredEvents($qb->get());
    }
}