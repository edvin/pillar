<?php

use Illuminate\Support\Str;
use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('persists and reloads an event-sourced aggregate', function () {
    $session = Pillar::session();

    $id = DocumentId::from(Str::uuid()->toString());
    $doc = Document::create($id, 'First Title');

    $session->add($doc);
    $session->commit();

    $session2 = Pillar::session();
    $reloaded = $session2->find($id);

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->id()->value())->toBe($id->value())
        ->and($reloaded->title())->toBe('First Title');
});