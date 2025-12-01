<?php

use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\AggregateSession;
use Pillar\Facade\Pillar;
use Pillar\Repository\EventStoreRepository;
use Pillar\Repository\RepositoryResolver;
use Pillar\Snapshot\CacheSnapshotStore;
use Pillar\Snapshot\CadenceSnapshotPolicy;
use Pillar\Snapshot\DelegatingSnapshotPolicy;
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\SnapshotStore;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('returns null when the aggregate does not exist', function () {
    // No events/snapshots exist for this brand-new ID
    $id = DocumentId::new();

    $session = Pillar::session();
    $found = $session->find($id);

    expect($found)->toBeNull();
});

it('rebuilds from events when snapshot is missing, then re-saves a snapshot', function () {
    $id = DocumentId::new();

    config()->set('pillar.snapshot', [
        'policy' => [
            'class' => CadenceSnapshotPolicy::class,
            'options' => [
                'threshold' => 1,
                'offset' => 0,
            ]
        ],
        'overrides' => [],
        'store' => ['class' => CacheSnapshotStore::class],
        'ttl' => null,
    ]);

    app()->forgetInstance(SnapshotStore::class);
    app()->forgetInstance(SnapshotPolicy::class);
    app()->forgetInstance(DelegatingSnapshotPolicy::class);
    app()->forgetInstance(EventStoreRepository::class);
    app()->forgetInstance(RepositoryResolver::class);
    app()->forgetInstance(AggregateSession::class);

    // Produce a small stream: created (1) + rename(2) + rename(3)
    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0'));
    $s->commit();

    foreach (['v1', 'v2'] as $t) {
        $sx = Pillar::session();
        $a = $sx->find($id);
        $a->rename($t);
        $sx->commit();
    }

    /** @var SnapshotStore $store */
    $store = app(SnapshotStore::class);

    // Ensure a snapshot exists first so delete() is meaningful
    $existing = $store->load($id);
    expect($existing)->not()->toBeNull();

    // Remove snapshot via the store API (don’t flush the cache directly)
    $store->delete($id);

    // Now find → must rebuild from events (no snapshot) and then save a fresh one
    $reloaded = Pillar::session()->find($id);
    expect($reloaded->title())->toBe('v2');

    $after = $store->load($id);
    expect($after)->not()->toBeNull()
        ->and($after->version)->toBe(3); // last applied aggregate sequence
});