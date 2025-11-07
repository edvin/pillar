<?php

use Pillar\Aggregate\GenericAggregateId;
use Pillar\Snapshot\CacheSnapshotStore;

it('throws if id->aggregateClass() is not an AggregateRoot', function () {
    $store = new CacheSnapshotStore();
    $id = GenericAggregateId::new();

    expect(fn() => $store->load($id))
        ->toThrow(InvalidArgumentException::class);
});