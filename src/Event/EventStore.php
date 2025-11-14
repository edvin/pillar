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
     * Uses optimistic concurrency when $expectedSequence is provided: the append
     * will only succeed if the current last per-aggregate version equals the expected value.
     *
     * @param AggregateRootId $id The identifier of the aggregate root.
     * @param object $event The event to append.
     * @param int|null $expectedSequence Expected per-aggregate version (aggregate_sequence) before appending, or null to skip the check.
     * @return int The per-aggregate version (aggregate_sequence) assigned to the appended event.
     */
    public function append(AggregateRootId $id, object $event, ?int $expectedSequence = null): int;

    /**
     * Stream events for a single aggregate within an optional window.
     *
     * The window can express both a START cursor (“after …”) and an END bound (“until …”):
     *  - “after*” fields are **exclusive** (start strictly after the given cursor)
     *  - “to* / until*” fields are **inclusive** (stop at or before the given bound)
     *
     * Ordering:
     *  - Implementations MUST yield events in ascending per-aggregate sequence order.
     *
     * Defaults:
     *  - When $window is null, the entire event history for $id is streamed.
     *  - When $window has only an “after*”, stream from that cursor to the end.
     *  - When $window has only a “to*”, stream from the beginning up to that bound.
     *
     * Validation:
     *  - At most one “after*” and at most one “to*” field should be set.
     *  - If the window is contradictory (e.g., after >= to for the same dimension),
     *    the implementation SHOULD return an empty stream.
     *
     * Performance notes:
     *  - Backends SHOULD translate window predicates into native storage filters
     *    (e.g., WHERE aggregate_sequence > :after AND aggregate_sequence <= :to),
     *    but may also fetch-and-filter when necessary.
     *
     * Examples:
     *  - EventWindow::afterAggSeq(42)            // start after per-aggregate seq 42
     *  - EventWindow::untilAggSeq(100)           // up to and including seq 100
     *  - EventWindow::betweenAggSeq(42, 100)     // (42, 100]
     *  - EventWindow::untilGlobal(12345)         // all events whose global seq ≤ 12345
     *  - EventWindow::untilDate(new DateTimeImmutable('2025-01-01T00:00:00Z'))
     *
     * @param AggregateRootId $id Aggregate identifier.
     * @param EventWindow|null $window Optional event window (start/stop cursors).
     * @return Generator<StoredEvent>  Generator yielding stored events.
     */
    public function load(AggregateRootId $id, ?EventWindow $window = null): Generator;

    /**
     * Scan events across the whole store (optionally filtered) in **global order**.
     *
     * Semantics of $window:
     * - If $aggregateId is **null**, only the *global* bounds are applied
     *   (afterGlobalSequence / toGlobalSequence / toDateUtc). Per-aggregate bounds
     *   are ignored in this mode.
     * - If $aggregateId is **provided**, both *per-aggregate* and *global/time*
     *   bounds may be applied (afterAggregateSequence / toAggregateSequence, etc).
     *
     * Implementations MUST yield events in ascending global sequence order.
     *
     * @param AggregateRootId|null $aggregateId (optional filter)
     * @param EventWindow|null $window
     * @param string|null $eventType alias or FQCN (optional filter)
     * @return Generator<StoredEvent>
     */
    public function all(
        ?AggregateRootId $aggregateId = null,
        ?EventWindow     $window = null,
        ?string          $eventType = null
    ): Generator;

    /**
     * Fetch a single stored event by its global, monotonically increasing sequence number.
     *
     * Semantics:
     * - The sequence uniquely identifies an event across the whole store and totally orders
     *   events across aggregates.
     * - Implementations SHOULD treat the sequence as an immutable primary key (e.g. the
     *   `sequence` column in the database-backed store).
     *
     * Returns:
     * - A fully materialized {@see StoredEvent} (deserialized domain object + metadata),
     *   or null if no event exists with the given sequence.
     *
     * @param int $sequence Global sequence number of the event to fetch.
     * @return StoredEvent|null
     */
    public function getByGlobalSequence(int $sequence): ?StoredEvent;

    /**
     * Resolve the AggregateRootId class (FQCN) for a raw aggregate identifier.
     *
     * Purpose:
     * - Enables UIs/tools to reconstruct a strongly-typed ID instance, for example
     *   `$class::from($aggregateId)`, without requiring the caller to supply the class explicitly.
     *
     * Contract:
     * - Return the fully-qualified class-string of the {@see AggregateRootId}
     *   implementation, or null if the store cannot determine it.
     * - Implementations MAY consult store-specific metadata (e.g., a mapping table, stream
     *   metadata, headers) and SHOULD avoid expensive scans.
     * - This is a pure lookup; implementations MUST NOT instantiate the ID here.
     *
     * Error handling:
     * - Return null when the mapping is unknown/unavailable; do not throw for “not found”.
     *
     * @param string $aggregateId Raw aggregate identifier as persisted in the store.
     * @return class-string<AggregateRootId>|null
     */
    public function resolveAggregateIdClass(string $aggregateId): ?string;

    /**
     * Fetch the most recently updated aggregates from the store.
     *
     * Semantics:
     * - Each returned {@see StoredEvent} MUST be the latest (most recent) event
     *   for its aggregate (i.e. highest per-aggregate sequence for that aggregate).
     * - No two entries in the result MAY belong to the same aggregateId.
     * - The list MUST be ordered by the *global* sequence of those latest events
     *   in descending order (most recently updated aggregate first).
     * - The `$limit` is a hard upper bound on the *number of distinct aggregates*
     *   returned.
     *
     * Usage:
     * - Intended for dashboards and monitoring UIs that need to show
     *   “the N most recently active aggregates” without having to stream and
     *   de-duplicate the entire history via {@see all()}.
     *
     * Performance notes:
     * - Implementations SHOULD push the aggregation, ordering and limit down to
     *   the storage layer when possible (e.g. window functions, DISTINCT ON, or
     *   grouped subqueries), rather than loading all events and post-processing
     *   in memory.
     *
     * @param int $limit Maximum number of distinct aggregates to return.
     * @return array<int, StoredEvent> A list of the latest event per aggregate, most recent first.
     */
    public function recent(int $limit): array;
}