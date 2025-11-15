<?php

use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Aggregate\RecordsEvents;
use Pillar\Snapshot\CacheSnapshotStore;
use Pillar\Aggregate\AggregateRootId;
use Illuminate\Support\Facades\Cache;

it('no-ops save() when aggregate is not Snapshottable', function () {
    // Fake aggregate: extends AggregateRoot but does NOT implement Snapshottable
    $id = GenericAggregateId::new();
    $aggregate = new class($id) implements EventSourcedAggregateRoot {
        use RecordsEvents;

        public function __construct(private AggregateRootId $id) {}
        public function id(): AggregateRootId { return $this->id; }
    };

    Cache::spy();

    $store = new CacheSnapshotStore();
    $store->save($aggregate, 123);

    // Early-return path means no snapshot write happens
    Cache::shouldNotHaveReceived('put');
});

it('load() returns null and skips cache when aggregate is not Snapshottable', function () {
    // GenericAggregateId maps to a non-Snapshottable aggregate, so load() should short-circuit
    $id = GenericAggregateId::new();

    Cache::spy();

    $store = new CacheSnapshotStore();
    $result = $store->load($id);

    expect($result)->toBeNull();
    Cache::shouldNotHaveReceived('get');
});
