<?php

namespace Pillar\Repository;

use Pillar\Aggregate\AggregateRoot;
use Pillar\Event\EphemeralEvent;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventStore;
use Pillar\Snapshot\SnapshotStore;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EventStoreRepository implements AggregateRepository
{
    public function __construct(
        private readonly SnapshotStore $snapshots,
        private readonly EventStore    $eventStore
    )
    {
    }

    /** @throws Throwable */
    public function save(AggregateRoot $aggregate): void
    {
        DB::transaction(function () use ($aggregate) {
            $lastSeq = null;

            foreach ($aggregate->recordedEvents() as $event) {
                if ($event instanceof EphemeralEvent) {
                    continue;
                }

                $lastSeq = $this->eventStore->append($aggregate->id(), $event);
            }

            if ($lastSeq !== null) {
                $this->snapshots->save($aggregate, $lastSeq);
            }
        });
    }

    public function find(AggregateRootId $id): ?AggregateRoot
    {
        $aggregateClass = $id->aggregateClass();
        $snapshot = $this->snapshots->load($aggregateClass, $id);

        $aggregate = null;
        $after = 0;

        if ($snapshot) {
            $aggregate = $snapshot['aggregate'];
            $after = $snapshot['snapshot_version'] ?? 0;
        }

        $events = $this->eventStore->load($id, $after);

        if (!$snapshot && empty($events)) {
            return null;
        }

        if (!$aggregate) {
            /** @var AggregateRoot $aggregate */
            $aggregate = new $aggregateClass();
        }

        $aggregate->markAsReconstituting();

        foreach ($events as $storedEvent) {
            $aggregate->apply($storedEvent->event);
        }

        $aggregate->markAsNotReconstituting();

        if (!empty($events)) {
            $lastSeq = end($events)->sequence;
            $this->snapshots->save($aggregate, $lastSeq);
        }

        return $aggregate;
    }

    public function exists(AggregateRootId $id): bool
    {
        return $this->eventStore->exists($id);
    }

}