<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Event\ConcurrencyException;
use Pillar\Event\DatabaseEventStore;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\EventStore;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\Stream\StreamResolver;
use Pillar\Serialization\JsonObjectSerializer;
use Pillar\Serialization\ObjectSerializer;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;
use Tests\Fixtures\Encryption\DummyEvent;

it('append() advances last_sequence when expectedSequence matches (portable path)', function () {
    /** @var EventStore $store */
    $store = app(EventStore::class);

    $id = DocumentId::new();

    // 1) First append – no expectedSequence → creates aggregate_versions row and returns 1
    $seq1 = $store->append($id, new DocumentCreated($id, 't0'));
    expect($seq1)->toBe(1);

    // 2) Second append – pass expectedSequence=1 → should succeed and return 2
    $seq2 = $store->append($id, new DocumentRenamed($id, 't1'), 1);
    expect($seq2)->toBe(2);

    // Sanity-check backing state
    $last = DB::table('aggregate_versions')
        ->where('aggregate_id', $id->value())
        ->value('last_sequence');

    expect((int)$last)->toBe(2);

    // And events are there with contiguous aggregate_sequence
    $rows = DB::table('events')
        ->select('aggregate_sequence', 'event_type')
        ->where('aggregate_id', $id->value())
        ->orderBy('aggregate_sequence')
        ->get()
        ->map(fn($r) => [(int)$r->aggregate_sequence, $r->event_type])
        ->all();

    expect($rows)->toEqual([
        [1, Tests\Fixtures\Document\DocumentCreated::class],
        [2, Tests\Fixtures\Document\DocumentRenamed::class],
    ]);
});

it('creates aggregate_versions on first append and advances on subsequent appends', function () {
    /** @var EventStore $store */
    $store = app(EventStore::class);

    $id = DocumentId::new();

    // First append seeds aggregate_versions with last_sequence = 1
    $store->append($id, new DocumentCreated($id, 'seed'));
    $last1 = (int)DB::table('aggregate_versions')
        ->where('aggregate_id', $id->value())
        ->value('last_sequence');

    // Second append with no expectedSequence (last-write-wins mode) → last_sequence increments
    $store->append($id, new DocumentRenamed($id, 'next'));
    $last2 = (int)DB::table('aggregate_versions')
        ->where('aggregate_id', $id->value())
        ->value('last_sequence');

    expect($last1)->toBe(1)
        ->and($last2)->toBe(2);
});

it('uses the portable path for unsupported drivers and still persists correctly', function () {
    // Force "unsupported" driver by overriding driver() via a test subclass
    $store = new class(
        app(StreamResolver::class),
        app(ObjectSerializer::class),
        app(EventAliasRegistry::class),
        app(EventFetchStrategyResolver::class),
    ) extends DatabaseEventStore {
        protected function driver(): string
        {
            return 'sqlsrv';
        } // triggers portable branch
    };

    // Bind our forced-driver store for this test
    app()->instance(EventStore::class, $store);

    try {
        $id = DocumentId::new();

        $s1 = $store->append($id, new DocumentCreated($id, 't0'));
        $s2 = $store->append($id, new DocumentRenamed($id, 't1'));

        expect($s1)->toBe(1)
            ->and($s2)->toBe(2);

        $last = (int)DB::table('aggregate_versions')
            ->where('aggregate_id', $id->value())
            ->value('last_sequence');

        expect($last)->toBe(2);

        $rows = DB::table('events')
            ->select('aggregate_sequence', 'event_type')
            ->where('aggregate_id', $id->value())
            ->orderBy('aggregate_sequence')
            ->get()
            ->map(fn($r) => [(int)$r->aggregate_sequence, $r->event_type])
            ->all();

        expect($rows)->toEqual([
            [1, Tests\Fixtures\Document\DocumentCreated::class],
            [2, Tests\Fixtures\Document\DocumentRenamed::class],
        ]);
    } finally {
        // Clean up container bindings between tests
        Facade::clearResolvedInstance('db');
        app()->forgetInstance(DatabaseEventStore::class);
        app()->forgetInstance(EventStore::class);
    }
});

it('enforces expectedSequence on the portable path (conflict throws ConcurrencyException)', function () {
    $store = new class(
        app(StreamResolver::class),
        app(ObjectSerializer::class),
        app(EventAliasRegistry::class),
        app(EventFetchStrategyResolver::class),
    ) extends DatabaseEventStore {
        protected function driver(): string
        {
            return 'sqlsrv';
        } // triggers portable branch
    };

    app()->instance(EventStore::class, $store);

    try {
        $id = DocumentId::new();

        // Seed one event → version 1
        $store->append($id, new DocumentCreated($id, 't0'));

        // Now claim the wrong expected sequence → should throw ConcurrencyException
        expect(fn() => $store->append($id, new DocumentRenamed($id, 't1'), 999))
            ->toThrow(ConcurrencyException::class);
    } finally {
        Facade::clearResolvedInstance('db');
        app()->forgetInstance(DatabaseEventStore::class);
        app()->forgetInstance(EventStore::class);
    }
});

