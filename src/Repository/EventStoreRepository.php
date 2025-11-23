<?php

namespace Pillar\Repository;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;
use LogicException;
use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Event\EventContext;
use Pillar\Event\EventStore;
use Pillar\Event\EventWindow;
use Pillar\Event\ShouldPublishInline;
use Pillar\Logging\PillarLogger;
use Pillar\Metrics\Counter;
use Pillar\Metrics\Metrics;
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\SnapshotStore;
use Pillar\Snapshot\Snapshottable;
use Throwable;

final readonly class EventStoreRepository implements AggregateRepository
{
    private Counter $eventStoreAppendsCounter;
    private Counter $eventStoreReadsCounter;
    private Counter $snapshotLoadCounter;
    private Counter $snapshotSaveCounter;

    public function __construct(
        private PillarLogger   $logger,
        private SnapshotPolicy $snapshotPolicy,
        private SnapshotStore  $snapshots,
        private EventStore     $eventStore,
        private Dispatcher     $dispatcher,
        #[Config('pillar.event_store.options.optimistic_locking', false)]
        private bool           $optimisticLocking,
        Metrics                $metrics,
    )
    {
        $this->eventStoreAppendsCounter = $metrics->counter(
            'eventstore_appends_total',
            ['aggregate_type']
        );

        $this->eventStoreReadsCounter = $metrics->counter(
            'eventstore_reads_total',
            ['aggregate_type', 'found']
        );

        $this->snapshotLoadCounter = $metrics->counter(
            'eventstore_snapshot_load_total',
            ['aggregate_type', 'hit']
        );

        $this->snapshotSaveCounter = $metrics->counter(
            'eventstore_snapshot_save_total',
            ['aggregate_type']
        );
    }

    /** @throws Throwable */
    public function save(AggregateRoot $aggregate, ?int $expectedVersion = null): void
    {
        if (!$aggregate instanceof EventSourcedAggregateRoot) {
            throw new LogicException(sprintf(
                '%s can only save EventSourcedAggregateRoot; got %s',
                __CLASS__,
                get_debug_type($aggregate)
            ));
        }

        $work = function () use ($aggregate, $expectedVersion) {
            $lastSeq = null;
            $delta = 0;
            $expected = $this->optimisticLocking ? $expectedVersion : null;

            foreach ($aggregate->recordedEvents() as $event) {
                $lastSeq = $this->eventStore->append($aggregate->id(), $event, $expected);
                $this->eventStoreAppendsCounter->inc(1, [
                    'aggregate_type' => $aggregate::class,
                ]);
                if (!EventContext::isReplaying() && $event instanceof ShouldPublishInline) {
                    $this->dispatcher->dispatch($event);
                }
                $delta++;
                if ($expected !== null) {
                    $expected = $lastSeq;
                }
            }

            if ($lastSeq !== null && $aggregate instanceof Snapshottable) {
                $prevSeq = ($this->optimisticLocking && $expectedVersion !== null)
                    ? $expectedVersion
                    : max(0, $lastSeq - $delta);

                if ($this->snapshotPolicy->shouldSnapshot($aggregate, $lastSeq, $prevSeq, $delta)) {
                    $this->snapshots->save($aggregate, $lastSeq);
                    $this->logger->debug('pillar.eventstore.snapshot_saved', [
                        'aggregate_type' => $aggregate::class,
                        'aggregate_id' => (string)$aggregate->id(),
                        'seq' => $lastSeq,
                    ]);
                    $this->snapshotSaveCounter->inc(1, [
                        'aggregate_type' => $aggregate::class,
                    ]);
                } else {
                    $this->logger->debug('pillar.eventstore.snapshot_skipped', [
                        'aggregate_type' => $aggregate::class,
                        'aggregate_id' => (string)$aggregate->id(),
                        'last_seq' => $lastSeq,
                    ]);
                }
            }
        };

        if (DB::transactionLevel() > 0) {
            $work();
        } else {
            DB::transaction($work);
        }

        DB::afterCommit(fn() => $aggregate->clearRecordedEvents());
    }

    public function find(AggregateRootId $id, ?EventWindow $window = null): ?LoadedAggregate
    {
        $aggregateClass = $id::aggregateClass();

        if (!is_subclass_of($aggregateClass, EventSourcedAggregateRoot::class)) {
            throw new LogicException(sprintf(
                '%s can only load EventSourcedAggregateRoot; got %s',
                __CLASS__,
                $aggregateClass,
            ));
        }

        $snapshot = $this->snapshots->load($id);

        $this->snapshotLoadCounter->inc(1, [
            'aggregate_type' => $aggregateClass,
            'hit' => $snapshot ? 'true' : 'false',
        ]);

        $this->logger->debug('pillar.eventstore.snapshot_load', [
            'aggregate_type' => $aggregateClass,
            'aggregate_id' => (string)$id,
            'hit' => $snapshot ? 'true' : 'false',
        ]);

        $aggregate = null;

        // Caller’s requested starting cursor (defaults to 0)
        $requestedAfter = $window?->afterStreamSequence ?? 0;

        // Use snapshot only if it is at/after the requested start; otherwise rebuild earlier state
        if ($snapshot && $snapshot->version >= $requestedAfter) {
            $aggregate = $snapshot->aggregate;
            $after = $snapshot->version;
        } else {
            $after = $requestedAfter;
        }

        // Effective window: always start after $after, carry any upper bounds from the caller
        $effectiveWindow = new EventWindow(
            afterStreamSequence: $after,
            toStreamSequence: $window?->toStreamSequence,
            toGlobalSequence: $window?->toGlobalSequence,
            toDateUtc: $window?->toDateUtc,
        );

        $events = $this->eventStore->streamFor($id, $effectiveWindow);

        if (!$aggregate) {
            /** @var AggregateRoot $aggregate */
            $aggregate = new ($id->aggregateClass());
        }

        // @codeCoverageIgnoreStart
        if (!$aggregate instanceof EventSourcedAggregateRoot) {
            throw new LogicException(sprintf(
                '%s can only load EventSourcedAggregateRoot; got %s',
                __CLASS__,
                get_debug_type($aggregate)
            ));
        }
        // @codeCoverageIgnoreEnd

        $hadEvents = false;
        $lastSeq = null;
        foreach ($events as $storedEvent) {
            $hadEvents = true;
            EventContext::initialize(
                occurredAt: $storedEvent->occurredAt,
                correlationId: $storedEvent->correlationId,
                reconstituting: true,
            );
            $aggregate->apply($storedEvent->event);
            $lastSeq = $storedEvent->streamSequence;
        }

        $this->eventStoreReadsCounter->inc(1, [
            'aggregate_type' => $aggregateClass,
            'found' => (!$snapshot && !$hadEvents) ? 'false' : 'true',
        ]);

        EventContext::clear();

        if (!$snapshot && !$hadEvents) {
            // No snapshot and no events to rebuild from
            return null;
        }

        // Persisted version = last applied event version (or the chosen "after" when none applied)
        $persistedVersion = $lastSeq ?? $after;

        // Only snapshot when building the latest (no upper bound)
        $isLatest = ($window === null)
            || ($window->toStreamSequence === null
                && $window->toGlobalSequence === null
                && $window->toDateUtc === null);

        if ($isLatest && $hadEvents && $lastSeq !== null) {
            $prevSeq = $after;     // version at starting point (0 or snapshot version)
            $newSeq = $lastSeq;   // version after applying events in window
            $delta = max(0, $newSeq - $prevSeq);

            if ($this->snapshotPolicy->shouldSnapshot($aggregate, $newSeq, $prevSeq, $delta)) {
                $this->snapshots->save($aggregate, $newSeq);
                $this->logger->debug('pillar.eventstore.snapshot_saved', [
                    'aggregate_type' => $aggregateClass,
                    'aggregate_id' => (string)$id,
                    'seq' => $newSeq,
                ]);
                $this->snapshotSaveCounter->inc(1, [
                    'aggregate_type' => $aggregateClass,
                ]);
            }
        }

        return new LoadedAggregate($aggregate, $persistedVersion);
    }
}