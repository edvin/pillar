<?php

use Pillar\Aggregate\AggregateRegistry;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventStore;
use Pillar\Event\EventWindow;
use Pillar\Event\StoredEvent;
use Pillar\Metrics\Metrics;
use Pillar\Outbox\Worker\TickResult;
use Pillar\Outbox\Worker\WorkerIdentity;
use Pillar\Outbox\Worker\WorkerRegistry;
use Pillar\Outbox\Worker\WorkerRunner;
use Pillar\Outbox\Lease\PartitionLeaseStore;
use Pillar\Outbox\Outbox;
use Pillar\Outbox\Partitioner;
use Illuminate\Contracts\Events\Dispatcher;
use Tests\Fixtures\Encryption\DummyEvent;

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
        app(AggregateRegistry::class),
        app(Metrics::class),
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
        app(AggregateRegistry::class),
        app(Metrics::class),
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

    /** @var string[] $parts */
    $parts = $method->invoke($runner, ['w1', 'w2']);

    expect($parts)->toBe([]);
});

it('derives target partitions for a single active worker', function () {
    $runner = makeWorkerRunnerForTest('worker-b', 6);

    $ref = new ReflectionClass(WorkerRunner::class);
    $forMe = $ref->getMethod('targetPartitionsForMe');

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
    $propLastRenew->setValue($runner, 0);

    // Second tick: worker-a should release some partitions and keep a subset
    $second = $runner->tick();

    expect($second->ownedPartitions)->not->toBe($firstOwned);
});

it('records failed messages and trims lastErrors when dispatching throws', function () {
    /**
     * Simple message DTO that looks like what the Outbox would normally return.
     * We only need a globalSequence property for WorkerRunner.
     */
    $messages = [];
    for ($i = 1; $i <= 6; $i++) {
        $messages[] = (object)[
            'globalSequence' => $i,
        ];
    }

    /**
     * Fake Outbox that returns a fixed batch once and records markFailed calls.
     * Adjust method list to match your Outbox interface if needed.
     */
    $fakeOutbox = new class($messages) implements Outbox {
        public array $failed = [];
        private array $messages;

        public function __construct(array $messages)
        {
            $this->messages = $messages;
        }

        public function claimPending(int $limit = 100, array $partitions = []): iterable
        {
            // Return all messages once, then nothing
            $out = $this->messages;
            $this->messages = [];
            return $out;
        }

        public function markPublished(object $message): void
        {
            // not used in this test
        }

        public function markFailed(object $message, Throwable $error): void
        {
            $this->failed[] = [$message, $error];
        }

        public function enqueue(int $globalSequence, ?string $partition = null): void
        {
        }
    };

    /**
     * Fake EventStore that always returns a StoredEvent for the requested global sequence.
     * We only need getByGlobalSequence() for this test.
     */
    $fakeStore = new class implements EventStore {
        public function append($id, object $event, ?int $expectedSequence = null): int
        {
            return 0;
        }

        public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator
        {
            yield from [];
        }

        public function stream(?EventWindow $window = null, ?string $eventType = null): Generator
        {
            yield from [];
        }

        public function getByGlobalSequence(int $sequence): ?StoredEvent
        {
            // Use any event type you like here; DummyEvent is fine if you have it.
            return new StoredEvent(
                event: new DummyEvent((string)$sequence, 'E' . $sequence),
                sequence: $sequence,
                streamSequence: $sequence,
                streamId: 'test-' . $sequence,
                eventType: DummyEvent::class,
                storedVersion: 1,
                eventVersion: 1,
                occurredAt: now('UTC')->format('Y-m-d H:i:s'),
                correlationId: null,
            );
        }

        public function recent(int $limit): array
        {
            return [];
        }
    };

    // Dispatcher that always throws, so we hit the catch-block for every message.
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('boom'));

    $runner = new WorkerRunner(
        app(WorkerRegistry::class),
        app(PartitionLeaseStore::class),
        $fakeOutbox,
        $fakeStore,
        $dispatcher,
        'error-worker',
        app(Partitioner::class),
        app(AggregateRegistry::class),
        app(Metrics::class),
        false,   // leasing off – we don’t care about partitions here
        10,      // partition count (ignored by our fake outbox)
        10,      // batch size (>= 6 so we can process all messages)
        60,
        30,
        10,
    );

    /** @var TickResult $result */
    $result = $runner->tick();

    // All 6 messages failed
    expect($result->failed)->toBe(6);

    // lastErrors keeps only the last 5 entries after trimming
    expect($result->lastErrors)->toHaveCount(5);

    // Check that the last error corresponds to the last sequence (6)
    $last = $result->lastErrors[array_key_last($result->lastErrors)];
    expect($last['seq'])->toBe(6);

    // And Outbox::markFailed was called for each message
    expect($fakeOutbox->failed)->toHaveCount(6);
});

