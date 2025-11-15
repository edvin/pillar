<?php

use Pillar\Aggregate\AggregateRegistry;
use Pillar\Event\EventContext;
use Pillar\Event\EventReplayer;
use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;
use Tests\Fixtures\Projectors\ContextProbeProjector;

it('sets correlation id and timestamp in EventContext during replay', function () {
    // Produce two events with distinct correlation ids
    $id = DocumentId::new();

    // Commit #1
    $s0 = Pillar::session();
    $s0->attach(Document::create($id, 'v0'));
    // set a correlation id for this commit (EventStore uses EventContext::correlationId() on append)
    EventContext::initialize(null, 'C-1');
    $s0->commit();
    EventContext::clear();

    // Commit #2
    $s1 = Pillar::session();
    $a1 = $s1->find($id);
    $a1->rename('v1');
    EventContext::initialize(null, 'C-2');
    $s1->commit();
    EventContext::clear();

    // Register our probe projector directly on the EventReplayer (Option A)
    $replayer = app(EventReplayer::class);
    ContextProbeProjector::reset();
    $replayer->registerProjector(DocumentCreated::class, ContextProbeProjector::class);
    $replayer->registerProjector(DocumentRenamed::class, ContextProbeProjector::class);

    // Replay and let the probe capture EventContext values
    $streamId = app(AggregateRegistry::class)->toStreamName($id);
    $replayer->replay($streamId);

    // Extract what the projector saw
    $seenCorr = array_map(fn($row) => $row['corr'], ContextProbeProjector::$seen);
    $seenTs   = array_map(fn($row) => $row['ts'],   ContextProbeProjector::$seen);

    // We explicitly set correlation ids per commit → order should be preserved on replay
    expect($seenCorr)->toEqual(['C-1', 'C-2']);

    // Timestamps should be present and look like "YYYY-mm-dd HH:ii:ss"
    expect($seenTs)->toHaveCount(2);
    foreach ($seenTs as $ts) {
        expect($ts)->toMatch('/^\\d{4}-\\d{2}-\\d{2} \\d{2}:\\d{2}:\\d{2}$/');
    }
});