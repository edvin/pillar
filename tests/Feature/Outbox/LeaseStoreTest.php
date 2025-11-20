<?php
declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Pillar\Logging\PillarLogger;
use Pillar\Outbox\Lease\DatabasePartitionLeaseStore;

function part(string $key): ?array
{
    $row = DB::table('outbox_partitions')->where('partition_key', $key)->first();
    return $row ? (array)$row : null;
}

it('seeds partitions idempotently', function () {
    $table = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    $store = new DatabasePartitionLeaseStore($table, app(PillarLogger::class));

    $store->seed(['p00', 'p01', 'p01']);     // duplicates
    expect(DB::table('outbox_partitions')->count())->toBe(2);

    $store->seed(['p01', 'p02']);            // add one new key
    expect(DB::table('outbox_partitions')->count())->toBe(3)
        ->and(part('p00'))->not->toBeNull()
        ->and(part('p01'))->not->toBeNull()
        ->and(part('p02'))->not->toBeNull();

});

it('tryLease acquires free and renews own lease without bumping epoch', function () {
    $table = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    $store = new DatabasePartitionLeaseStore($table, app(PillarLogger::class));
    $store->seed(['p00']);

    // Acquire fresh
    $ok = $store->tryLease(['p00'], 'w1', 30);
    expect($ok)->toBeTrue();
    $row1 = part('p00');
    expect($row1['lease_owner'])->toBe('w1')
        ->and((int)$row1['lease_epoch'])->toBe(1)
        ->and($row1['lease_until'])->not->toBeNull();

    // Renew same owner: should not bump epoch
    $ok2 = $store->tryLease(['p00'], 'w1', 30);
    expect($ok2)->toBeTrue();
    $row2 = part('p00');
    expect($row2['lease_owner'])->toBe('w1')
        ->and((int)$row2['lease_epoch'])->toBe(1); // unchanged
});

it('tryLease does not steal from another owner unless expired', function () {
    $table = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    $store = new DatabasePartitionLeaseStore($table, app(PillarLogger::class));
    $store->seed(['p01']);

    // w1 takes it with a healthy TTL
    $store->tryLease(['p01'], 'w1', 60);
    $stolen = $store->tryLease(['p01'], 'w2', 60);
    expect($stolen)->toBeFalse()
        ->and(part('p01')['lease_owner'])->toBe('w1');

    // Now synthesize expiry by using a negative ttl for a new lease attempt by w1,
    // which sets lease_until in the past (keeps epoch semantics consistent).
    $store->tryLease(['p01'], 'w1', -5);
    // Another worker can now take it and epoch should bump
    $taken = $store->tryLease(['p01'], 'w2', 60);
    expect($taken)->toBeTrue();
    $row = part('p01');
    expect($row['lease_owner'])->toBe('w2')
        ->and((int)$row['lease_epoch'])->toBeGreaterThanOrEqual(2);
});

it('renew extends only when owner matches', function () {
    $table = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    $store = new DatabasePartitionLeaseStore($table, app(PillarLogger::class));
    $store->seed(['p02']);

    $store->tryLease(['p02'], 'w1', 30);
    $renewOther = $store->renew(['p02'], 'w2', 60);
    expect($renewOther)->toBeFalse();

    $renewSelf = $store->renew(['p02'], 'w1', 60);
    expect($renewSelf)->toBeTrue()
        ->and(part('p02')['lease_owner'])->toBe('w1');
});

it('ownedBy returns active leases and respects the optional filter', function () {
    $table = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    $store = new DatabasePartitionLeaseStore($table, app(PillarLogger::class));
    $store->seed(['p00', 'p01', 'p02', 'p03']);

    $store->tryLease(['p00', 'p02'], 'w1', 60);
    $store->tryLease(['p01'], 'w2', 60);
    // Make p02 expired for w1 (negative TTL)
    $store->tryLease(['p02'], 'w1', -1);

    // Without filter: p00 is active, p02 expired → not returned
    $owned = $store->ownedBy('w1');
    expect($owned)->toEqualCanonicalizing(['p00']);

    // With filter (includes a non-owned key and the expired one)
    $filtered = $store->ownedBy('w1', ['p00', 'p02', 'p03']);
    expect($filtered)->toEqualCanonicalizing(['p00']);

    // Empty filter should behave as no filter
    $same = $store->ownedBy('w1', []);
    expect($same)->toEqualCanonicalizing(['p00']);
});

it('release frees selected leases and ignores empty input', function () {
    $table = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    $store = new DatabasePartitionLeaseStore($table, app(PillarLogger::class));
    $store->seed(['p10', 'p11']);

    $store->tryLease(['p10', 'p11'], 'wZ', 30);

    // No-op path
    $store->release([], 'wZ');
    expect(part('p10')['lease_owner'])->toBe('wZ');

    // Release one
    $store->release(['p10'], 'wZ');
    expect(part('p10')['lease_owner'])->toBeNull()
        ->and(part('p11')['lease_owner'])->toBe('wZ');
});

it('does nothing when seeding with an empty list', function () {
    $table = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    $store = app(DatabasePartitionLeaseStore::class);

    // Sanity: table exists and is empty
    expect(DB::getSchemaBuilder()->hasTable($table))->toBeTrue()
        ->and(DB::table($table)->count())->toBe(0);

    // Act
    $store->seed([]);

    // Assert: still empty (early return path hit)
    expect(DB::table($table)->count())->toBe(0);
});

it('pruneObsolete removes only keys outside keep that are unleased or expired', function () {
    $table = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    $store = new DatabasePartitionLeaseStore($table, app(PillarLogger::class));
    $store->seed(['p00', 'p01', 'p02', 'p03']);

    // p00 leased and active
    $store->tryLease(['p00'], 'w1', 60);

    // p01 leased but expired (negative ttl)
    $store->tryLease(['p01'], 'wX', -5);

    // p02 never leased (unleased)
    // p03 never leased (unleased)

    // Empty keep → nothing (guard path)
    $none = $store->pruneObsolete([]);
    expect($none)->toBe(0);

    // Keep p00 and p02; p01 is expired and p03 is unleased → both get pruned
    $deleted = $store->pruneObsolete(['p00', 'p02']);
    expect($deleted)->toBe(2)
        ->and(part('p00'))->not->toBeNull()
        ->and(part('p02'))->not->toBeNull()
        ->and(part('p01'))->toBeNull()
        ->and(part('p03'))->toBeNull();

    // Verify survivors
});

it('guards: empty inputs return early', function () {
    $table = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    $store = new DatabasePartitionLeaseStore($table, app(PillarLogger::class));

    expect($store->tryLease([], 'w', 10))->toBeFalse()
        ->and($store->renew([], 'w', 10))->toBeFalse();

    // release([]) is a no-op; just ensure it does not throw
    $store->release([], 'w');

    // pruneObsolete([]) returns 0 (covered above but keep explicit)
    expect($store->pruneObsolete([]))->toBe(0);
});