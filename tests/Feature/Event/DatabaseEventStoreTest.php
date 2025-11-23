<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Pillar\Aggregate\AggregateRegistry;
use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Event\ConcurrencyException;
use Pillar\Event\DatabaseEventMapper;
use Pillar\Event\DatabaseEventStore;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\EventStore;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\PublicationPolicy;
use Pillar\Metrics\Metrics;
use Pillar\Outbox\Outbox;
use Pillar\Outbox\Partitioner;
use Pillar\Repository\EventStoreRepository;
use Pillar\Serialization\ObjectSerializer;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;
use Tests\Fixtures\Encryption\DummyEvent;

it('append() advances stream_sequence when expectedSequence matches (portable path)', function () {
    /** @var EventStore $store */
    $store = app(EventStore::class);

    $id = DocumentId::new();

    // 1) First append – no expectedSequence → returns per-stream sequence 1
    $seq1 = $store->append($id, new DocumentCreated($id, 't0'));
    expect($seq1)->toBe(1);

    // 2) Second append – pass expectedSequence=1 → should succeed and return 2
    $seq2 = $store->append($id, new DocumentRenamed($id, 't1'), 1);
    expect($seq2)->toBe(2);

    // Sanity-check backing state via the events table
    $streamId = app(AggregateRegistry::class)->toStreamName($id);

    $rows = DB::table('events')
        ->select('stream_sequence', 'event_type')
        ->where('stream_id', $streamId)
        ->orderBy('stream_sequence')
        ->get()
        ->map(fn($r) => [(int)$r->stream_sequence, $r->event_type])
        ->all();

    expect($rows)->toEqual([
        [1, Tests\Fixtures\Document\DocumentCreated::class],
        [2, Tests\Fixtures\Document\DocumentRenamed::class],
    ]);
});

it('increments per-stream sequence on subsequent appends without expectedSequence', function () {
    /** @var EventStore $store */
    $store = app(EventStore::class);

    $id = DocumentId::new();

    $s1 = $store->append($id, new DocumentCreated($id, 'seed'));
    $s2 = $store->append($id, new DocumentRenamed($id, 'next'));

    expect($s1)->toBe(1)
        ->and($s2)->toBe(2);

    $streamId = app(AggregateRegistry::class)->toStreamName($id);

    $rows = DB::table('events')
        ->select('stream_sequence', 'event_type')
        ->where('stream_id', $streamId)
        ->orderBy('stream_sequence')
        ->get()
        ->map(fn($r) => [(int)$r->stream_sequence, $r->event_type])
        ->all();

    expect($rows)->toEqual([
        [1, Tests\Fixtures\Document\DocumentCreated::class],
        [2, Tests\Fixtures\Document\DocumentRenamed::class],
    ]);
});

