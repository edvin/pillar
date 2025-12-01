<?php

use Pillar\Aggregate\AggregateRegistry;
use Pillar\Event\EventReplayer;
use Pillar\Facade\Pillar;
use Pillar\Outbox\Worker\WorkerRunner;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Projectors\TitleListProjector;

it('runs the TitleListProjector on live commits', function () {
    TitleListProjector::reset();

    $id  = DocumentId::new();

    // create
    $s0 = Pillar::session();
    $s0->attach(Document::create($id, 'v0'));
    $s0->commit();

    // rename -> v1
    $s1 = Pillar::session();
    $a1 = $s1->find($id);
    $a1->rename('v1');
    $s1->commit();

    // rename -> v2
    $s2 = Pillar::session();
    $a2 = $s2->find($id);
    $a2->rename('v2');
    $s2->commit();

    app(WorkerRunner::class)->tick();

    expect(TitleListProjector::$seen)->toBe(['v0', 'v1', 'v2']);
});

it('replays into the TitleListProjector after clearing it', function () {
    // produce live events
    $id  = DocumentId::new();

    $s0 = Pillar::session();
    $s0->attach(Document::create($id, 'v0'));
    $s0->commit();

    $s1 = Pillar::session();
    $a1 = $s1->find($id);
    $a1->rename('v1');
    $s1->commit();

    $s2 = Pillar::session();
    $a2 = $s2->find($id);
    $a2->rename('v2');
    $s2->commit();

    // simulate rebuild: clear projector first
    TitleListProjector::reset();

    $streamId = app(AggregateRegistry::class)->toStreamName($id);
    app(EventReplayer::class)->replay($streamId);

    expect(TitleListProjector::$seen)->toBe(['v0', 'v1', 'v2']);
});