it('marks messages as failed when stored event is missing', function () {
    // One message whose stored event will be missing
    $messages = [
        (object)['globalSequence' => 42],
    ];

    // Fake Outbox: returns the message once, tracks markFailed calls
    $fakeOutbox = new class($messages) implements Outbox {
        public array $failed = [];
        private array $messages;

        public function __construct(array $messages)
        {
            $this->messages = $messages;
        }

        public function claimPending(int $limit = 100, array $partitions = []): iterable
        {
            $out = $this->messages;
            $this->messages = [];
            return $out;
        }

        public function markPublished(object $message): void
        {
            // not used in this test
        }

        public function markFailed(object $message, Throwable $error): void
        {
            $this->failed[] = [$message, $error];
        }

        public function enqueue(int $globalSequence, ?string $partition = null): void
        {
            // not needed here
        }
    };

    // Fake EventStore: getByGlobalSequence() always returns null
    $fakeStore = new class implements EventStore {
        public function append($id, object $event, ?int $expectedSequence = null): int
        {
            return 0;
        }

        public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator
        {
            yield from [];
        }

        public function stream(?EventWindow $window = null, ?string $eventType = null): Generator
        {
            yield from [];
        }

        public function getByGlobalSequence(int $sequence): ?StoredEvent
        {
            // Simulate missing event
            return null;
        }

        public function recent(int $limit): array
        {
            return [];
        }
    };

    // Dispatcher is never reached (we throw before dispatch), so a noop mock is fine
    $dispatcher = Mockery::mock(Dispatcher::class);

    $runner = new WorkerRunner(
        app(WorkerRegistry::class),
        app(PartitionLeaseStore::class),
        $fakeOutbox,
        $fakeStore,
        $dispatcher,
        'missing-event-worker',
        app(Partitioner::class),
        app(AggregateRegistry::class),
        app(Metrics::class),
        false,   // leasing off
        10,
        10,
        60,
        30,
        10,
    );

    /** @var TickResult $result */
    $result = $runner->tick();

    // One claimed, one failed, nothing published
    expect($result->failed)->toBe(1)
        ->and($result->published)->toBe(0);

    // lastErrors should contain the missing sequence with a helpful message
    expect($result->lastErrors)->toHaveCount(1);
    $err = $result->lastErrors[0];

    expect($err['seq'])->toBe(42);

    $joined = implode(' ', array_map('strval', array_values($err)));
    expect($joined)->toContain('Stored event not found');

    // Outbox::markFailed should have been called once
    expect($fakeOutbox->failed)->toHaveCount(1);
});

it('releases partitions we no longer desire', function () {
    $partitioner = app(Partitioner::class);

    // Fake lease store: starts owning a superset of what we "should" own
    $fakeLeases = new class($partitioner) implements PartitionLeaseStore {
        public array $owned;
        public array $released = [];

        public function __construct(Partitioner $partitioner)
        {
            // Two partitions owned to start with; we'll only "desire" one of them
            $this->owned = [
                $partitioner->labelForIndex(0),
                $partitioner->labelForIndex(1),
            ];
        }

        public function ownedBy(string $workerId, array $desired): array
        {
            // Worker currently owns whatever is in $this->owned
            return $this->owned;
        }

        public function tryLease(array $partitions, string $owner, int $ttlSeconds): bool
        {
            return true;
        }

        public function release(array $partitions, string $owner): void
        {
            // Record what was released and update owned set
            $this->released = $partitions;
            $this->owned = array_values(array_diff($this->owned, $partitions));
        }

        public function renew(array $partitions, string $owner, int $ttlSeconds): bool
        {
            return true;
        }
    };

    // WorkerRegistry: two workers, so our worker should not own *all* partitions
    $registry = Mockery::mock(WorkerRegistry::class);
    $registry->shouldReceive('join')->andReturnNull();
    $registry->shouldReceive('heartbeat')->andReturnNull();
    $registry->shouldReceive('purgeStale')->andReturn(1);
    $registry->shouldReceive('activeIds')->andReturn(['worker-a', 'worker-b']);

    $outbox = app(Outbox::class);      // not used in this test
    $eventStore = app(EventStore::class);  // not used in this test
    $dispatcher = app(Dispatcher::class);
    $metrics = app(Metrics::class);
    $aggregateRegistry = app(AggregateRegistry::class);

    $runner = new WorkerRunner(
        $registry,
        $fakeLeases,
        $outbox,
        $eventStore,
        $dispatcher,
        'worker-a',
        $partitioner,
        $aggregateRegistry,
        $metrics,
        true,   // leasing enabled
        2,      // small partition count: 0 and 1
        10,
        60,
        30,
        10,
    );

    // Force a renew/sync on the next tick so the leasing logic runs
    $ref = new ReflectionClass(WorkerRunner::class);
    $propLastRenew = $ref->getProperty('lastRenewNs');
    $propLastRenew->setValue($runner, 0);

    /** @var TickResult $result */
    $result = $runner->tick();

    // We should have released at least one partition
    expect($fakeLeases->released)->not->toBe([]);
    expect($result->releasedPartitions)->toBe($fakeLeases->released);
});