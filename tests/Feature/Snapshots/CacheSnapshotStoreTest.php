<?php

use Pillar\Aggregate\GenericAggregateId;
use Pillar\Snapshot\CacheSnapshotStore;
use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;

it('throws if id->aggregateClass() is not an AggregateRoot', function () {
    $store = new CacheSnapshotStore();
    $id = GenericAggregateId::new();

    expect(fn() => $store->load($id))
        ->toThrow(InvalidArgumentException::class);
});

it('no-ops save() when aggregate is not Snapshottable', function () {
    // Fake aggregate: extends AggregateRoot but does NOT implement Snapshottable
    $id = GenericAggregateId::new();
    $aggregate = new class($id) extends AggregateRoot {
        public function __construct(private AggregateRootId $id) {}
        public function id(): AggregateRootId { return $this->id; }
    };

    Cache::spy();

    $store = new CacheSnapshotStore();
    $store->save($aggregate, 123);

    // Early-return path means no snapshot write happens
    Cache::shouldNotHaveReceived('put');
});
