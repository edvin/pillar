<?php

declare(strict_types=1);

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Aggregate\RecordsEvents;
use Pillar\Event\EventStore;
use Pillar\Event\EventWindow;
use Pillar\Event\StoredEvent;
use Pillar\Snapshot\Snapshot;
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\SnapshotStore;

/**
 * Minimal fake aggregate used for snapshot reconstitution in this test.
 */
final class __FakeAggregate implements EventSourcedAggregateRoot
{
    use RecordsEvents;

    public function __construct(private AggregateRootId $id)
    {
    }

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
    public function load(AggregateRootId $id): ?Snapshot
    {
        return new Snapshot(new __FakeAggregate($id), 0);
    }

    public function save(AggregateRootID $id, int $sequence, array $payload): void
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
        yield from [];
    }

    public function all(?AggregateRootId $aggregateId = null, ?EventWindow $window = null, ?string $eventType = null): Generator
    {
        yield from [];
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
        return null;
    }

    public function stream(?EventWindow $window = null, ?string $eventType = null): Generator
    {
        yield from [];
        return null;
    }
}