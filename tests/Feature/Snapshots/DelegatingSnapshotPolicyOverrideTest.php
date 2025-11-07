<?php

use Pillar\Facade\Pillar;
use Pillar\Snapshot\SnapshotStore;
use Pillar\Snapshot\SnapshotPolicy;
use Pillar\Snapshot\DelegatingSnapshotPolicy;
use Pillar\Snapshot\OnDemandSnapshotPolicy;
use Pillar\Snapshot\AlwaysSnapshotPolicy;
use Pillar\Snapshot\CacheSnapshotStore;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('uses the per-aggregate override for Document instead of the default policy', function () {
    // Keep original to restore after
    $original = config('pillar.snapshot');

    try {
        // 1) Default = OnDemand → should NOT snapshot
        config()->set('pillar.snapshot', [
            'policy' => ['class' => OnDemandSnapshotPolicy::class, 'options' => []],
            'overrides' => [],
            'store' => ['class' => CacheSnapshotStore::class],
            'ttl' => null,
        ]);

        // Force re-resolve with new config
        app()->forgetInstance(DelegatingSnapshotPolicy::class);
        app()->forgetInstance(SnapshotPolicy::class);

        /** @var SnapshotStore $snap */
        $snap = app(SnapshotStore::class);

        $id1 = DocumentId::new();
        $snap->delete($id1);

        $s1 = Pillar::session();
        $s1->add(Document::create($id1, 'v0'));
        $s1->commit();

        expect($snap->load($id1))->toBeNull(); // no snapshot under OnDemand

// 2) Override Document → Always; default still OnDemand
        config()->set('pillar.snapshot', [
            'policy' => ['class' => OnDemandSnapshotPolicy::class, 'options' => []],
            'overrides' => [
                Document::class => ['class' => AlwaysSnapshotPolicy::class, 'options' => []],
            ],
            'store' => ['class' => CacheSnapshotStore::class],
            'ttl' => null,
        ]);

        // Make sure everyone re-resolves with the new policy
        app()->forgetInstance(DelegatingSnapshotPolicy::class);
        app()->forgetInstance(SnapshotPolicy::class);
        app()->forgetInstance(\Pillar\Repository\EventStoreRepository::class);
        app()->forgetInstance(\Pillar\Repository\RepositoryResolver::class);
        app()->forgetInstance(\Pillar\Aggregate\AggregateSession::class);

        $id2 = DocumentId::new();
        $snap->delete($id2);

        $s2 = Pillar::session();
        $s2->add(Document::create($id2, 'v0'));
        $s2->commit();

        $loaded = $snap->load($id2);
        expect($loaded)->not()->toBeNull()
            ->and($loaded['snapshot_version'])->toBeGreaterThanOrEqual(1);
    } finally {
        // Restore original config and clear policy singletons
        config()->set('pillar.snapshot', $original);
        app()->forgetInstance(DelegatingSnapshotPolicy::class);
        app()->forgetInstance(SnapshotPolicy::class);
    }
});