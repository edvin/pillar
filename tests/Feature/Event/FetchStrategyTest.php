<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Event\DatabaseEventStore;
use Pillar\Event\EventReplayer;
use Pillar\Event\EventWindow;
use Pillar\Event\Fetch\Database\DatabaseCursorFetchStrategy;
use Pillar\Event\Fetch\Database\DatabaseLoadAllStrategy;
use Pillar\Event\Fetch\Database\DatabaseChunkedFetchStrategy;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\StoredEvent;
use Pillar\Facade\Pillar;
use Pillar\Serialization\JsonObjectSerializer;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;
use Tests\Fixtures\Encryption\DummyEvent;

dataset('strategies', [
    ['db_load_all', DatabaseLoadAllStrategy::class],
    ['db_streaming', DatabaseCursorFetchStrategy::class],
]);

it('load() applies afterAggregateSequence filter', function (string $default, string $expectedClass) {
    // Build stream with aggregate_sequence 1..4
    $id  = DocumentId::new();
    $s   = Pillar::session();
    $s->attach(Document::create($id, 'v0')); // seq 1
    $s->commit();

    foreach (['v1', 'v2', 'v3'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t); // seq 2..4
        $sx->commit();
    }

    // Force strategy and refresh singletons
    config()->set('pillar.fetch_strategies.default', $default);
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);

    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    expect($strategy)->toBeInstanceOf($expectedClass);

    // afterAggregateSequence = 2 → only 3,4
    $rows = array_map(
        fn($e) => $e->streamSequence,
        iterator_to_array($strategy->streamFor($id, EventWindow::afterStreamSeq(2)))
    );

    expect($rows)->toEqual([3, 4]);
})->with('strategies');

it('all() applies eventType filter', function (string $default, string $expectedClass) {
    // Create mixed event types: 1x created, 3x renamed
    $id  = DocumentId::new();
    $s   = Pillar::session();
    $s->attach(Document::create($id, 'v0')); // DocumentCreated
    $s->commit();

    foreach (['v1', 'v2', 'v3'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t); // DocumentRenamed
        $sx->commit();
    }

    // Force strategy and refresh singletons
    config()->set('pillar.fetch_strategies.default', $default);
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);

    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    expect($strategy)->toBeInstanceOf($expectedClass);

    // Filter to only DocumentRenamed
    $renamed = iterator_to_array($strategy->stream(null, DocumentRenamed::class));
    $types   = array_unique(array_map(fn($e) => $e->eventType, $renamed));

    expect($renamed)->toHaveCount(3)
        ->and($types)->toEqual([DocumentRenamed::class]);

    // Sanity: created-only is 1
    $created = iterator_to_array($strategy->stream(null, DocumentCreated::class));
    expect($created)->toHaveCount(1)
        ->and($created[0]->eventType)->toBe(DocumentCreated::class);
})->with('strategies');

it('db_chunked stops at toStreamSequence across chunks (hits early-break)', function () {
    // Force chunked strategy with very small chunk size so we span multiple chunks.
    config()->set('pillar.fetch_strategies.default', 'db_chunked');
    config()->set('pillar.fetch_strategies.available.db_chunked.options.chunk_size', 2);
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(DatabaseChunkedFetchStrategy::class);

    // Minimal, class-agnostic ID for read-only testing
    $aggId = GenericAggregateId::new();

    // Seed 5 events into the default 'events' stream for this aggregate.
    $ser = new JsonObjectSerializer();
    $now = Carbon::now('UTC')->format('Y-m-d H:i:s');

    // Ensure version row exists
    DB::table('aggregate_versions')->insertOrIgnore([
        'aggregate_id'  => $aggId->value(),
        'last_sequence' => 5,
    ]);

    for ($i = 1; $i <= 5; $i++) {
        DB::table('events')->insert([
            'aggregate_id'       => $aggId->value(),
            'aggregate_sequence' => $i,
            'event_type'         => DummyEvent::class,   // FQCN—alias resolver will accept it as-is
            'event_version'      => 1,
            'correlation_id'     => null,
            'event_data'         => $ser->serialize(new DummyEvent((string)$i, "E$i")),
            'occurred_at'        => $now,
        ]);
    }

    // Ask for events up to (and including) aggregate seq 4; with chunk=2 we’ll do:
    // chunk #1 -> seq 1,2 ; after=2 (<4)
    // chunk #2 -> seq 3,4 ; after=4 and the loop hits the early-break ($after >= toAgg)
    // This ensures we do not exit due to a short final chunk and actually exercise the branch.
    $window = EventWindow::toStreamSeq(4);

    // Resolve the strategy directly to guarantee we exercise the chunked branch
    $strategy = app(EventFetchStrategyResolver::class)->resolve($aggId);
    expect($strategy)->toBeInstanceOf(DatabaseChunkedFetchStrategy::class);

    $got = iterator_to_array($strategy->streamFor($aggId, $window));
    $seqs = array_map(fn (StoredEvent $e) => $e->streamSequence, $got);

    // We should only get 1,2,3,4 — and the early-break branch gets executed.
    expect($seqs)->toBe([1, 2, 3, 4]);
});

