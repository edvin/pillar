<?php

use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

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
    $chunked = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array(Pillar::events($id))
    );

    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    $all = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array(Pillar::events($id))
    );

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
    $cursor = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array(Pillar::events($id))
    );

    // load_all
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    $all = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array(Pillar::events($id))
    );

    expect($cursor)->toEqual($all)
        ->and(count($all))->toBeGreaterThan(2);
});