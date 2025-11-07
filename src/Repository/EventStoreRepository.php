<?php

namespace Pillar\Repository;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;
use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EphemeralEvent;
use Pillar\Event\EventStore;
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\SnapshotStore;
use Throwable;

final readonly class EventStoreRepository implements AggregateRepository
{
    public function __construct(
        private SnapshotPolicy $snapshotPolicy,
        private SnapshotStore  $snapshots,
        private EventStore     $eventStore,
        #[Config('pillar.event_store.options.optimistic_locking', false)]
        private bool           $optimisticLocking,
    )
    {
    }

    /** @throws Throwable */
    public function save(AggregateRoot $aggregate, ?int $expectedVersion = null): void
    {
        DB::transaction(function () use ($aggregate, $expectedVersion) {
            $lastSeq = null;
            $delta = 0;
            $expected = $this->optimisticLocking ? $expectedVersion : null;

            foreach ($aggregate->recordedEvents() as $event) {
                if ($event instanceof EphemeralEvent) {
                    continue;
                }

                $lastSeq = $this->eventStore->append($aggregate->id(), $event, $expected);
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
        });
    }

    public function find(AggregateRootId $id): ?LoadedAggregate
    {
        $snapshot = $this->snapshots->load($id);

        $aggregate = null;
        $after = 0;

        if ($snapshot) {
            $aggregate = $snapshot['aggregate'];
            $after = $snapshot['snapshot_version'] ?? 0;
        }

        $events = $this->eventStore->load($id, $after);

        if (!$aggregate) {
            /** @var AggregateRoot $aggregate */
            $aggregate = new ($id->aggregateClass());
        }

        $aggregate->markAsReconstituting();

        $hadEvents = false;
        $lastSeq = null;
        foreach ($events as $storedEvent) {
            $hadEvents = true;
            $aggregate->apply($storedEvent->event);
            $lastSeq = $storedEvent->aggregateSequence;
        }

        $aggregate->markAsNotReconstituting();

        if (!$snapshot && !$hadEvents) {
            // No snapshot and no events to rebuild from
            return null;
        }

        // Set the aggregate's persisted version (prefer events applied; otherwise snapshot version)
        $persistedVersion = (int)($lastSeq ?? $after);

        if ($hadEvents && $lastSeq !== null) {
            $prevSeq = (int)$after;                 // version at snapshot time (0 if none)
            $newSeq = (int)$lastSeq;               // version after replaying new events
            $delta = max(0, $newSeq - $prevSeq);   // number of applied events

            if ($this->snapshotPolicy->shouldSnapshot($aggregate, $newSeq, $prevSeq, $delta)) {
                $this->snapshots->save($aggregate, $newSeq);
            }
        }

        return new LoadedAggregate($aggregate, $persistedVersion);
    }
}