<?php

use Pillar\Event\ConcurrencyException;
use Pillar\Event\DatabaseEventStore;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\EventStore;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\Stream\StreamResolver;
use Pillar\Serialization\ObjectSerializer;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;

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
    $last1 = (int) DB::table('aggregate_versions')
        ->where('aggregate_id', $id->value())
        ->value('last_sequence');

    // Second append with no expectedSequence (last-write-wins mode) → last_sequence increments
    $store->append($id, new DocumentRenamed($id, 'next'));
    $last2 = (int) DB::table('aggregate_versions')
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
        protected function driver(): string { return 'sqlsrv'; } // triggers portable branch
    };

    // Bind our forced-driver store for this test
    app()->instance(EventStore::class, $store);

    try {
        $id = DocumentId::new();

        $s1 = $store->append($id, new DocumentCreated($id, 't0'));
        $s2 = $store->append($id, new DocumentRenamed($id, 't1'));

        expect($s1)->toBe(1)
            ->and($s2)->toBe(2);

        $last = (int) DB::table('aggregate_versions')
            ->where('aggregate_id', $id->value())
            ->value('last_sequence');

        expect($last)->toBe(2);

        $rows = DB::table('events')
            ->select('aggregate_sequence', 'event_type')
            ->where('aggregate_id', $id->value())
            ->orderBy('aggregate_sequence')
            ->get()
            ->map(fn ($r) => [(int) $r->aggregate_sequence, $r->event_type])
            ->all();

        expect($rows)->toEqual([
            [1, Tests\Fixtures\Document\DocumentCreated::class],
            [2, Tests\Fixtures\Document\DocumentRenamed::class],
        ]);
    } finally {
        // Clean up container bindings between tests
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('db');
        app()->forgetInstance(DatabaseEventStore::class);
        app()->forgetInstance(\Pillar\Event\EventStore::class);
    }
});

it('enforces expectedSequence on the portable path (conflict throws ConcurrencyException)', function () {
    $store = new class(
        app(StreamResolver::class),
        app(ObjectSerializer::class),
        app(EventAliasRegistry::class),
        app(EventFetchStrategyResolver::class),
    ) extends DatabaseEventStore {
        protected function driver(): string { return 'sqlsrv'; } // triggers portable branch
    };

    app()->instance(EventStore::class, $store);

    try {
        $id = DocumentId::new();

        // Seed one event → version 1
        $store->append($id, new DocumentCreated($id, 't0'));

        // Now claim the wrong expected sequence → should throw ConcurrencyException
        expect(fn () => $store->append($id, new DocumentRenamed($id, 't1'), 999))
            ->toThrow(ConcurrencyException::class);
    } finally {
        \Illuminate\Support\Facades\Facade::clearResolvedInstance('db');
        app()->forgetInstance(DatabaseEventStore::class);
        app()->forgetInstance(\Pillar\Event\EventStore::class);
    }
});