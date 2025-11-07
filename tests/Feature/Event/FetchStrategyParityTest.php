<?php

use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Pillar\Event\DatabaseEventStore;
use Pillar\Event\EventReplayer;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\Fetch\Database\DatabaseCursorFetchStrategy;
use Pillar\Event\Fetch\Database\DatabaseLoadAllStrategy;
use Pillar\Event\Fetch\Database\DatabaseChunkedFetchStrategy;

it('db_chunked and db_load_all return identical events', function () {
    $id  = DocumentId::new();
    $s   = Pillar::session();
    $s->add(Document::create($id, 'v0'));
    $s->commit();

    foreach (['v1', 'v2', 'v3', 'v4', 'v5'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t);
        $sx->commit();
    }

    config()->set('pillar.fetch_strategies.default', 'db_chunked');
    config()->set('pillar.fetch_strategies.available.db_chunked.options.chunk_size', 2);
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);
    $chunked = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array(Pillar::events($id))
    );

    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);
    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    expect($strategy)->toBeInstanceOf(DatabaseLoadAllStrategy::class);

    // Also exercise the per-aggregate load() path to cover DatabaseLoadAllStrategy::load()
    $store = app(DatabaseEventStore::class);
    $viaLoad = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array($store->load($id, 0))
    );

    $all = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array(Pillar::events($id))
    );

    expect($viaLoad)->toEqual($all);

    expect($chunked)->toEqual($all)
        ->and(count($all))->toBeGreaterThan(2);
});

it('db_cursor and db_load_all return identical events', function () {
    // Build a modest stream
    $id  = DocumentId::new();
    $s   = Pillar::session();
    $s->add(Document::create($id, 'v0'));
    $s->commit();

    foreach (['v1', 'v2', 'v3', 'v4'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t);
        $sx->commit();
    }

    // cursor (db_streaming)
    config()->set('pillar.fetch_strategies.default', 'db_streaming');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);

    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    expect($strategy)->toBeInstanceOf(DatabaseCursorFetchStrategy::class);

    // Exercise per-aggregate load() to cover DatabaseCursorFetchStrategy::load()
    $store = app(DatabaseEventStore::class);
    $cursorViaLoad = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array($store->load($id, 0))
    );

    $cursor = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array(Pillar::events($id))
    );

    expect($cursorViaLoad)->toEqual($cursor);

    // load_all
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);

    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    expect($strategy)->toBeInstanceOf(DatabaseLoadAllStrategy::class);

    $all = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array(Pillar::events($id))
    );

    expect($cursor)->toEqual($all)
        ->and(count($all))->toBeGreaterThan(2);
});

it('db_chunked load() and all() produce identical sequences', function () {
    $id  = DocumentId::new();
    $s   = Pillar::session();
    $s->add(Document::create($id, 'v0'));
    $s->commit();

    foreach (['v1','v2','v3','v4'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t);
        $sx->commit();
    }

    // choose chunked and a tiny chunk size
    config()->set('pillar.fetch_strategies.default', 'db_chunked');
    config()->set('pillar.fetch_strategies.available.db_chunked.options.chunk_size', 2);
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);

    $resolver = app(EventFetchStrategyResolver::class);
    $strategy = $resolver->resolve($id);
    expect($strategy)->toBeInstanceOf(DatabaseChunkedFetchStrategy::class);

    // load() path (per-aggregate)
    $store = app(DatabaseEventStore::class);
    $viaLoad = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array($store->load($id, 0))
    );

    // all() path (explicitly on the concrete strategy)
    $viaAll = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array($strategy->all($id, null))
    );

    // Also get the all() stream via the EventStore to verify resolver path
    $storeAll = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array($store->all($id, null))
    );

    // If the parity assertion fails, dump full arrays for diagnosis
    try {
        expect($viaLoad)->toEqual($viaAll)
            ->and($viaAll)->toEqual($storeAll)
            ->and(count($viaAll))->toBeGreaterThan(2);
    } catch (Throwable $e) {
        fwrite(STDERR, "\n--- DEBUG: db_chunked load() vs all() ---\n");
        fwrite(STDERR, "viaLoad:\n" . json_encode($viaLoad, JSON_PRETTY_PRINT) . "\n");
        fwrite(STDERR, "viaAll (strategy):\n" . json_encode($viaAll, JSON_PRETTY_PRINT) . "\n");
        fwrite(STDERR, "viaAll (store->all):\n" . json_encode($storeAll, JSON_PRETTY_PRINT) . "\n");
        throw $e;
    }
});

it('db_load_all load() and all() produce identical sequences', function () {
    $id  = DocumentId::new();
    $s   = Pillar::session();
    $s->add(Document::create($id, 'v0'));
    $s->commit();

    foreach (['v1','v2','v3'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t);
        $sx->commit();
    }

    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);

    $resolver = app(EventFetchStrategyResolver::class);
    $strategy = $resolver->resolve($id);
    expect($strategy)->toBeInstanceOf(DatabaseLoadAllStrategy::class);

    $store = app(DatabaseEventStore::class);
    $viaLoad = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array($store->load($id, 0))
    );

    $viaAll = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array($strategy->all($id, null))
    );

    expect($viaLoad)->toEqual($viaAll)
        ->and(count($viaAll))->toBeGreaterThan(2);
});

it('db_streaming load() and all() produce identical sequences', function () {
    $id  = DocumentId::new();
    $s   = Pillar::session();
    $s->add(Document::create($id, 'v0'));
    $s->commit();

    foreach (['v1','v2','v3','v4','v5'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t);
        $sx->commit();
    }

    config()->set('pillar.fetch_strategies.default', 'db_streaming');
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);

    $resolver = app(EventFetchStrategyResolver::class);
    $strategy = $resolver->resolve($id);
    expect($strategy)->toBeInstanceOf(DatabaseCursorFetchStrategy::class);

    $store = app(DatabaseEventStore::class);
    $viaLoad = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array($store->load($id, 0))
    );

    $viaAll = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array($strategy->all($id, null))
    );

    expect($viaLoad)->toEqual($viaAll)
        ->and(count($viaAll))->toBeGreaterThan(2);
});