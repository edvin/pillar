<?php

use Pillar\Aggregate\AggregateRoot;
use Pillar\Facade\Pillar;
use Pillar\Snapshot\SnapshotStore;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Pillar\Aggregate\AggregateRootId;

it('does not save a snapshot when committing with no new events', function () {
    // Fake in-memory snapshot store that persists and returns snapshots
    $fake = new class implements SnapshotStore {
        /** @var array<string, array{aggregate: AggregateRoot, snapshot_version: int}> */
        public array $store = [];
        /** @var int[] */
        public array $saved = [];

        public function load(AggregateRootId $id): ?array
        {
            return $this->store[$id->value()] ?? null;
        }
        public function save(AggregateRoot $aggregate, int $sequence): void
        {
            $this->store[$aggregate->id()->value()] = [
                'aggregate' => $aggregate,
                'snapshot_version' => $sequence,
            ];
            $this->saved[] = $sequence;
        }
        public function delete(AggregateRootId $id): void {}
    };

    app()->instance(SnapshotStore::class, $fake);

    $id  = DocumentId::new();

    // First commit creates aggregate → snapshot once at 1
    $s0 = Pillar::session();
    $s0->add(Document::create($id, 'v0'));
    $s0->commit();

    expect($fake->saved)->toBe([1]);

    // New session, load from snapshot, commit without changes → no new snapshot
    $s1 = Pillar::session();
    $a1 = $s1->find($id); // loads from snapshot, applies no events
    $s1->commit();        // should NOT call SnapshotStore::save

    expect($fake->saved)->toBe([1]);
});