<?php

use Pillar\Facade\Pillar;
use Pillar\Snapshot\SnapshotStore;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Pillar\Aggregate\AggregateRootId;

it('does not save a snapshot when committing with no new events', function () {
    // Fake in-memory snapshot store that persists and returns snapshots
    $fake = new class implements SnapshotStore {
        /** @var array<string, array{aggregate: object, snapshot_version: int}> */
        public array $store = [];
        /** @var int[] */
        public array $saved = [];

        public function load(string $aggregateClass, object $id): ?array
        {
            $key = $this->key($aggregateClass, $id);
            return $this->store[$key] ?? null;
        }
        public function save(object $aggregate, int $sequence): void
        {
            $aggregateClass = get_class($aggregate);
            $id = method_exists($aggregate, 'id') ? $aggregate->id() : null;
            $key = $this->key($aggregateClass, $id);
            $this->store[$key] = [
                'aggregate' => $aggregate,
                'snapshot_version' => $sequence,
            ];
            $this->saved[] = $sequence;
        }
        public function clear(string $aggregateClass, object $id): void
        {
            unset($this->store[$this->key($aggregateClass, $id)]);
        }
        public function delete(string $aggregateClass, AggregateRootId $id): void {}
        private function key(string $aggregateClass, ?object $id): string
        {
            $idVal = (method_exists($id, 'value')) ? $id->value() : (string) spl_object_id($id);
            return $aggregateClass.'|'.$idVal;
        }
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