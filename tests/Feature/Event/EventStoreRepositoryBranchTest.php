<?php
// tests/Feature/Event/EventStoreRepositoryBranchTest.php

use Illuminate\Contracts\Events\Dispatcher;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Event\EventStore;
use Pillar\Event\EventWindow;
use Pillar\Event\StoredEvent;
use Pillar\Logging\PillarLogger;
use Pillar\Metrics\Metrics;
use Pillar\Repository\EventStoreRepository;
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\SnapshotStore;

it('throws when aggregateClass is not an EventSourcedAggregateRoot', function () {
    // Snapshot store is never consulted when the aggregate class is invalid
    $snapshots = Mockery::mock(SnapshotStore::class);

    $policy = Mockery::mock(SnapshotPolicy::class);

    // Minimal stub EventStore that returns an empty iterator for load()
    $events = new class implements EventStore {
        public function append($id, object $event, ?int $expectedSequence = null): int
        {
            return 0;
        }

        public function getByGlobalSequence(int $sequence): ?StoredEvent
        {
            return null;
        }

        public function recent(int $limit): array
        {
            return [];
        }

        public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator
        {
            yield from [];
        }

        public function stream(?EventWindow $window = null, ?string $eventType = null): Generator
        {
            yield from [];
        }
    };

    $repo = new EventStoreRepository(
        logger: app(PillarLogger::class),
        snapshotPolicy: $policy,
        snapshots: $snapshots,
        eventStore: $events,
        dispatcher: app(Dispatcher::class),
        optimisticLocking: false,
        snapshotMode: 'inline',
        snapshotConnection: 'default',
        snapshotQueue: 'default',
        metrics: app(Metrics::class),

    );

    // ID that resolves to a non-event-sourced aggregate (stdClass)
    $badId = new readonly class(Str::uuid()) extends GenericAggregateId {
        public function __construct(string $raw)
        {
            parent::__construct($raw);
        }

        public static function aggregateClass(): string
        {
            return stdClass::class;
        }
    };

    expect(fn() => $repo->find($badId))->toThrow(LogicException::class);
});