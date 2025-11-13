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
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\SnapshotStore;
use Throwable;

final readonly class EventStoreRepository implements AggregateRepository
{
    public function __construct(
        private SnapshotPolicy $snapshotPolicy,
        private SnapshotStore  $snapshots,
        private EventStore     $eventStore,
        private Dispatcher     $dispatcher,
        #[Config('pillar.event_store.options.optimistic_locking', false)]
        private bool           $optimisticLocking,
    )
    {
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
                if (!EventContext::isReplaying() && $event instanceof ShouldPublishInline) {
                    $this->dispatcher->dispatch($event);
                }
                $delta++;
                if ($expected !== null) {
                    $expected = $lastSeq;
                }
            }

            if ($lastSeq !== null) {
                $prevSeq = ($this->optimisticLocking && $expectedVersion !== null)
                    ? $expectedVersion
                    : max(0, $lastSeq - $delta);

                if ($this->snapshotPolicy->shouldSnapshot($aggregate, $lastSeq, $prevSeq, $delta)) {
                    $this->snapshots->save($aggregate, $lastSeq);
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
        $snapshot = $this->snapshots->load($id);

        $aggregate = null;
        $snapshotVersion = 0;

        if ($snapshot) {
            $snapshotVersion = (int)($snapshot['snapshot_version'] ?? 0);
        }

        // Caller’s requested starting cursor (defaults to 0)
        $requestedAfter = $window?->afterAggregateSequence ?? 0;

        // Use snapshot only if it is at/after the requested start; otherwise rebuild earlier state
        if ($snapshot && $snapshotVersion >= $requestedAfter) {
            $aggregate = $snapshot['aggregate'];
            $after = $snapshotVersion;
        } else {
            $after = $requestedAfter;
        }

        // Effective window: start after the chosen cursor, carry any upper bounds
        $effectiveWindow = $window
            ? new EventWindow(
                afterAggregateSequence: $after,
                toAggregateSequence: $window->toAggregateSequence,
                toGlobalSequence: $window->toGlobalSequence,
                toDateUtc: $window->toDateUtc,
            )
            : EventWindow::afterAggSeq($after);

        $events = $this->eventStore->load($id, $effectiveWindow);

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
            $lastSeq = $storedEvent->aggregateSequence;
        }

        EventContext::clear();

        if (!$snapshot && !$hadEvents) {
            // No snapshot and no events to rebuild from
            return null;
        }

        // Persisted version = last applied event version (or the chosen "after" when none applied)
        $persistedVersion = $lastSeq ?? $after;

        // Only snapshot when building the latest (no upper bound)
        $isLatest = ($window === null)
            || ($window->toAggregateSequence === null
                && $window->toGlobalSequence === null
                && $window->toDateUtc === null);

        if ($isLatest && $hadEvents && $lastSeq !== null) {
            $prevSeq = $after;     // version at starting point (0 or snapshot version)
            $newSeq = $lastSeq;   // version after applying events in window
            $delta = max(0, $newSeq - $prevSeq);

            if ($this->snapshotPolicy->shouldSnapshot($aggregate, $newSeq, $prevSeq, $delta)) {
                $this->snapshots->save($aggregate, $newSeq);
            }
        }

        return new LoadedAggregate($aggregate, $persistedVersion);
    }
}