it('applyWindow caps by global sequence (toGlobalSequence)', function () {
    // Use load_all to exercise applyWindow cleanly
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);

    // Build a simple stream with 4 events for one aggregate
    $id = \Tests\Fixtures\Document\DocumentId::new();

    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0')); // seq 1
    $s->commit();

    foreach (['v1','v2','v3'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t); // seq 2..4
        $sx->commit();
    }

    // Determine the global sequence of the 3rd aggregate event
    $globSeqs = \Illuminate\Support\Facades\DB::table('events')
        ->where('aggregate_id', $id->value())
        ->orderBy('aggregate_sequence')
        ->pluck('sequence')
        ->all();

    $cutoff = (int) $globSeqs[2]; // third event (agg seq 3)

    $window   = EventWindow::toGlobalSeq($cutoff);
    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);

    $got  = iterator_to_array($strategy->streamFor($id, $window));
    $seqs = array_map(fn($e) => $e->streamSequence, $got);

    expect($seqs)->toBe([1, 2, 3]);
});

it('applyWindow caps by occurred_at timestamp (toDateUtc)', function () {
    // Use load_all to exercise applyWindow cleanly
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);

    $id = \Tests\Fixtures\Document\DocumentId::new();

    // Seed 4 events with controlled timestamps
    Carbon::setTestNow(Carbon::parse('2024-01-01 00:00:00', 'UTC'));
    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0')); // seq 1 @ :00
    $s->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:01:00', 'UTC'));
    $s2 = Pillar::session();
    $a2 = $s2->find($id);
    $a2->rename('v1'); // seq 2 @ :01
    $s2->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:02:00', 'UTC'));
    $s3 = Pillar::session();
    $a3 = $s3->find($id);
    $a3->rename('v2'); // seq 3 @ :02
    $s3->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:03:00', 'UTC'));
    $s4 = Pillar::session();
    $a4 = $s4->find($id);
    $a4->rename('v3'); // seq 4 @ :03
    $s4->commit();

    // Cap at 00:01:00 inclusive → should return seq 1 and 2 only
    $cutAt = new DateTimeImmutable('2024-01-01 00:01:00 UTC');
    $window = EventWindow::toDateUtc($cutAt);

    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    $got  = iterator_to_array($strategy->streamFor($id, $window));
    $seqs = array_map(fn($e) => $e->streamSequence, $got);

    expect($seqs)->toBe([1, 2]);

    // Cleanup time mocking
    Carbon::setTestNow();
});

it('applyWindow starts after global sequence (afterGlobalSequence)', function () {
    // Use load_all to exercise applyWindow cleanly
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    // Build a simple stream with 4 events for one aggregate
    $id = \Tests\Fixtures\Document\DocumentId::new();

    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0')); // seq 1
    $s->commit();

    foreach (['v1','v2','v3'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t); // seq 2..4
        $sx->commit();
    }

    // Determine the global sequence of the 2nd aggregate event
    $globSeqs = \Illuminate\Support\Facades\DB::table('events')
        ->where('aggregate_id', $id->value())
        ->orderBy('aggregate_sequence')
        ->pluck('sequence')
        ->all();

    $after = (int) $globSeqs[1]; // second event (agg seq 2)

    $window   = EventWindow::afterGlobalSeq($after);
    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);

    $got  = iterator_to_array($strategy->streamFor($id, $window));
    $seqs = array_map(fn($e) => $e->streamSequence, $got);

    // Should strictly start AFTER the second event → [3,4]
    expect($seqs)->toBe([3, 4]);
});

