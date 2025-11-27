<?php

use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Aggregate\RecordsEvents;
use Pillar\Logging\PillarLogger;
use Pillar\Snapshot\CacheSnapshotStore;
use Pillar\Aggregate\AggregateRootId;
use Illuminate\Support\Facades\Cache;
use Pillar\Snapshot\DatabaseSnapshotStore;
use Illuminate\Support\Facades\DB;
use Pillar\Snapshot\SnapshotStore;
use Illuminate\Support\Facades\Config;

dataset('snapshotStores', [
    'database' => DatabaseSnapshotStore::class,
    'cache' => CacheSnapshotStore::class,
]);

it('no-ops save() when aggregate is not Snapshottable', function (string $storeClass) {
    // Fake aggregate: extends AggregateRoot but does NOT implement Snapshottable
    $id = GenericAggregateId::new();
    $aggregate = new class($id) implements EventSourcedAggregateRoot {
        use RecordsEvents;

        public function __construct(private AggregateRootId $id) {}
        public function id(): AggregateRootId { return $this->id; }
    };

    Cache::spy();
    DB::spy();

    // Configure and rebind the snapshot store implementation for this test case
    Config::set('pillar.snapshot.store.class', $storeClass);
    app()->forgetInstance(SnapshotStore::class);
    app()->singleton(SnapshotStore::class, $storeClass);

    /** @var SnapshotStore $store */
    $store = app(SnapshotStore::class);
    $store->save($aggregate->id(), 123, []);

    // Early-return path means no snapshot write happens
    if ($storeClass === CacheSnapshotStore::class) {
        Cache::shouldNotHaveReceived('put');
    }

    if ($storeClass === DatabaseSnapshotStore::class) {
        DB::shouldNotHaveReceived('table');
    }
})->with('snapshotStores');

it('load() returns null and skips cache when aggregate is not Snapshottable', function (string $storeClass) {
    // GenericAggregateId maps to a non-Snapshottable aggregate, so load() should short-circuit
    $id = GenericAggregateId::new();

    Cache::spy();
    DB::spy();

    // Configure and rebind the snapshot store implementation for this test case
    Config::set('pillar.snapshot.store.class', $storeClass);
    app()->forgetInstance(SnapshotStore::class);
    app()->singleton(SnapshotStore::class, $storeClass);

    /** @var SnapshotStore $store */
    $store = app(SnapshotStore::class);
    $result = $store->load($id);

    expect($result)->toBeNull();
    if ($storeClass === CacheSnapshotStore::class) {
        Cache::shouldNotHaveReceived('get');
    }

    if ($storeClass === DatabaseSnapshotStore::class) {
        DB::shouldNotHaveReceived('table');
    }
})->with('snapshotStores');