it('executes the MySQL-optimized branch when connected to real MySQL', function () {
    if (!env('TEST_WITH_MYSQL')) {
        test()->markTestSkipped('Set TEST_WITH_MYSQL=1 to run this integration test.');
    }
    if (!extension_loaded('pdo_mysql')) {
        test()->markTestSkipped('pdo_mysql extension not loaded.');
    }

    // Remember the original default connection (usually sqlite for tests)
    $original = config('database.default', 'sqlite');

    // Point the default connection to a real MySQL
    config()->set('database.connections.it_mysql', [
        'driver' => 'mysql',
        'host' => env('MYSQL_HOST', '127.0.0.1'),
        'port' => env('MYSQL_PORT', '3306'),
        'database' => env('MYSQL_DATABASE', 'pillar_test'),
        'username' => env('MYSQL_USERNAME', 'root'),
        'password' => env('MYSQL_PASSWORD', 'secret'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => false,
    ]);
    config()->set('database.default', 'it_mysql');

    DB::purge('it_mysql');
    DB::reconnect('it_mysql');

    try {
        // Minimal schema on MySQL
        Schema::connection('it_mysql')->dropIfExists('events');
        Schema::connection('it_mysql')->dropIfExists('aggregate_versions');

        Schema::connection('it_mysql')->create('aggregate_versions', function (Blueprint $t) {
            $t->string('aggregate_id')->primary();
            $t->string('aggregate_id_class')->nullable()->index();
            $t->unsignedBigInteger('last_sequence')->default(0);
        });

        Schema::connection('it_mysql')->create('events', function (Blueprint $t) {
            $t->bigIncrements('sequence');
            $t->string('aggregate_id');
            $t->unsignedBigInteger('aggregate_sequence');
            $t->string('event_type');
            $t->unsignedInteger('event_version')->default(1);
            $t->string('correlation_id')->nullable();
            $t->longText('event_data');
            $t->dateTime('occurred_at');
            $t->index(['aggregate_id', 'aggregate_sequence']);
        });

        /** @var EventStore $store */
        $store = app(EventStore::class);

        $id = DocumentId::new();

        // Exercise the MySQL LAST_INSERT_ID() branch
        $s1 = $store->append($id, new DocumentCreated($id, 'first'));
        $s2 = $store->append($id, new DocumentRenamed($id, 'second'), 1);

        expect($s1)->toBe(1)->and($s2)->toBe(2);

        $rows = DB::table('events')
            ->select('aggregate_sequence', 'event_type')
            ->where('aggregate_id', $id->value())
            ->orderBy('aggregate_sequence')
            ->get()
            ->map(fn($r) => [(int)$r->aggregate_sequence, $r->event_type])
            ->all();

        expect($rows)->toEqual([
            [1, DocumentCreated::class],
            [2, DocumentRenamed::class],
        ])
            ->and(fn() => $store->append($id, new DocumentRenamed($id, 'should-fail'), 999))
            ->toThrow(ConcurrencyException::class);

        $count = DB::table('events')->where('aggregate_id', $id->value())->count();
        $last  = (int) DB::table('aggregate_versions')->where('aggregate_id', $id->value())->value('last_sequence');

        expect($count)->toBe(2)->and($last)->toBe(2);
    } finally {
        // Restore original default connection & tear down MySQL
        config()->set('database.default', $original);

        // Drop the MySQL tables to avoid leaking state
        try {
            Schema::connection('it_mysql')->dropIfExists('events');
            Schema::connection('it_mysql')->dropIfExists('aggregate_versions');
        } catch (\Throwable $e) {
            // ignore if connection is gone
        }

        // Purge & disconnect both connections
        DB::disconnect('it_mysql');
        DB::purge('it_mysql');

        DB::purge($original);
        DB::reconnect($original);

        // IMPORTANT: reboot the Testbench/Laravel app so any SQLite refresh
        // runs outside of any lingering transaction context and without MySQL defaults
        test()->refreshApplication();
    }
});

it('returns null when no event exists at the given global sequence', function () {
    $store = app(DatabaseEventStore::class);

    // Use an impossible/invalid global sequence
    expect($store->getByGlobalSequence(-1))->toBeNull();
});

it('returns a StoredEvent by global sequence (FQCN event_type)', function () {
    $id  = GenericAggregateId::new();
    $ser = new JsonObjectSerializer();
    $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

    // Insert a single event and capture its global sequence (PK)
    $seq = DB::table('events')->insertGetId([
        'aggregate_id'       => $id->value(),
        'aggregate_sequence' => 1,
        'event_type'         => DummyEvent::class, // FQCN is fine; alias resolution will no-op
        'event_version'      => 1,
        'correlation_id'     => null,
        'event_data'         => $ser->serialize(new DummyEvent('1', 'Hello')),
        'occurred_at'        => $now,
    ]);

    $store = app(DatabaseEventStore::class);
    $e     = $store->getByGlobalSequence((int) $seq);

    expect($e)->not->toBeNull()
        ->and($e->sequence)->toBe((int) $seq)
        ->and($e->aggregateId)->toBe($id->value())
        ->and($e->aggregateSequence)->toBe(1)
        ->and($e->eventType)->toBe(DummyEvent::class)
        ->and($e->eventVersion)->toBe(1)
        ->and($e->occurredAt)->toBe($now)
        ->and($e->event)->toEqual(new DummyEvent('1', 'Hello'));
});