it('applyWindow starts after occurred_at timestamp (afterDateUtc)', function () {
    // Use load_all to exercise applyWindow cleanly
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    $id = \Tests\Fixtures\Document\DocumentId::new();

    // Seed 4 events with controlled timestamps
    Carbon::setTestNow(Carbon::parse('2024-01-01 00:00:00', 'UTC'));
    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0')); // seq 1 @ :00
    $s->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:01:00', 'UTC'));
    $s2 = Pillar::session();
    $a2 = $s2->find($id);
    $a2->rename('v1'); // seq 2 @ :01
    $s2->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:02:00', 'UTC'));
    $s3 = Pillar::session();
    $a3 = $s3->find($id);
    $a3->rename('v2'); // seq 3 @ :02
    $s3->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:03:00', 'UTC'));
    $s4 = Pillar::session();
    $a4 = $s4->find($id);
    $a4->rename('v3'); // seq 4 @ :03
    $s4->commit();

    // After 00:01:00 exclusive → should return seq 3 and 4 only
    $after = new DateTimeImmutable('2024-01-01 00:01:00 UTC');
    $window = EventWindow::afterDateUtc($after);

    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    $got  = iterator_to_array($strategy->streamFor($id, $window));
    $seqs = array_map(fn($e) => $e->streamSequence, $got);

    expect($seqs)->toBe([3, 4]);

    // Cleanup time mocking
    Carbon::setTestNow();
});

it('global all() respects afterGlobalSequence in applyGlobalWindow', function () {
    // Use load_all to exercise global base path
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    // Two aggregates with interleaving events
    $a = \Tests\Fixtures\Document\DocumentId::new();
    $b = \Tests\Fixtures\Document\DocumentId::new();

    // Seed A@00, B@01, A@02, B@03
    Carbon::setTestNow(Carbon::parse('2024-01-01 00:00:00', 'UTC'));
    $s = Pillar::session();
    $s->attach(Document::create($a, 'A0')); $s->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:01:00', 'UTC'));
    $s = Pillar::session();
    $s->attach(Document::create($b, 'B0')); $s->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:02:00', 'UTC'));
    $sx = Pillar::session(); $ax = $sx->find($a); $ax->rename('A1'); $sx->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:03:00', 'UTC'));
    $sy = Pillar::session(); $by = $sy->find($b); $by->rename('B1'); $sy->commit();

    // Cutoff = global seq of B0 (second event overall)
    $cutoff = (int) \Illuminate\Support\Facades\DB::table('events')
        ->orderBy('sequence')
        ->skip(1)
        ->value('sequence');

    $strategy = app(EventFetchStrategyResolver::class)->resolve(null);
    $window   = EventWindow::afterGlobalSeq($cutoff);

    $got = iterator_to_array($strategy->stream($window));

    // Expect the last two events only (A1, B1)
    expect(count($got))->toBe(2)
        ->and($got[0]->sequence)->toBeGreaterThan($cutoff)
        ->and($got[1]->sequence)->toBeGreaterThan($cutoff);

    Carbon::setTestNow();
});

it('global all() respects toDateUtc in applyGlobalWindow', function () {
    // Use load_all to exercise global base path
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    // Two aggregates with interleaving events
    $a = \Tests\Fixtures\Document\DocumentId::new();
    $b = \Tests\Fixtures\Document\DocumentId::new();

    // Seed A@00, B@01, A@02, B@03
    Carbon::setTestNow(Carbon::parse('2024-01-01 00:00:00', 'UTC'));
    $s = Pillar::session();
    $s->attach(Document::create($a, 'A0')); $s->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:01:00', 'UTC'));
    $s = Pillar::session();
    $s->attach(Document::create($b, 'B0')); $s->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:02:00', 'UTC'));
    $sx = Pillar::session(); $ax = $sx->find($a); $ax->rename('A1'); $sx->commit();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:03:00', 'UTC'));
    $sy = Pillar::session(); $by = $sy->find($b); $by->rename('B1'); $sy->commit();

    $cutAt = new DateTimeImmutable('2024-01-01 00:01:00 UTC');

    $strategy = app(EventFetchStrategyResolver::class)->resolve(null);
    $window   = EventWindow::toDateUtc($cutAt);

    $got = iterator_to_array($strategy->stream($window));

    // Expect the first two events only (A0, B0), capped at :01 inclusive
    expect(count($got))->toBe(2)
        ->and($got[0]->occurredAt)->toBeLessThanOrEqual('2024-01-01 00:01:00')
        ->and($got[1]->occurredAt)->toBeLessThanOrEqual('2024-01-01 00:01:00');

    Carbon::setTestNow();
});

