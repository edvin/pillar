<?php

use Pillar\Event\EventStore;
use Pillar\Outbox\Worker\WorkerIdentity;
use Pillar\Outbox\Worker\WorkerRegistry;
use Pillar\Outbox\Worker\WorkerRunner;
use Pillar\Outbox\Lease\PartitionLeaseStore;
use Pillar\Outbox\Outbox;
use Pillar\Outbox\Partitioner;
use Illuminate\Contracts\Events\Dispatcher;

function makeWorkerRunnerForTest(string $workerId, int $partitionCount = 6): WorkerRunner
{
    return new WorkerRunner(
        app(WorkerRegistry::class),
        app(PartitionLeaseStore::class),
        app(Outbox::class),
        app(EventStore::class),
        app(Dispatcher::class),
        $workerId,
        app(Partitioner::class),
        false,
        $partitionCount,
        10,
        60,
        30,
        10,
    );
}

function makeLeasingWorkerRunnerForTest(string $workerId, int $partitionCount = 6): WorkerRunner
{
    return new WorkerRunner(
        app(WorkerRegistry::class),
        app(PartitionLeaseStore::class),
        app(Outbox::class),
        app(EventStore::class),
        app(Dispatcher::class),
        $workerId,
        app(Partitioner::class),
        true,
        $partitionCount,
        10,
        60,
        30,
        10,
    );
}

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

it('distributes partitions across workers based on index', function () {
    $runner = makeWorkerRunnerForTest('worker-b', 6);

    $ref = new ReflectionClass(WorkerRunner::class);
    $method = $ref->getMethod('targetPartitionsFromWorkers');
    $method->setAccessible(true);

    $partitioner = app(Partitioner::class);

    $workers = ['worker-c', 'worker-a', 'worker-b'];

    /** @var string[] $parts */
    $parts = $method->invoke($runner, $workers);

    $expected = [
        $partitioner->labelForIndex(2),
        $partitioner->labelForIndex(5),
    ];

    expect($parts)->toBe($expected);
});

it('returns no partitions when partition count is zero', function () {
    $runner = makeWorkerRunnerForTest('any-worker', 0);

    $ref = new ReflectionClass(WorkerRunner::class);
    $method = $ref->getMethod('targetPartitionsFromWorkers');
    $method->setAccessible(true);

    /** @var string[] $parts */
    $parts = $method->invoke($runner, ['w1', 'w2']);

    expect($parts)->toBe([]);
});

it('derives target partitions for a single active worker', function () {
    $runner = makeWorkerRunnerForTest('worker-b', 6);

    $ref = new ReflectionClass(WorkerRunner::class);
    $forMe = $ref->getMethod('targetPartitionsForMe');
    $forMe->setAccessible(true);

    /** @var string[] $parts */
    $parts = $forMe->invoke($runner);

    $partitioner = app(Partitioner::class);
    $expected = [];
    for ($i = 0; $i < 6; $i++) {
        $expected[] = $partitioner->labelForIndex($i);
    }

    expect($parts)->toBe($expected);
});

it('truncates long strings with an ellipsis', function () {
    $runner = makeWorkerRunnerForTest('truncate-worker');

    $ref = new ReflectionClass(WorkerRunner::class);
    $method = $ref->getMethod('truncate');
    $method->setAccessible(true);

    $lenFn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
    $substrFn = function_exists('mb_substr') ? 'mb_substr' : 'substr';

    $short = 'short';
    $shortResult = $method->invoke($runner, $short, 10);

    expect($shortResult)->toBe($short);

    $long = str_repeat('x', 20);
    $longResult = $method->invoke($runner, $long, 10);

    expect($lenFn($longResult))->toBe(10)
        ->and($substrFn($longResult, -1))->toBe('…');
});

