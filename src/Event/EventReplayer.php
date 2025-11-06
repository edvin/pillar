<?php

namespace Pillar\Event;

use Carbon\CarbonImmutable;
use Generator;
use Pillar\Aggregate\AggregateRootId;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use InvalidArgumentException;

/**
 * Replays historical domain events for rebuilding projections.
 *
 * Supports filtering by aggregate, event type, global sequence window, and/or occurred_at date window (UTC).
 * Sequence and date bounds are inclusive.
 * Listener map is maintained as projectors-only; ContextLoader populates it via registerProjector().
 */
final class EventReplayer
{
    /**
     * @param EventStore $eventStore        The event store to stream events from.
     * @param array<class-string, array<class-string>> $projectors Mapping of event FQCN → list of projector class names (must implement Projector).
     */
    public function __construct(
        private readonly EventStore $eventStore,
        private array $projectors = []
    )
    {
    }

    /**
     * Register a projector for a given event class.
     */
    public function registerProjector(string $eventClass, string $projectorClass): void
    {
        $list = $this->projectors[$eventClass] ?? [];
        if (!in_array($projectorClass, $list, true)) {
            $list[] = $projectorClass;
            $this->projectors[$eventClass] = $list;
        }
    }

    /**
     * Replay events matching optional filters.
     *
     * Bounds are inclusive. Date strings are parsed as UTC (ISO-8601 or anything Carbon parses).
     *
     * @param AggregateRootId|null $aggregateId Restrict to a single aggregate (or null for all).
     * @param string|null          $eventType    Restrict to a single event class (FQCN), or null for all.
     * @param int|null             $fromSequence Lower bound on global sequence (inclusive).
     * @param int|null             $toSequence   Upper bound on global sequence (inclusive).
     * @param string|null          $fromDate     Lower bound on occurred_at (inclusive), UTC ISO-8601.
     * @param string|null          $toDate       Upper bound on occurred_at (inclusive), UTC ISO-8601.
     *
     * @throws InvalidArgumentException when a lower bound is greater than its upper bound.
     * @throws Throwable if a listener throws during replay.
     */
    public function replay(
        ?AggregateRootId $aggregateId = null,
        ?string          $eventType = null,
        ?int             $fromSequence = null,
        ?int             $toSequence = null,
        ?string          $fromDate = null,
        ?string          $toDate = null
    ): void
    {
        $this->validateRanges($fromSequence, $toSequence, $fromDate, $toDate);

        $events = $this->eventStore->all($aggregateId, $eventType);
        $filtered = $this->filterEvents($events, $fromSequence, $toSequence, $fromDate, $toDate);
        $count = $this->replayEvents($filtered);

        if ($count === 0) {
            throw new RuntimeException('No events found for replay.');
        }
    }

    /**
     * Stream events matching optional filters. Bounds are inclusive; dates are parsed as UTC.
     *
     * @param AggregateRootId|null $aggregateId Restrict to a single aggregate (or null for all).
     * @param string|null          $eventType    Restrict to a single event class (FQCN), or null for all.
     * @param int|null             $fromSequence Lower bound on global sequence (inclusive).
     * @param int|null             $toSequence   Upper bound on global sequence (inclusive).
     * @param string|null          $fromDate     Lower bound on occurred_at (inclusive), UTC ISO-8601 or Carbon-parseable.
     * @param string|null          $toDate       Upper bound on occurred_at (inclusive), UTC ISO-8601 or Carbon-parseable.
     *
     * @return Generator<StoredEvent>
     */
    public function stream(
        ?AggregateRootId $aggregateId = null,
        ?string          $eventType = null,
        ?int             $fromSequence = null,
        ?int             $toSequence = null,
        ?string          $fromDate = null,
        ?string          $toDate = null
    ): Generator {
        $this->validateRanges($fromSequence, $toSequence, $fromDate, $toDate);
        $events = $this->eventStore->all($aggregateId, $eventType);
        return $this->filterEvents($events, $fromSequence, $toSequence, $fromDate, $toDate);
    }

    /**
     * Validate sequence and date ranges.
     *
     * @throws InvalidArgumentException
     */
    private function validateRanges(
        ?int $fromSequence,
        ?int $toSequence,
        ?string $fromDate,
        ?string $toDate
    ): void {
        if ($fromSequence !== null && $toSequence !== null && $fromSequence > $toSequence) {
            throw new InvalidArgumentException('fromSequence must be less than or equal to toSequence');
        }
        if ($fromDate !== null && $toDate !== null) {
            $fromTs = CarbonImmutable::parse($fromDate, 'UTC');
            $toTs   = CarbonImmutable::parse($toDate, 'UTC');
            if ($fromTs->gt($toTs)) {
                throw new InvalidArgumentException('fromDate must be earlier than or equal to toDate');
            }
        }
    }

    /**
     * Stream-filter events by global sequence and occurred_at windows.
     *
     * NOTE: We can short-circuit on `toSequence` (events are globally ordered by sequence),
     * but we **cannot** short-circuit on `toDate` because timestamp order may not strictly
     * follow global sequence across all producers; we skip instead of breaking.
     *
     * @param iterable<StoredEvent> $events
     * @return Generator<StoredEvent>
     */
    private function filterEvents(
        iterable $events,
        ?int     $fromSequence,
        ?int     $toSequence,
        ?string  $fromDate,
        ?string  $toDate
    ): Generator
    {
        $fromTs = $fromDate ? CarbonImmutable::parse($fromDate, 'UTC') : null;
        $toTs = $toDate ? CarbonImmutable::parse($toDate, 'UTC') : null;

        foreach ($events as $e) {
            if ($fromSequence !== null && $e->sequence < $fromSequence) {
                continue;
            }
            if ($toSequence !== null && $e->sequence > $toSequence) {
                break; // safe because events are ordered by global sequence
            }

            if ($fromTs || $toTs) {
                $evtTs = CarbonImmutable::parse($e->occurredAt, 'UTC');
                if ($fromTs && $evtTs->lt($fromTs)) {
                    continue;
                }
                if ($toTs && $evtTs->gt($toTs)) {
                    continue; // can't break reliably on date because order is by sequence, not timestamp
                }
            }

            yield $e;
        }
    }

    /**
     * Dispatch each stored event to its registered projectors.
     * Initializes EventContext with the event's occurredAt and correlationId.
     *
     * @param iterable<StoredEvent> $events
     * @return int Number of events processed.
     */
    private function replayEvents(iterable $events): int
    {
        $count = 0;

        foreach ($events as $storedEvent) {
            ++$count;

            EventContext::initialize($storedEvent->occurredAt, $storedEvent->correlationId);

            $eventClass = $storedEvent->event::class;
            $listeners = $this->projectors[$eventClass] ?? [];

            foreach ($listeners as $listenerClass) {
                $listener = App::make($listenerClass);
                Log::info("🎬 Replaying $storedEvent->eventType → $listenerClass");
                $listener($storedEvent->event);
            }

            EventContext::clear();
        }

        return $count;
    }
}
