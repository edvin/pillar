<?php

use Illuminate\Support\Str;
use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('db_chunked and db_load_all return identical events', function () {
    // Produce a modest stream
    $id  = DocumentId::from(Str::uuid()->toString());
    $s   = Pillar::session();
    $s->add(Document::create($id, 'v0'));
    $s->commit();

    // a few renames to ensure > 1 chunk when chunk size is small
    foreach (['v1', 'v2', 'v3', 'v4', 'v5'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t);
        $sx->commit();
    }

    // Strategy: chunked (tiny chunk size to force multiple queries)
    config()->set('pillar.event_store.fetch.strategy', 'db_chunked');
    config()->set('pillar.event_store.fetch.chunk_size', 2);
    $chunked = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array(Pillar::events($id))
    );

    // Strategy: load_all
    config()->set('pillar.event_store.fetch.strategy', 'db_load_all');
    $all = array_map(
        fn($e) => [$e->sequence, $e->aggregateSequence, $e->eventType],
        iterator_to_array(Pillar::events($id))
    );

    expect($chunked)->toEqual($all)
        ->and(count($all))->toBeGreaterThan(2);
});