it('formats exceptions using shortError()', function () {
    $runner = makeWorkerRunnerForTest('error-worker');

    $ref = new ReflectionClass(WorkerRunner::class);
    $method = $ref->getMethod('shortError');
    $method->setAccessible(true);

    $lenFn = function_exists('mb_strlen') ? 'mb_strlen' : 'strlen';
    $substrFn = function_exists('mb_substr') ? 'mb_substr' : 'substr';

    $ex = new RuntimeException('boom');
    $formatted = $method->invoke($runner, $ex);

    expect($formatted)->toBe('RuntimeException: boom');

    $longMessage = str_repeat('y', 300);
    $exLong = new RuntimeException($longMessage);
    $formattedLong = $method->invoke($runner, $exLong);

    expect(str_starts_with($formattedLong, 'RuntimeException: '))
        ->and($lenFn($formattedLong))->toBeLessThan($lenFn('RuntimeException: ' . $longMessage))
        ->and($substrFn($formattedLong, -1))->toBe('…');
});

it('applies idle backoff when no messages are processed', function () {
    $runner = makeWorkerRunnerForTest('idle-worker');

    $outboxTable = config('pillar.outbox.tables.outbox', 'outbox');
    DB::table($outboxTable)->truncate();

    $result = $runner->tick();

    expect($result->claimed)->toBe(0)
        ->and($result->published)->toBe(0)
        ->and($result->failed)->toBe(0)
        ->and($result->backoffMs)->toBe(10);
});

it('leases partitions when leasing is enabled and keeps them on subsequent ticks', function () {
    $runner = makeLeasingWorkerRunnerForTest('leasing-worker', 6);

    $leaseTable = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    DB::table($leaseTable)->truncate();

    // First tick should acquire leases
    $first = $runner->tick();

    expect($first->desiredPartitions)->not->toBe([])
        ->and($first->leasedPartitions)->not->toBe([])
        ->and($first->releasedPartitions)->toBe([]);

    // Second tick should see the same ownership but no new leases
    $second = $runner->tick();

    expect($second->desiredPartitions)->toBe($first->desiredPartitions)
        ->and($second->releasedPartitions)->toBe([]);
});

it('releases partitions when more workers join and shrink the target set', function () {
    $leaseTable = config('pillar.outbox.tables.partitions', 'outbox_partitions');
    $workersTable = config('pillar.outbox.tables.workers', 'outbox_workers');

    DB::table($leaseTable)->truncate();
    DB::table($workersTable)->truncate();

    // Seed partitions like the OutboxPartitionSyncCommand would
    $leases = app(PartitionLeaseStore::class);
    $partitioner = app(Partitioner::class);
    $partitionCnt = (int)config('pillar.outbox.partition_count', 16);

    $want = [];
    for ($i = 0; $i < max(0, $partitionCnt); $i++) {
        $want[] = $partitioner->labelForIndex($i);
    }

    if (method_exists($leases, 'seed')) {
        $leases->seed($want);
    }

    /** @var WorkerRegistry $registry */
    $registry = app(WorkerRegistry::class);

    // Runner for worker-a with leasing turned on
    $runner = makeLeasingWorkerRunnerForTest('worker-a', 6);

    // First tick: only worker-a exists, it should own some partitions
    $first = $runner->tick();
    $firstOwned = $first->ownedPartitions;

    expect($firstOwned)->not->toBe([]);

    // Now add a second worker – the modulo distribution changes
    $w2 = new WorkerIdentity('worker-b', 'host-b', 2222);
    $registry->join($w2);

    // Force a renew so we actually run the lease sync again on the next tick
    $ref = new \ReflectionClass(\Pillar\Outbox\Worker\WorkerRunner::class);
    $propLastRenew = $ref->getProperty('lastRenewNs');
    $propLastRenew->setAccessible(true);
    $propLastRenew->setValue($runner, 0);

    // Second tick: worker-a should release some partitions and keep a subset
    $second = $runner->tick();

    expect($second->ownedPartitions)->not->toBe($firstOwned);
});

