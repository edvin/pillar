<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\Fetch\EventFetchStrategy;

class DatabaseLoadAllStrategy extends AbstractDatabaseFetchStrategy implements EventFetchStrategy
{
    public function load(AggregateRootId $id, int $afterAggregateSequence = 0): Generator
    {
        $query = $this->baseQuery($id)->where('aggregate_id', $id->value());
        if ($afterAggregateSequence > 0) {
            $query->where('aggregate_sequence', '>', $afterAggregateSequence);
        }

        yield from $this->mapToStoredEvents($query->get());
    }

    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): Generator
    {
        $query = $this->baseQuery($aggregateId);

        if ($aggregateId) {
            $query->where('aggregate_id', $aggregateId->value());
        }

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        yield from $this->mapToStoredEvents($query->get());
    }
}