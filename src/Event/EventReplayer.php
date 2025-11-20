<?php

namespace Pillar\Event;

use Carbon\CarbonImmutable;
use Generator;
use Pillar\Aggregate\AggregateRegistry;
use Illuminate\Support\Facades\App;
use RuntimeException;
use Throwable;
use InvalidArgumentException;
use Pillar\Metrics\Metrics;
use Pillar\Metrics\Counter;
use Pillar\Logging\PillarLogger;

/**
 * Replays historical domain events for rebuilding projections.
 *
 * Supports filtering by aggregate, event type, global sequence window, and/or occurred_at date window (UTC).
 * Sequence and date bounds are inclusive.
 * Listener map is maintained as projectors-only; ContextLoader populates it via registerProjector().
 */
final class EventReplayer
{
    private Counter $replayStartedCounter;
    private Counter $replayProcessedCounter;
    private Counter $replayFailedCounter;

    /**
     * @param EventStore $eventStore The event store to stream events from.
     * @param array<class-string, array<class-string>> $projectors Mapping of event FQCN → list of projector class names (must implement Projector).
     */
    public function __construct(
        private PillarLogger        $logger,
        private readonly EventStore $eventStore,
        Metrics                     $metrics,
        private array               $projectors = [],
    )
    {
        $this->replayStartedCounter = $metrics->counter(
            'replay_started_total'
        );

        $this->replayProcessedCounter = $metrics->counter(
            'replay_events_processed_total'
        );

        $this->replayFailedCounter = $metrics->counter(
            'replay_failed_total'
        );
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
     * @param string|null $streamId Restrict to a single stream_id (or null for all).
     * @param string|null $eventType Restrict to a single event class (FQCN), or null for all.
     * @param int|null $fromSequence Lower bound on global sequence (inclusive).
     * @param int|null $toSequence Upper bound on global sequence (inclusive).
     * @param string|null $fromDate Lower bound on occurred_at (inclusive), UTC ISO-8601.
     * @param string|null $toDate Upper bound on occurred_at (inclusive), UTC ISO-8601.
     *
     * @throws InvalidArgumentException when a lower bound is greater than its upper bound.
     * @throws Throwable if a listener throws during replay.
     */
    public function replay(
        ?string $streamId = null,
        ?string $eventType = null,
        ?int    $fromSequence = null,
        ?int    $toSequence = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): void
    {
        $this->validateRanges($fromSequence, $toSequence, $fromDate, $toDate);
        $this->replayStartedCounter->inc();

        $context = [
            'stream_id'     => $streamId,
            'event_type'    => $eventType,
            'from_sequence' => $fromSequence,
            'to_sequence'   => $toSequence,
            'from_date'     => $fromDate,
            'to_date'       => $toDate,
        ];

        $this->logger->info('pillar.replay.started', $context);

        $events = $this->baseStream($streamId, $eventType);
        $filtered = $this->filterEvents($events, $fromSequence, $toSequence, $fromDate, $toDate);
        $count = $this->replayEvents($filtered);

        if ($count === 0) {
            $this->logger->warning('pillar.replay.no_events', $context);
            throw new RuntimeException('No events found for replay.');
        }

        $this->logger->info('pillar.replay.completed', $context + [
            'events_processed' => $count,
        ]);
    }

    /**
     * Stream events matching optional filters. Bounds are inclusive; dates are parsed as UTC.
     *
     * @param string|null $streamId Restrict to a single stream_id (or null for all).
     * @param string|null $eventType Restrict to a single event class (FQCN), or null for all.
     * @param int|null $fromSequence Lower bound on global sequence (inclusive).
     * @param int|null $toSequence Upper bound on global sequence (inclusive).
     * @param string|null $fromDate Lower bound on occurred_at (inclusive), UTC ISO-8601 or Carbon-parseable.
     * @param string|null $toDate Upper bound on occurred_at (inclusive), UTC ISO-8601 or Carbon-parseable.
     *
     * @return Generator<StoredEvent>
     */
    public function stream(
        ?string $streamId = null,
        ?string $eventType = null,
        ?int    $fromSequence = null,
        ?int    $toSequence = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): Generator
    {
        $this->validateRanges($fromSequence, $toSequence, $fromDate, $toDate);
        $events = $this->baseStream($streamId, $eventType);
        return $this->filterEvents($events, $fromSequence, $toSequence, $fromDate, $toDate);
    }

    /**
     * Validate sequence and date ranges.
     *
     * @throws InvalidArgumentException
     */
    private function validateRanges(
        ?int    $fromSequence,
        ?int    $toSequence,
        ?string $fromDate,
        ?string $toDate
    ): void
    {
        if ($fromSequence !== null && $toSequence !== null && $fromSequence > $toSequence) {
            throw new InvalidArgumentException('fromSequence must be less than or equal to toSequence');
        }
        if ($fromDate !== null && $toDate !== null) {
            $fromTs = CarbonImmutable::parse($fromDate, 'UTC');
            $toTs = CarbonImmutable::parse($toDate, 'UTC');
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
     * Resolve the base event stream from the store, choosing between per-stream and global streaming.
     * When a streamId is provided, we use streamFor(); otherwise we use the global stream().
     * If both streamId and eventType are provided, we apply the type filter in-memory.
     *
     * @param string|null $streamId
     * @param string|null $eventType
     * @return iterable<StoredEvent>
     */
    private function baseStream(?string $streamId, ?string $eventType): iterable
    {
        if ($streamId !== null) {
            /** @var AggregateRegistry $registry */
            $registry = app(AggregateRegistry::class);
            $aggregateId = $registry->idFromStreamName($streamId);

            $events = $this->eventStore->streamFor($aggregateId);

            if ($eventType !== null) {
                return $this->filterByEventType($events, $eventType);
            }

            return $events;
        }

        // Global scan; the store can natively optimize by event type.
        return $this->eventStore->stream(null, $eventType);
    }

    /**
     * Filter a stream of stored events by event type (FQCN or alias).
     *
     * @param iterable<StoredEvent> $events
     * @param string $eventType
     * @return Generator<StoredEvent>
     */
    private function filterByEventType(iterable $events, string $eventType): Generator
    {
        foreach ($events as $storedEvent) {
            if ($storedEvent->eventType === $eventType || $storedEvent->event::class === $eventType) {
                yield $storedEvent;
            }
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
            $this->replayProcessedCounter->inc();

            EventContext::initialize(
                occurredAt: $storedEvent->occurredAt,
                correlationId: $storedEvent->correlationId,
                reconstituting: true,
                replaying: true
            );

            $eventClass = $storedEvent->event::class;
            $listeners = $this->projectors[$eventClass] ?? [];

            foreach ($listeners as $listenerClass) {
                $listener = App::make($listenerClass);
                $this->logger->debug('pillar.replay.dispatch', [
                    'event_type' => $storedEvent->eventType,
                    'projector'  => $listenerClass,
                    'sequence'   => $storedEvent->sequence ?? null,
                ]);
                // @codeCoverageIgnoreStart
                try {
                    $listener($storedEvent->event);
                } catch (Throwable $e) {
                    $this->logger->error('pillar.replay.handler_failed', [
                        'event_type' => $storedEvent->eventType,
                        'projector'  => $listenerClass,
                        'sequence'   => $storedEvent->sequence ?? null,
                        'exception'  => $e,
                    ]);
                    $this->replayFailedCounter->inc();
                    throw $e;
                }
                // @codeCoverageIgnoreEnd
            }

            EventContext::clear();
        }

        return $count;
    }
}
