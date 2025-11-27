<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Pillar\Logging\PillarLogger;
use Pillar\Metrics\Metrics;
use Pillar\Snapshot\CacheSnapshotStore;
use Pillar\Snapshot\Snapshot;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('saves snapshot to cache when ttl is null', function () {
    Config::set('pillar.snapshot.ttl', null);
    Cache::spy();

    $store = new CacheSnapshotStore(app(PillarLogger::class), null, app(Metrics::class));

    $id = DocumentId::new();
    $aggregate = Document::create($id, 'v0');

    $store->save($id, 5, $aggregate->toSnapshot());

    // We don’t care about exact args here, just that cache is written once
    Cache::shouldHaveReceived('put')->once();
});

it('saves snapshot to cache with a concrete ttl', function () {
    Config::set('pillar.snapshot.ttl', 60);
    Cache::spy();

    $store = new CacheSnapshotStore(app(PillarLogger::class), 60, app(Metrics::class));

    $id = DocumentId::new();
    $aggregate = Document::create($id, 'v0');

    $store->save($id, 7, $aggregate->toSnapshot());

    // Assert ttl is not null and is some kind of DateTime
    Cache::shouldHaveReceived('put')
        ->once()
        ->withArgs(function ($key, $payload, $ttl) {
            // Key + payload shape are implicitly covered; we just ensure TTL is set
            return $ttl instanceof DateTimeInterface;
        });
});

it('returns null on cache miss for a Snapshottable aggregate', function () {
    Config::set('pillar.snapshot.ttl', null);
    Cache::spy(); // default array store is empty → get() returns null

    $store = new CacheSnapshotStore(app(PillarLogger::class), null, app(Metrics::class));

    $id = DocumentId::new();

    $result = $store->load($id);

    expect($result)->toBeNull();
    Cache::shouldHaveReceived('get')->once();
});

it('hydrates snapshot from a cached payload', function () {
    // Use the real cache store (array driver in tests)
    Config::set('pillar.snapshot.ttl', null);

    $store = new CacheSnapshotStore(app(PillarLogger::class), null, app(Metrics::class));

    $id = DocumentId::new();
    $aggregate = Document::create($id, 'initial');

    $payload = [
        'data' => $aggregate->toSnapshot(),
        'snapshot_version' => 42,
        'snapshot_created_at' => now('UTC')->format('Y-m-d H:i:s'),
    ];

    // Mirror CacheSnapshotStore::cacheKey() logic
    $key = sprintf(
        'snapshot:%s:%s',
        str_replace('\\', '.', Document::class),
        $id->value()
    );

    // Seed the cache as if CacheSnapshotStore::save() had run
    Cache::put($key, $payload, null);

    $snapshot = $store->load($id);

    expect($snapshot)
        ->toBeInstanceOf(Snapshot::class)
        ->and($snapshot->version)->toBe(42);
});


it('forgets snapshot from cache on delete', function () {
    Config::set('pillar.snapshot.ttl', null);
    Cache::spy();

    $store = new CacheSnapshotStore(app(PillarLogger::class), null, app(Metrics::class));

    $id = DocumentId::new();

    $store->delete($id);

    Cache::shouldHaveReceived('forget')->once();
});