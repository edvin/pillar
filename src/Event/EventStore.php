<?php

namespace Pillar\Event;

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
     * @param AggregateRootId $id The identifier of the aggregate root.
     * @param int $afterSequence The sequence number after which to load events (default is 0).
     * @return list<StoredEvent> An array of domain events.
     */
    public function load(AggregateRootId $id, int $afterSequence = 0): array;

    /**
     * Checks if any events exist for the given aggregate root.
     *
     * @param AggregateRootId $id The identifier of the aggregate root.
     * @return bool True if events exist, false otherwise.
     */
    public function exists(AggregateRootId $id): bool;

    /**
     * Returns all stored events, optionally filtered by aggregate ID or event type.
     *
     * @param AggregateRootId|null $aggregateId
     * @param string|null $eventType
     * @return list<StoredEvent>
     */
    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): array;
}