it('global all() with null window returns events (hits early-return)', function () {
    // Ensure we use a strategy that calls applyGlobalWindow() in the global path
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    // Seed two aggregates with one event each
    $a = \Tests\Fixtures\Document\DocumentId::new();
    $b = \Tests\Fixtures\Document\DocumentId::new();

    $s = Pillar::session();
    $s->attach(Document::create($a, 'A0')); $s->commit();

    $s = Pillar::session();
    $s->attach(Document::create($b, 'B0')); $s->commit();

    $strategy = app(EventFetchStrategyResolver::class)->resolve(null);

    // Passing null window triggers the early-return branch inside applyGlobalWindow()
    $got = iterator_to_array($strategy->stream());

    expect(count($got))->toBeGreaterThanOrEqual(2);
});

it('global all() caps at toGlobalSequence in applyGlobalWindow', function () {
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    // Build a single aggregate with 4 events so we can pick a global cutoff
    $id = \Tests\Fixtures\Document\DocumentId::new();

    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0')); $s->commit(); // first global

    foreach (['v1','v2','v3'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t);
        $sx->commit();
    }

    // Take the global sequence of the third overall event as an inclusive cap
    $cutoff = (int) \Illuminate\Support\Facades\DB::table('events')
        ->orderBy('sequence')
        ->skip(2)
        ->value('sequence');

    $strategy = app(EventFetchStrategyResolver::class)->resolve(null);
    $window   = EventWindow::toGlobalSeq($cutoff);

    $got = iterator_to_array($strategy->stream($window));

    // Should include exactly the first 3 events globally, all with sequence <= cutoff
    expect(count($got))->toBe(3)
        ->and($got[0]->sequence)->toBeLessThanOrEqual($cutoff)
        ->and($got[1]->sequence)->toBeLessThanOrEqual($cutoff)
        ->and($got[2]->sequence)->toBeLessThanOrEqual($cutoff)
        ->and(end($got)->sequence)->toBe($cutoff);
});

it('per-aggregate load populates aggregateIdClass', function () {
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    $id = \Tests\Fixtures\Document\DocumentId::new();
    $s  = Pillar::session();
    $s->attach(Document::create($id, 'v0')); $s->commit();

    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    $events   = iterator_to_array($strategy->streamFor($id));

    expect($events)->not->toBeEmpty();
    foreach ($events as $e) {
        expect($e->aggregateIdClass)->toBe($id::class);
    }
});

it('global all() populates aggregateIdClass via join', function () {
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    // two aggregates
    $a = \Tests\Fixtures\Document\DocumentId::new();
    $b = \Tests\Fixtures\Document\DocumentId::new();

    $s = Pillar::session();
    $s->attach(Document::create($a, 'A0')); $s->commit();
    $s = Pillar::session();
    $s->attach(Document::create($b, 'B0')); $s->commit();

    $strategy = app(EventFetchStrategyResolver::class)->resolve(null);
    $events   = iterator_to_array($strategy->stream(null));

    expect($events)->not->toBeEmpty();
    // Should be populated for all rows
    foreach ($events as $e) {
        expect($e->aggregateIdClass)->not->toBeNull();
    }
});

it('cursor strategy hits the global scan branch', function () {
    // Force resolver to pick the cursor strategy
    config()->set('pillar.fetch_strategies.default', 'db_streaming');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    // Seed a couple aggregates
    $a = \Tests\Fixtures\Document\DocumentId::new();
    $b = \Tests\Fixtures\Document\DocumentId::new();

    $s = Pillar::session();
    $s->attach(Document::create($a, 'A1')); $s->commit();

    $s = Pillar::session();
    $s->attach(Document::create($b, 'B1')); $s->commit();

    // Resolve strategy for a GLOBAL scan
    $strategy = app(EventFetchStrategyResolver::class)->resolve(null);
    expect($strategy)->toBeInstanceOf(\Pillar\Event\Fetch\Database\DatabaseCursorFetchStrategy::class);

    // Call the global path
    $events = iterator_to_array($strategy->stream());
    expect($events)->not->toBeEmpty();

    // Ascending global sequence
    $seqs = array_map(fn($e) => $e->sequence, $events);
    $sorted = $seqs; sort($sorted);
    expect($seqs)->toEqual($sorted);

    // Global path should populate aggregateIdClass (join)
    foreach ($events as $e) {
        expect($e->aggregateIdClass)->not->toBeNull();
    }
});
it('global all() applies eventType filter (hits $qb->where("event_type", ...))', function (string $default) {
    // Force strategy and refresh singletons
    config()->set('pillar.fetch_strategies.default', $default);
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    // Seed a single aggregate with a mix of event types
    $id = \Tests\Fixtures\Document\DocumentId::new();

    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0')); // DocumentCreated
    $s->commit();

    foreach (['v1', 'v2', 'v3'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t); // DocumentRenamed
        $sx->commit();
    }

    // Resolve a GLOBAL strategy (null aggregate => global scan path)
    $strategy = app(EventFetchStrategyResolver::class)->resolve(null);

    // Filter to only DocumentCreated (should be exactly 1)
    $created = iterator_to_array($strategy->stream(null, \Tests\Fixtures\Document\DocumentCreated::class));
    expect($created)->toHaveCount(1);
    foreach ($created as $e) {
        expect($e->eventType)->toBe(\Tests\Fixtures\Document\DocumentCreated::class);
    }

    // Filter to only DocumentRenamed (should be exactly 3)
    $renamed = iterator_to_array($strategy->stream(null, \Tests\Fixtures\Document\DocumentRenamed::class));
    expect($renamed)->toHaveCount(3);
    foreach ($renamed as $e) {
        expect($e->eventType)->toBe(\Tests\Fixtures\Document\DocumentRenamed::class);
    }
})->with(['db_load_all', 'db_streaming']);

