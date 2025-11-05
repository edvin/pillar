<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\Fetch\EventFetchStrategy;

class DatabaseCursorFetchStrategy extends AbstractDatabaseFetchStrategy implements EventFetchStrategy
{
    public function load(AggregateRootId $id, int $afterSequence = 0): Generator
    {
        $query = $this->baseQuery($id)->where('aggregate_id', $id->value());
        if ($afterSequence > 0) {
            $query->where('sequence', '>', $afterSequence);
        }

        yield from $this->mapToStoredEvents($query->cursor());
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

        yield from $this->mapToStoredEvents($query->cursor());
    }
}