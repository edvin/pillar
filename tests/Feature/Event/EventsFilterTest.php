<?php

use Carbon\CarbonImmutable;
use Pillar\Event\EventReplayer;
use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;
use Tests\Fixtures\Projectors\TitleListProjector;

it('filters events by sequence and date bounds', function () {
    // Timestamps (UTC)
    $t1 = CarbonImmutable::parse('2025-01-01 10:00:00', 'UTC');
    $t2 = CarbonImmutable::parse('2025-01-01 10:05:00', 'UTC');
    $t3 = CarbonImmutable::parse('2025-01-01 10:10:00', 'UTC');

    // Create @ t1
    Carbon\Carbon::setTestNow($t1);
    $id = DocumentId::new();
    $doc = Document::create($id, 'v0');
    $s0 = Pillar::session();
    $s0->add($doc);
    $s0->commit();

    // Rename @ t2
    Carbon\Carbon::setTestNow($t2);
    $s1 = Pillar::session();
    $a1 = $s1->find($id);
    $a1->rename('v1');
    $s1->commit();

    // Rename @ t3
    Carbon\Carbon::setTestNow($t3);
    $s2 = Pillar::session();
    $a2 = $s2->find($id);
    $a2->rename('v2');
    $s2->commit();

    // Always clear test clock after
    Carbon\Carbon::setTestNow();

    // Grab all events for this aggregate
    $all = iterator_to_array(Pillar::events($id));
    expect($all)->toHaveCount(3);

    // === Sequence window: up to the second event (inclusive)
    $toSeq = $all[1]->sequence; // global sequence of the 2nd event
    $seqWindow = iterator_to_array(Pillar::events($id, null, null, $toSeq));
    expect($seqWindow)->toHaveCount(2);

    // === Date window: up to time t2 (inclusive)
    $dateWindow = iterator_to_array(Pillar::events($id, null, null, null, null, $t2->toIso8601String()));
    expect($dateWindow)->toHaveCount(2);
});

it('it replays only the specified event type (DocumentRenamed)', function () {
    // produce events: v0, v1, v2
    $id = DocumentId::new();

    $s0 = Pillar::session();
    $s0->add(Document::create($id, 'v0'));
    $s0->commit();

    $s1 = Pillar::session();
    $a1 = $s1->find($id);
    $a1->rename('v1');
    $s1->commit();

    $s2 = Pillar::session();
    $a2 = $s2->find($id);
    $a2->rename('v2');
    $s2->commit();

    // simulate rebuild: clear projector
    TitleListProjector::reset();

    // replay only renames
    app(EventReplayer::class)->replay($id, DocumentRenamed::class);

    expect(TitleListProjector::$seen)->toBe(['v1', 'v2']); // no 'v0'
});

