<?php
// tests/Feature/Event/EventStoreRepositoryBranchTest.php

use Illuminate\Contracts\Events\Dispatcher;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Event\EventStore;
use Pillar\Event\EventWindow;
use Pillar\Event\StoredEvent;
use Pillar\Repository\EventStoreRepository;
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\SnapshotStore;

it('throws when aggregateClass is not an EventSourcedAggregateRoot', function () {
    // Snapshot store returns no snapshot
    $snapshots = Mockery::mock(SnapshotStore::class);
    $snapshots->shouldReceive('load')->once()->andReturn(null);

    $policy = Mockery::mock(SnapshotPolicy::class);

    // Minimal stub EventStore that returns an empty iterator for load()
    $events = new class implements EventStore {
        public function append($id, object $event, ?int $expectedSequence = null): int { return 0; }
        public function resolveAggregateIdClass(string $aggregateId): ?string { return null; }

        public function all(?AggregateRootId $aggregateId = null, ?EventWindow $window = null, ?string $eventType = null): Generator
        {
            yield null;
        }

        public function load(AggregateRootId $id, ?EventWindow $window = null): Generator
        {
            yield null;
        }

        public function getByGlobalSequence(int $sequence): ?StoredEvent
        {
            return null;
        }
    };

    $repo = new EventStoreRepository(
        snapshotPolicy: $policy,
        snapshots: $snapshots,
        eventStore: $events,
        dispatcher: app(Dispatcher::class),
        optimisticLocking: false,
    );

    // ID that resolves to a non-event-sourced aggregate (stdClass)
    $badId = new readonly class(Str::uuid()) extends GenericAggregateId {
        public function __construct(string $raw) { parent::__construct($raw); }
        public static function aggregateClass(): string { return \stdClass::class; }
    };

    expect(fn () => $repo->find($badId))->toThrow(LogicException::class);
});