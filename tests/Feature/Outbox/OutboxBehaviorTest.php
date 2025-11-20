<?php

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Expression;
use Mockery\MockInterface;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Event\EventStore;
use Pillar\Event\ShouldPublish;
use Pillar\Logging\PillarLogger;
use Pillar\Metrics\Metrics;
use Pillar\Outbox\DatabaseOutbox;
use Pillar\Outbox\Outbox;
use Pillar\Outbox\OutboxMessage;
use Pillar\Outbox\Worker\WorkerRunner;

it('does not dispatch a ShouldPublish event until a worker tick runs', function () {
    // Arrange: make the worker claim from all partitions (no leasing complexity)
    config()->set('pillar.outbox.worker.leasing', false);

    // We only care about this specific event class
    Event::fake([TestPublishedForTick::class]);

    /** @var EventStore $store */
    $store = app(EventStore::class);

    // Append a publishable event directly (no aggregate needed for this assertion)
    $aggregateId = GenericAggregateId::new();
    $store->append($aggregateId, new TestPublishedForTick('hello'), null);

    // Assert: nothing dispatched yet (enqueued in outbox only)
    Event::assertNotDispatched(TestPublishedForTick::class);

    // Act: run one worker tick (claims & publishes from outbox)
    /** @var WorkerRunner $runner */
    $runner = app(WorkerRunner::class);
    $runner->tick();

    // Assert: now it has been dispatched exactly once
    Event::assertDispatchedTimes(TestPublishedForTick::class, 1);
});


it('hydrates a pending outbox message', function () {
    /** @var Outbox $outbox */
    $outbox = app(Outbox::class);

    $outbox->enqueue(globalSequence: 42, partition: 'p00');

    $messages = $outbox->claimPending(limit: 10);
    expect($messages)->toHaveCount(1);

    /** @var OutboxMessage $m */
    $m = $messages[0];

    expect($m->globalSequence)->toBe(42)
        ->and($m->publishedAt)->toBeNull()
        ->and($m->isPublished())->toBeFalse()
        ->and($m->isReady(new DateTimeImmutable('2030-01-01T00:00:00Z')))->toBeTrue();
});


it('marks a message as failed and schedules a retry', function () {
    $table = 'outbox';
    $globalSequence = 42;

    // Seed a pending row
    DB::table($table)->insert([
        'global_sequence' => $globalSequence,
        'attempts' => 0,
        'available_at' => now()->subMinute(),
        'published_at' => null,
        'partition_key' => 'default',
        'claim_token' => 'old-token',
        'last_error' => null,
    ]);

    // Build an OutboxMessage using the same shape hydrateRows expects
    $message = OutboxMessage::fromRow((object)[
        'global_sequence' => $globalSequence,
        'attempts' => 0,
        'available_at' => now()->subMinute(),
        'published_at' => null,
        'partition_key' => 'default',
        'last_error' => null,
    ]);

    // Long error message to exercise the truncation
    $errorMessage = str_repeat('X', 2000);
    $error = new RuntimeException($errorMessage);

    $outbox = new DatabaseOutbox(
        table: $table,
        claimTtl: 30,
        retryBackoff: 60,
        logger: app(PillarLogger::class),
        metrics: app(Metrics::class),
    );

    $outbox->markFailed($message, $error);

    $row = DB::table($table)->where('global_sequence', $globalSequence)->first();

    expect($row->attempts)->toBe(1)
        ->and($row->claim_token)->toBeNull()
        ->and($row->last_error)->toBe(substr($errorMessage, 0, 1000))
        ->and(strlen($row->last_error))->toBe(1000)
        ->and($row->available_at)->not->toBeNull(); // don’t over-specify exact timestamp
});