it('uses the portable path for unsupported drivers and still persists correctly', function () {
    // Force "unsupported" driver by overriding driver() via a test subclass
    $store = new class(
        app(AggregateRegistry::class),
        app(ObjectSerializer::class),
        app(EventAliasRegistry::class),
        app(EventFetchStrategyResolver::class),
        app(PublicationPolicy::class),
        app(Outbox::class),
        app(Partitioner::class),
        app(DatabaseEventMapper::class),
        app(Metrics::class),
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

        $streamId = app(AggregateRegistry::class)->toStreamName($id);

        $rows = DB::table('events')
            ->select('stream_sequence', 'event_type')
            ->where('stream_id', $streamId)
            ->orderBy('stream_sequence')
            ->get()
            ->map(fn($r) => [(int)$r->stream_sequence, $r->event_type])
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
        app(AggregateRegistry::class),
        app(ObjectSerializer::class),
        app(EventAliasRegistry::class),
        app(EventFetchStrategyResolver::class),
        app(PublicationPolicy::class),
        app(Outbox::class),
        app(Partitioner::class),
        app(DatabaseEventMapper::class),
        app(Metrics::class),
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

it('recent() returns empty array when limit is non-positive', function () {
    $store = app(DatabaseEventStore::class);

    expect($store->recent(0))->toBe([])
        ->and($store->recent(-5))->toBe([]);
});

it('recent() returns empty array when there are no events', function () {
    // Ensure the events table is empty for this scenario
    DB::table('events')->truncate();

    $store = app(DatabaseEventStore::class);

    expect($store->recent(10))->toBe([]);
});

it('recent() returns latest event per stream ordered by global sequence desc', function () {
    /** @var DatabaseEventStore $store */
    $store = app(DatabaseEventStore::class);

    // Start from a clean slate for deterministic assertions
    DB::table('events')->truncate();

    $idA = DocumentId::new();
    $idB = DocumentId::new();
    $idC = DocumentId::new();

    // Produce events in a known global order:
    // seq1: A created
    $store->append($idA, new DocumentCreated($idA, 'A0'));
    // seq2: B created
    $store->append($idB, new DocumentCreated($idB, 'B0'));
    // seq3: C created
    $store->append($idC, new DocumentCreated($idC, 'C0'));
    // seq4: B renamed
    $store->append($idB, new DocumentRenamed($idB, 'B1'));
    // seq5: A renamed
    $store->append($idA, new DocumentRenamed($idA, 'A1'));

    $streamA = app(AggregateRegistry::class)->toStreamName($idA);
    $streamB = app(AggregateRegistry::class)->toStreamName($idB);
    $streamC = app(AggregateRegistry::class)->toStreamName($idC);

    // recent() with a generous limit should return one latest event per stream,
    // ordered by their global sequence desc: A(renamed), B(renamed), C(created)
    $recent = $store->recent(10);

    $asArray = array_map(
        fn($e) => [$e->streamId, $e->streamSequence, $e->eventType],
        $recent
    );

    expect($recent)->toHaveCount(3);
    expect($asArray)->toEqual([
        [$streamA, 2, DocumentRenamed::class], // seq5
        [$streamB, 2, DocumentRenamed::class], // seq4
        [$streamC, 1, DocumentCreated::class], // seq3
    ]);

    // And the limit parameter should cap the number of streams returned
    $recent2 = $store->recent(2);
    $asArray2 = array_map(
        fn($e) => [$e->streamId, $e->streamSequence, $e->eventType],
        $recent2
    );

    expect($recent2)->toHaveCount(2);
    expect($asArray2)->toEqual([
        [$streamA, 2, DocumentRenamed::class],
        [$streamB, 2, DocumentRenamed::class],
    ]);
});

it('returns null when no event exists at the given global sequence', function () {
    $store = app(DatabaseEventStore::class);

    // Use an impossible/invalid global sequence
    expect($store->getByGlobalSequence(-1))->toBeNull();
});

it('returns a StoredEvent by global sequence (FQCN event_type)', function () {
    $store = app(DatabaseEventStore::class);

    $id = DocumentId::new();

    // Append via the store so we respect whatever mapping logic is in place
    $store->append($id, new DummyEvent('1', 'Hello'));

    $streamId = app(AggregateRegistry::class)->toStreamName($id);

    // Fetch the global sequence from the events table
    $seq = (int)DB::table('events')
        ->where('stream_id', $streamId)
        ->value('sequence');

    $e = $store->getByGlobalSequence($seq);

    expect($e)->not->toBeNull()
        ->and($e->sequence)->toBe($seq)
        ->and($e->streamId)->toBe($streamId)
        ->and($e->streamSequence)->toBe(1)
        ->and($e->eventType)->toBe(DummyEvent::class)
        ->and($e->eventVersion)->toBe(1)
        ->and($e->occurredAt)->not->toBeEmpty()
        ->and($e->event)->toEqual(new DummyEvent('1', 'Hello'));
});


it('throws when attempting to save non event sourced aggregate in event store', function () {
    // A non event-sourced aggregate: implements AggregateRoot, but no event-sourcing trait or interface
    $id = GenericAggregateId::new();

    $aggregate = new class($id) implements AggregateRoot {
        public function __construct(private AggregateRootId $id)
        {
        }

        public function id(): AggregateRootId
        {
            return $this->id;
        }
    };

    $repo = app(EventStoreRepository::class);

    expect(fn() => $repo->save($aggregate))
        ->toThrow(LogicException::class, 'EventSourcedAggregateRoot'); // message contains substring
});

it('wraps repository save in its own transaction when called outside a transaction', function () {
    // Switch to a fresh, isolated SQLite connection so we are not inside any harness transaction
    $original = config('database.default');

    config()->set('database.connections.tx_probe', [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);
    config()->set('database.default', 'tx_probe');
    DB::purge('tx_probe');
    DB::reconnect('tx_probe');

    try {
        // Now we should be at transaction level 0 on this fresh connection
        expect(DB::transactionLevel())->toBe(0);

        // Minimal schema for this connection
        Schema::create('events', function (Blueprint $t) {
            $t->bigIncrements('sequence');
            $t->string('stream_id');
            $t->unsignedBigInteger('stream_sequence');
            $t->string('event_type');
            $t->unsignedInteger('event_version')->default(1);
            $t->string('correlation_id')->nullable();
            $t->longText('event_data');
            $t->dateTime('occurred_at');
            $t->index(['stream_id', 'stream_sequence']);
        });

        Schema::create('snapshots', function (Blueprint $table) {
            $table->id();

            $table->string('aggregate_type', 255);
            $table->string('aggregate_id', 191);

            $table->unsignedBigInteger('snapshot_version')->default(0);
            $table->dateTime('snapshot_created_at');
            $table->json('data');
            $table->unique(['aggregate_type', 'aggregate_id'], 'pillar_snapshots_unique_aggregate');
            $table->index(['aggregate_type', 'snapshot_version'], 'pillar_snapshots_type_version_idx');
        });


        /** @var EventStoreRepository $repo */
        $repo = app(EventStoreRepository::class);

        $id = DocumentId::new();
        $doc = Document::create($id, 't0');
        $doc->rename('t1');

        // Act: this should hit the internal DB::transaction($work) branch in the repository
        $repo->save($doc);

        // Assert: events were persisted atomically for this aggregate id
        $streamId = app(AggregateRegistry::class)->toStreamName($id);

        $rows = DB::table('events')
            ->select('stream_sequence', 'event_type')
            ->where('stream_id', $streamId)
            ->orderBy('stream_sequence')
            ->get()
            ->map(fn($r) => [(int)$r->stream_sequence, $r->event_type])
            ->all();

        expect($rows)->toEqual([
            [1, Tests\Fixtures\Document\DocumentCreated::class],
            [2, Tests\Fixtures\Document\DocumentRenamed::class],
        ]);
    } finally {
        // Tear down the temp connection and restore the original default
        try {
            Schema::dropIfExists('events');
        } catch (Throwable $e) {
            // ignore
        }

        // Restore original default connection
        config()->set('database.default', $original);

        // Disconnect/purge temp and reconnect original
        DB::disconnect('tx_probe');
        DB::purge('tx_probe');
        DB::purge($original);
        DB::reconnect($original);

        // Refresh app to clear any singletons bound to the temp connection
        test()->refreshApplication();
    }
});

