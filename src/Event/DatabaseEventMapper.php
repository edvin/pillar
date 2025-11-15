<?php

namespace Pillar\Event;

use Pillar\Serialization\ObjectSerializer;

final class DatabaseEventMapper
{
    public function __construct(
        private EventAliasRegistry $aliases,
        private ObjectSerializer   $serializer,
        private UpcasterRegistry   $upcasters,
    )
    {
    }

    public function map(object $row): StoredEvent
    {
        // Resolve event class from alias or accept fully-qualified class names
        $type = (string)$row->event_type;
        $eventClass = $this->aliases->resolveClass($type) ?? $type;

        $fromVersion = $row->event_version ?? 1;
        $toVersion = $fromVersion;
        $upcasters = [];

        if ($this->upcasters->has($eventClass)) {
            // Normalize to array, run upcasters, then deserialize
            $data = $this->serializer->toArray($row->event_data);
            $result = $this->upcasters->upcast($eventClass, $fromVersion, $data);

            $data = $this->serializer->fromArray($result->payload);
            $event = $this->serializer->deserialize($eventClass, $data);

            $toVersion = $result->toVersion;
            $upcasters = $result->upcasters;
        } else {
            $event = $this->serializer->deserialize($eventClass, $row->event_data);
        }

        return new StoredEvent(
            event: $event,
            sequence: (int)$row->sequence,
            streamSequence: (int)$row->stream_sequence,
            streamId: (string)$row->stream_id,
            eventType: $type,
            storedVersion: $fromVersion,
            eventVersion: $toVersion,
            occurredAt: (string)$row->occurred_at,
            correlationId: $row->correlation_id ?? null,
            upcasters: $upcasters,
        );
    }
}