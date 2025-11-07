<?php

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Facade\Pillar;
use Pillar\Snapshot\SnapshotStore;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('saves a snapshot with the last aggregate version after commit', function () {
    /**
     * In-memory snapshot store that persists aggregate + version so
     * EventStoreRepository::find() can load snapshots and avoid re-saving.
     */
    $fake = new class implements SnapshotStore {
        /** @var array<string, array{aggregate: object, snapshot_version: int}> */
        public array $store = [];
        /** @var int[] versions we recorded for assertions */
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

        public function delete(AggregateRootId $id): void
        {
        }
    };

    // Swap our fake into the container
    app()->instance(SnapshotStore::class, $fake);

    $id = DocumentId::new();
    $doc = Document::create($id, 'v0');

    $s = Pillar::session();
    $s->add($doc);

    // two events in a single commit: created and rename
    $doc->rename('v1');
    $s->commit();

    // created=1, rename=2 → snapshot saved once with version 2
    expect($fake->saved)->toBe([2]);

    // Another change → snapshot should save version 3
    $s2 = Pillar::session();
    $a2 = $s2->find($id);
    $a2->rename('v2');
    $s2->commit();

    expect($fake->saved)->toBe([2, 3]);
});