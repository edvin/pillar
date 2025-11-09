<?php

declare(strict_types=1);

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventStore;
use Pillar\Event\EventWindow;
use Pillar\Event\StoredEvent;
use Pillar\Repository\EventStoreRepository;
use Pillar\Repository\LoadedAggregate;
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\SnapshotStore;
use Tests\Fixtures\Document\DocumentId;

/**
 * Minimal fake aggregate used for snapshot reconstitution in this test.
 */
final class __FakeAggregate extends AggregateRoot
{
    public function __construct(private AggregateRootId $id) {}

    public function id(): AggregateRootId
    {
        return $this->id;
    }
}

/**
 * SnapshotStore that always returns a snapshot at version 0
 * with a prebuilt aggregate. This lets the repository take
 * the non-null $window branch while keeping the test fast.
 */
final class __FakeSnapshotStore implements SnapshotStore
{
    public function load(AggregateRootId $id): ?array
    {
        return [
            'aggregate'        => new __FakeAggregate($id),
            'snapshot_version' => 0,
        ];
    }

    public function save(AggregateRoot $aggregate, int $version): void
    {
        // no-op for test
    }

    public function delete(AggregateRootId $id): void
    {
        // no-op for test
    }
}

/**
 * SnapshotPolicy that never forces a save during this test.
 */
final class __NeverSnapshotPolicy implements SnapshotPolicy
{
    public function shouldSnapshot(AggregateRoot $aggregate, int $newSeq, int $prevSeq, int $delta): bool
    {
        return false;
    }
}

/**
 * EventStore stub that captures the EventWindow passed by the repository.
 */
final class __CapturingEventStore implements EventStore
{
    public ?EventWindow $captured = null;

    public function append(AggregateRootId $id, object $event, ?int $expectedSequence = null): int
    {
        return 0;
    }

    public function load(AggregateRootId $id, ?EventWindow $window = null): Generator
    {
        $this->captured = $window;
        if (false) { yield; } // empty generator
    }

    public function all(?AggregateRootId $aggregateId = null, ?EventWindow $window = null, ?string $eventType = null): Generator
    {
        if (false) { yield; }
    }

    public function getByGlobalSequence(int $sequence): ?StoredEvent
    {
        return null;
    }

    public function resolveAggregateIdClass(string $aggregateId): ?string
    {
        return null;
    }
}

it('passes a non-null EventWindow with snapshot-aware after to EventStore::load()', function () {
    $store     = new __CapturingEventStore();
    $snapshots = new __FakeSnapshotStore();
    $policy    = new __NeverSnapshotPolicy();

    // optimisticLocking = false for this unit test
    $repo = new EventStoreRepository($policy, $snapshots, $store, false);

    $id = DocumentId::new();

    // Provide a stopping bound so the repo takes the "non-null window" branch
    $result = $repo->find($id, EventWindow::toAggSeq(5));

    // We got a LoadedAggregate back because a snapshot was present
    expect($result)->toBeInstanceOf(LoadedAggregate::class);

    // And the window that went into EventStore::load() is what we expect:
    // - afterAggregateSequence comes from the snapshot version (0 here)
    // - toAggregateSequence is carried through (5 here)
    expect($store->captured)->not->toBeNull()
        ->and($store->captured->afterAggregateSequence)->toBe(0)
        ->and($store->captured->toAggregateSequence)->toBe(5)
        ->and($store->captured->toGlobalSequence)->toBeNull()
        ->and($store->captured->toDateUtc)->toBeNull();
});