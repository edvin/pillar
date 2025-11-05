<?php

namespace Pillar\Event;

use Generator;
use Pillar\Aggregate\AggregateRootId;

/**
 * Interface for an event store.
 */
interface EventStore
{
    /**
     * Appends an event to the event stream of a given aggregate root.
     *
     * @param AggregateRootId $id The identifier of the aggregate root.
     * @param object $event The event to append.
     * @return int The sequence number assigned to the appended event.
     */
    public function append(AggregateRootId $id, object $event): int;

    /**
     * Loads the events for the given aggregate root, optionally after a specific sequence number.
     *
     * This method yields StoredEvent objects as a generator, allowing streaming of events from the store.
     * This supports backends using in-memory, chunked, or cursor-based strategies to balance performance and memory usage.
     *
     * @param AggregateRootId $id The identifier of the aggregate root.
     * @param int $afterSequence The sequence number after which to load events (default is 0).
     * @return Generator<StoredEvent> A generator yielding stored events.
     */
    public function load(AggregateRootId $id, int $afterSequence = 0): Generator;

    /**
     * Returns all stored events, optionally filtered by aggregate ID or event type.
     *
     * This method yields StoredEvent objects as a generator, allowing streaming of events from the store.
     * This supports backends using in-memory, chunked, or cursor-based strategies to balance performance and memory usage.
     *
     * @param AggregateRootId|null $aggregateId
     * @param string|null $eventType
     * @return Generator<StoredEvent> A generator yielding stored events.
     */
    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): Generator;
}