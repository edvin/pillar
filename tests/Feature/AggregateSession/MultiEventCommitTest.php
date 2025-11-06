<?php

use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('commits multiple events in order with contiguous aggregate versions', function () {
    $id = DocumentId::new();
    $doc = Document::create($id, 'v0');

    $s = Pillar::session();
    $s->add($doc);

    // Record two domain events before a single commit
    $doc->rename('v1');
    $doc->rename('v2');

    $s->commit();

    // State check
    $reloaded = Pillar::session()->find($id);
    expect($reloaded->title())->toBe('v2');

    // Sequence check (created + 2 renames)
    $events = iterator_to_array(Pillar::events($id));
    expect($events)->toHaveCount(3);

    $seqs = array_map(fn($e) => $e->aggregateSequence, $events);
    expect($seqs)->toBe([1, 2, 3]); // created=1, rename=2, rename=3
});