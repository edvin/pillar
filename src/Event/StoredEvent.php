<?php

namespace Pillar\Event;

/**
 * Represents a deserialized event loaded from the event store,
 * including its sequence and metadata.
 */
final class StoredEvent
{
    public function __construct(
        public readonly object  $event,
        public readonly int     $sequence,
        public readonly int     $aggregateSequence,
        public readonly string  $aggregateId,
        public readonly string  $eventType,
        public readonly int     $storedVersion,
        public readonly int     $eventVersion,
        public readonly string  $occurredAt,
        public readonly ?string $correlationId = null,
        public readonly ?string $aggregateIdClass = null,
        public readonly ?array  $upcasters = null,
    )
    {
    }
}