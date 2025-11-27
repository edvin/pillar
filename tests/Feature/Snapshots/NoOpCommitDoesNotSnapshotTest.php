<?php

use Pillar\Aggregate\AggregateRoot;
use Pillar\Facade\Pillar;
use Pillar\Snapshot\Snapshot;
use Pillar\Snapshot\SnapshotStore;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Pillar\Aggregate\AggregateRootId;

it('does not save a snapshot when committing with no new events', function () {
    // Fake in-memory snapshot store that persists and returns snapshots
    $fake = new class implements SnapshotStore {
        /** @var array<string, Snapshot}> */
        public array $store = [];
        /** @var int[] */
        public array $saved = [];

        public function load(AggregateRootId $id): ?Snapshot
        {
            return $this->store[$id->value()] ?? null;
        }
        public function save(AggregateRootId $id, int $sequence, array $payload): void
        {
            $aggregate = $id->aggregateClass()::fromSnapshot($payload);
            $this->store[$id->value()] = new Snapshot($aggregate, $sequence);
            $this->saved[] = $sequence;
        }
        public function delete(AggregateRootId $id): void {}
    };

    app()->instance(SnapshotStore::class, $fake);

    $id  = DocumentId::new();

    // First commit creates aggregate → snapshot once at 1
    $s0 = Pillar::session();
    $s0->attach(Document::create($id, 'v0'));
    $s0->commit();

    expect($fake->saved)->toBe([1]);

    // New session, load from snapshot, commit without changes → no new snapshot
    $s1 = Pillar::session();
    $a1 = $s1->find($id); // loads from snapshot, applies no events
    $s1->commit();        // should NOT call SnapshotStore::save

    expect($fake->saved)->toBe([1]);
});