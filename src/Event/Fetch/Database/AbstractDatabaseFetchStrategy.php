<?php

namespace Pillar\Event\Fetch\Database;

use Carbon\Carbon;
use Generator;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\StoredEvent;
use Pillar\Event\UpcasterRegistry;
use Pillar\Serialization\ObjectSerializer;

abstract class AbstractDatabaseFetchStrategy
{
    public function __construct(
        protected ObjectSerializer   $serializer,
        protected EventAliasRegistry $aliases,
        protected UpcasterRegistry   $upcasters,
        #[Config('pillar.event_store.options.table')]
        protected string             $table
    ) {}

    /**
     * Helper to create the base query for a given aggregate.
     */
    protected function baseQuery(): Builder
    {
        return DB::table($this->table)->orderBy('sequence');
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
                sequence: $row->sequence,
                aggregateId: $row->aggregate_id,
                eventType: $row->event_type,
                eventVersion: $fromVersion,
                occurredAt: Carbon::parse($row->occurred_at),
                correlationId: $row->correlation_id
            );
        }
    }
}