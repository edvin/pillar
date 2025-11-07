<?php

use Pillar\Event\ConcurrencyException;
use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

it('throws on concurrent commits when optimistic locking is enabled', function () {
    // Create
    $id = DocumentId::new();
    $doc = Document::create($id, 'v0');

    $s0 = Pillar::session();
    $s0->attach($doc);
    $s0->commit();

    // Two sessions load the same version
    $s1 = Pillar::session();
    $a1 = $s1->find($id);

    $s2 = Pillar::session();
    $a2 = $s2->find($id);

    // First modifies and commits
    $a1->rename('v1');
    $s1->commit();

    // Second modifies from stale version → should conflict
    $a2->rename('v2');
    expect(fn () => $s2->commit())->toThrow(ConcurrencyException::class);

    // Final state remains from first commit
    $reloaded = Pillar::session()->find($id);
    expect($reloaded->title())->toBe('v1');
});

it('allows last write wins when optimistic locking is disabled', function () {
    config()->set('pillar.event_store.options.optimistic_locking', false);

    // Create
    $id  = DocumentId::new();
    $doc = Document::create($id, 'v0');

    $s0 = Pillar::session();
    $s0->attach($doc);
    $s0->commit();

    // Two sessions load the same version
    $s1 = Pillar::session();
    $a1 = $s1->find($id);

    $s2 = Pillar::session();
    $a2 = $s2->find($id);

    // Both commit; second wins
    $a1->rename('v1');
    $s1->commit();

    $a2->rename('v2');
    $s2->commit();

    $reloaded = Pillar::session()->find($id);
    expect($reloaded->title())->toBe('v2');
});