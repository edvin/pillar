<?php

namespace Pillar\Event\Fetch\Database;

use Carbon\Carbon;
use Generator;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\StoredEvent;
use Pillar\Event\Stream\StreamResolver;
use Pillar\Event\UpcasterRegistry;
use Pillar\Serialization\ObjectSerializer;

abstract class AbstractDatabaseFetchStrategy
{
    public function __construct(
        protected ObjectSerializer   $serializer,
        protected EventAliasRegistry $aliases,
        protected UpcasterRegistry   $upcasters,
        protected StreamResolver     $streamResolver,
    )
    {
    }

    /**
     * Helper to create the base query for a given aggregate.
     */
    protected function baseQuery(?AggregateRootId $id): Builder
    {
        $table = $this->streamResolver->resolve($id);
        $qb = DB::table($table);

        // Per-aggregate reads should use the contiguous per-aggregate version
        // Cross-aggregate reads should keep the global ordering
        return $id !== null
            ? $qb->orderBy('aggregate_sequence')
            : $qb->orderBy('sequence');
    }

    /**
     * Convert raw rows to StoredEvent objects.
     *
     * @param iterable $rows
     * @return Generator<StoredEvent>
     */
    protected function mapToStoredEvents(iterable $rows): Generator
    {
        foreach ($rows as $row) {
            $eventClass = $this->aliases->resolveClass($row->event_type);
            $fromVersion = $row->event_version ?? 1;

            if (!$this->upcasters->has($eventClass)) {
                $event = $this->serializer->deserialize($eventClass, $row->event_data);
            } else {
                $data = $this->serializer->toArray($row->event_data);
                $data = $this->upcasters->upcast($eventClass, $fromVersion, $data);
                $data = $this->serializer->fromArray($data);
                $event = $this->serializer->deserialize($eventClass, $data);
            }

            yield new StoredEvent(
                event: $event,
                sequence: (int) $row->sequence,
                aggregateSequence: (int) $row->aggregate_sequence,
                aggregateId: $row->aggregate_id,
                eventType: $row->event_type,
                eventVersion: $fromVersion,
                occurredAt: (string) $row->occurred_at,
                correlationId: $row->correlation_id
            );
        }
    }
}