it('claims pending messages using the generic strategy', function () {
    $table = 'outbox';

    // Seed two rows in the same partition, one available, one in the future.
    DB::table($table)->insert([
        [
            'global_sequence' => 1,
            'attempts' => 0,
            'available_at' => now()->subMinute(),
            'published_at' => null,
            'partition_key' => 'p1',
            'claim_token' => null,
            'last_error' => null,
        ],
        [
            'global_sequence' => 2,
            'attempts' => 0,
            'available_at' => now()->addMinute(), // not yet available
            'published_at' => null,
            'partition_key' => 'p1',
            'claim_token' => null,
            'last_error' => null,
        ],
    ]);

    /** @var MockInterface&DatabaseOutbox $outbox */
    $outbox = Mockery::mock(DatabaseOutbox::class, [
        $table, // table
        30,     // claimTtl
        60,     // retryBackoff
        app(PillarLogger::class),
        app(Metrics::class)
    ])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    // 1) Force the "default" arm in claimPending() (i.e. the generic path).
    // Any non-sqlite driver will do here.
    $outbox->shouldReceive('dbDriver')->andReturn('sqlsrv');

    // 2) But we want SQLite-compatible expressions for our *actual* test DB.
    // So we stub dbNow and dbPlusSeconds to return Expression instances that SQLite understands.
    $outbox->shouldReceive('dbNow')
        ->andReturn(DB::raw("datetime('now')"));

    $outbox->shouldReceive('dbPlusSeconds')
        ->andReturnUsing(function (int $seconds): Expression {
            return DB::raw("datetime('now', '" . sprintf('%+d seconds', $seconds) . "')");
        });

    $messages = $outbox->claimPending(limit: 10, partitions: ['p1']);

    // We should only have claimed the first message
    expect($messages)->toHaveCount(1)
        ->and($messages[0]->globalSequence)->toBe(1);

    // Row 1 should now be claimed
    $row1 = DB::table($table)->where('global_sequence', 1)->first();
    expect($row1->claim_token)->not->toBeNull()
        ->and($row1->available_at)->not->toBeNull();

    // Row 2 should still be untouched (future available_at)
    $row2 = DB::table($table)->where('global_sequence', 2)->first();
    expect($row2->claim_token)->toBeNull();
});

it('returns an empty array when there are no pending messages for the generic strategy', function () {
    $table = 'outbox';

    // Ensure the table is empty
    DB::table($table)->truncate();

    /** @var MockInterface&DatabaseOutbox $outbox */
    $outbox = Mockery::mock(DatabaseOutbox::class, [
        $table, // table
        30,     // claimTtl
        60,     // retryBackoff
        app(PillarLogger::class),
        app(Metrics::class),
    ])
        ->makePartial()
        ->shouldAllowMockingProtectedMethods();

    // Force the generic path in claimPending()
    $outbox->shouldReceive('dbDriver')->andReturn('sqlsrv');

    // Provide SQLite-compatible expressions for the test DB
    $outbox->shouldReceive('dbNow')
        ->andReturn(DB::raw("datetime('now')"));

    $outbox->shouldReceive('dbPlusSeconds')
        ->andReturnUsing(function (int $seconds): Expression {
            return DB::raw("datetime('now', '" . sprintf('%+d seconds', $seconds) . "')");
        });

    $messages = $outbox->claimPending(limit: 10, partitions: ['p1']);

    expect($messages)->toBeArray()->toBeEmpty();
});

it('uses the mysql-specific strategy when driver is mysql', function () {
    $outbox = new class('outbox', 30, 60, app(PillarLogger::class), app(Metrics::class)) extends DatabaseOutbox {
        public ?array $calledWith = null;

        public function dbDriver(): string
        {
            return 'mysql';
        }

        protected function claimPendingMysql(int $limit, array $partitions, string $token): array
        {
            $this->calledWith = compact('limit', 'partitions', 'token');

            return [];
        }

        protected function claimPendingPgsql(int $limit, array $partitions, string $token): array
        {
            throw new RuntimeException('claimPendingPgsql should not be called.');
        }

        protected function claimPendingGeneric(int $limit, array $partitions, string $token): array
        {
            throw new RuntimeException('claimPendingGeneric should not be called.');
        }
    };

    $messages = $outbox->claimPending(limit: 10, partitions: ['p1']);

    expect($messages)->toBeArray()->toBeEmpty()
        ->and($outbox->calledWith)->not->toBeNull()
        ->and($outbox->calledWith['limit'])->toBe(10)
        ->and($outbox->calledWith['partitions'])->toBe(['p1'])
        ->and($outbox->calledWith['token'])->toBeString()
        ->and($outbox->calledWith['token'])->not->toBe('');

});


