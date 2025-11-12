<?php

use Pillar\Outbox\Worker\WorkerIdentity;
use Pillar\Outbox\Worker\WorkerRegistry;

it('removes the worker row on leave()', function () {
    $table = config('pillar.outbox.tables.workers', 'outbox_workers');
    $registry = app(WorkerRegistry::class);

    $w = new WorkerIdentity('wrkr-leave-test', 'test-host', 4242);

    // Join inserts/updates the row
    $registry->join($w);

    expect(DB::table($table)->where('id', $w->id)->exists())->toBeTrue();
    expect($registry->activeIds())->toContain($w->id);

    // leave() deletes it
    $registry->leave($w);

    expect(DB::table($table)->where('id', $w->id)->exists())->toBeFalse();
    expect($registry->activeIds())->not->toContain($w->id);

    // Idempotent: calling again does nothing (and does not error)
    $registry->leave($w);
    expect(DB::table($table)->where('id', $w->id)->exists())->toBeFalse();
});

it('only removes the specified worker, keeping others', function () {
    $table = config('pillar.outbox.tables.workers', 'outbox_workers');
    $registry = app(WorkerRegistry::class);

    $w1 = new WorkerIdentity('wrkr-1', 'host-1', 1111);
    $w2 = new WorkerIdentity('wrkr-2', 'host-2', 2222);

    $registry->join($w1);
    $registry->join($w2);

    // Sanity
    expect(DB::table($table)->where('id', $w1->id)->exists())->toBeTrue();
    expect(DB::table($table)->where('id', $w2->id)->exists())->toBeTrue();

    // Remove only w1
    $registry->leave($w1);

    expect(DB::table($table)->where('id', $w1->id)->exists())->toBeFalse();
    expect(DB::table($table)->where('id', $w2->id)->exists())->toBeTrue();

    $ids = $registry->activeIds();
    expect($ids)->not->toContain($w1->id);
    expect($ids)->toContain($w2->id);
});