it('db_chunked per-aggregate all() applies EventWindow (hits applyPerAggregateWindow)', function () {
    // Force chunked strategy and small chunks so pagination logic is exercised
    config()->set('pillar.fetch_strategies.default', 'db_chunked');
    config()->set('pillar.fetch_strategies.available.db_chunked.options.chunk_size', 2);
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    // Build a single aggregate with 4 events (agg seq 1..4)
    $id = \Tests\Fixtures\Document\DocumentId::new();

    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0')); // seq 1
    $s->commit();

    foreach (['v1', 'v2', 'v3'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t); // seq 2..4
        $sx->commit();
    }

    // Resolve the strategy and call all() with a per-aggregate window
    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    expect($strategy)->toBeInstanceOf(\Pillar\Event\Fetch\Database\DatabaseChunkedFetchStrategy::class);

    $window = EventWindow::toStreamSeq(3); // inclusive cap at 3
    $events = iterator_to_array($strategy->streamFor($id, $window));

    $seqs = array_map(fn($e) => $e->streamSequence, $events);
    expect($seqs)->toBe([1, 2, 3]);
});

it('db_chunked global all() applies EventWindow (hits applyGlobalWindow)', function () {
    // Force chunked strategy so we exercise the branch in DatabaseChunkedFetchStrategy::all()
    config()->set('pillar.fetch_strategies.default', 'db_chunked');
    config()->set('pillar.fetch_strategies.available.db_chunked.options.chunk_size', 2);
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(\Pillar\Event\DatabaseEventStore::class);

    // Two aggregates with interleaving events to get a meaningful global sequence cutoff
    $a = \Tests\Fixtures\Document\DocumentId::new();
    $b = \Tests\Fixtures\Document\DocumentId::new();

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:00:00', 'UTC'));
    $s = Pillar::session();
    $s->attach(Document::create($a, 'A0')); $s->commit(); // global #1

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:01:00', 'UTC'));
    $s = Pillar::session();
    $s->attach(Document::create($b, 'B0')); $s->commit(); // global #2

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:02:00', 'UTC'));
    $sx = Pillar::session(); $ax = $sx->find($a); $ax->rename('A1'); $sx->commit(); // global #3

    Carbon::setTestNow(Carbon::parse('2024-01-01 00:03:00', 'UTC'));
    $sy = Pillar::session(); $by = $sy->find($b); $by->rename('B1'); $sy->commit(); // global #4

    // Inclusive cap at the 3rd global event
    $cutoff = (int) \Illuminate\Support\Facades\DB::table('events')
        ->orderBy('sequence')
        ->skip(2)
        ->value('sequence');

    $strategy = app(EventFetchStrategyResolver::class)->resolve(null);
    expect($strategy)->toBeInstanceOf(\Pillar\Event\Fetch\Database\DatabaseChunkedFetchStrategy::class);

    $window = EventWindow::toGlobalSeq($cutoff);
    $events = iterator_to_array($strategy->stream(null, $window));

    // We should get exactly the first 3 events globally, all with sequence <= cutoff
    expect(count($events))->toBe(3)
        ->and($events[0]->sequence)->toBeLessThanOrEqual($cutoff)
        ->and($events[1]->sequence)->toBeLessThanOrEqual($cutoff)
        ->and($events[2]->sequence)->toBeLessThanOrEqual($cutoff)
        ->and(end($events)->sequence)->toBe($cutoff);

    Carbon::setTestNow();
});