it('uses the pgsql-specific strategy when driver is pgsql', function () {
    $outbox = new class('outbox', 30, 60, app(PillarLogger::class), app(Metrics::class)) extends DatabaseOutbox {
        public ?array $calledWith = null;

        public function dbDriver(): string
        {
            return 'pgsql';
        }

        protected function claimPendingPgsql(int $limit, array $partitions, string $token): array
        {
            $this->calledWith = compact('limit', 'partitions', 'token');

            return [];
        }

        protected function claimPendingMysql(int $limit, array $partitions, string $token): array
        {
            throw new RuntimeException('claimPendingMysql should not be called.');
        }

        protected function claimPendingGeneric(int $limit, array $partitions, string $token): array
        {
            throw new RuntimeException('claimPendingGeneric should not be called.');
        }
    };

    $messages = $outbox->claimPending(limit: 5, partitions: ['p2']);

    expect($messages)->toBeArray()->toBeEmpty()
        ->and($outbox->calledWith)->not->toBeNull()
        ->and($outbox->calledWith['limit'])->toBe(5)
        ->and($outbox->calledWith['partitions'])->toBe(['p2'])
        ->and($outbox->calledWith['token'])->toBeString()
        ->and($outbox->calledWith['token'])->not->toBe('');
});

it('claims pending messages using the mysql-specific implementation', function () {
    $table = 'outbox';

    DB::table($table)->insert([
        'global_sequence' => 10,
        'attempts' => 1,
        'available_at' => now(),
        'published_at' => null,
        'partition_key' => 'p1',
        'claim_token' => 'mysql-token',
        'last_error' => null,
    ]);

    $originalDb = DB::getFacadeRoot();

    /** @var DatabaseManager|MockInterface $mock */
    $mock = Mockery::mock($originalDb)->makePartial();

    DB::swap($mock);

    $mock->shouldReceive('update')
        ->once()
        ->andReturn(1);

    $outbox = new class($table, 30, 60, app(PillarLogger::class), app(Metrics::class)) extends DatabaseOutbox {
        public function callClaimPendingMysql(int $limit, array $partitions, string $token): array
        {
            return $this->claimPendingMysql($limit, $partitions, $token);
        }
    };

    try {
        $messages = $outbox->callClaimPendingMysql(limit: 10, partitions: ['p1'], token: 'mysql-token');
    } finally {
        DB::swap($originalDb);
    }

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->globalSequence)->toBe(10);
});


it('claims pending messages using the pgsql-specific implementation', function () {
    $table = 'outbox';

    $originalDb = DB::getFacadeRoot();

    /** @var DatabaseManager|MockInterface $mock */
    $mock = Mockery::mock($originalDb)->makePartial();

    DB::swap($mock);

    $mock->shouldReceive('select')
        ->once()
        ->andReturn([
            (object)[
                'global_sequence' => 99,
                'attempts' => 2,
                'available_at' => now(),
                'published_at' => null,
                'partition_key' => 'p2',
                'last_error' => 'boom',
            ],
        ]);

    $outbox = new class($table, 30, 60, app(PillarLogger::class), app(Metrics::class)) extends DatabaseOutbox {
        public function callClaimPendingPgsql(int $limit, array $partitions, string $token): array
        {
            return $this->claimPendingPgsql($limit, $partitions, $token);
        }
    };

    try {
        $messages = $outbox->callClaimPendingPgsql(limit: 5, partitions: ['p2'], token: 'pgsql-token');
    } finally {
        DB::swap($originalDb);
    }

    expect($messages)->toHaveCount(1)
        ->and($messages[0]->globalSequence)->toBe(99);
});

/**
 * Minimal event fixture that is publishable via the outbox.
 */
final class TestPublishedForTick implements ShouldPublish
{
    public function __construct(public string $payload)
    {
    }
}