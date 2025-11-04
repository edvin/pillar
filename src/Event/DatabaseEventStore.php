<?php

namespace Pillar\Event;

use Pillar\Aggregate\AggregateRootId;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Pillar\Serialization\ObjectSerializer;
use Carbon\Carbon;

class DatabaseEventStore implements EventStore
{

    public function __construct(
        private ObjectSerializer   $serializer,
        private EventAliasRegistry $aliases,
        private UpcasterRegistry   $upcasters,
        #[Config('pillar.event_store.options.table')]
        private string             $table)
    {
    }

    public function append(AggregateRootId $id, object $event): int
    {
        return DB::table($this->table)->insertGetId([
            'aggregate_id' => $id->value(),
            'event_type' => $this->aliases->resolveAlias($event),
            'event_version' => ($event instanceof VersionedEvent) ? $event::version() : 1,
            'correlation_id' => EventContext::correlationId(),
            'event_data' => $this->serializer->serialize($event),
            'occurred_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ]);
    }

    public function load(AggregateRootId $id, ?int $afterSequence = null): array
    {
        $query = DB::table($this->table)
            ->where('aggregate_id', $id->value());

        if ($afterSequence !== null) {
            $query->where('sequence', '>', $afterSequence);
        }

        return $this->mapToStoredEvents(
            $query->orderBy('sequence')->get()
        );
    }

    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): array
    {
        $query = DB::table($this->table)->orderBy('sequence');

        if ($aggregateId !== null) {
            $query->where('aggregate_id', $aggregateId->value());
        }

        if ($eventType !== null) {
            $query->where('event_type', $eventType);
        }

        return $this->mapToStoredEvents($query->get());
    }

    public function exists(AggregateRootId $id): bool
    {
        return DB::table($this->table)
            ->where('aggregate_id', $id->value())
            ->exists();
    }

    /**
     * Convert raw database rows to StoredEvent objects.
     *
     * @param Collection $rows
     * @return StoredEvent[]
     */
    private function mapToStoredEvents(Collection $rows): array
    {
        $events = [];

        foreach ($rows as $row) {
            $eventClass = $this->aliases->resolveClass($row->event_type);
            $fromVersion = (int)($row->event_version ?? 1);

            if (!$this->upcasters->has($eventClass)) {
                $event = $this->serializer->deserialize($eventClass, $row->event_data);
            } else {
                // Transform serialized event data through upcasters before deserialization
                $data = $this->serializer->toArray($row->event_data);
                $data = $this->upcasters->upcast($eventClass, $fromVersion, $data);
                $data = $this->serializer->fromArray($data);
                $event = $this->serializer->deserialize($eventClass, $data);
            }

            $events[] = new StoredEvent(
                event: $event,
                sequence: $row->sequence,
                aggregateId: $row->aggregate_id,
                eventType: $row->event_type,
                eventVersion: $fromVersion,
                occurredAt: Carbon::parse($row->occurred_at),
                correlationId: $row->correlation_id
            );
        }

        return $events;
    }
}