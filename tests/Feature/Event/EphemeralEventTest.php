<?php

use Illuminate\Contracts\Events\Dispatcher;
use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentPreviewed;
use Tests\Fixtures\Projectors\TitleListProjector;

it('dispatches but does not persist EphemeralEvent on commit', function () {
    // Spy a runtime listener for the ephemeral event
    $dispatched = false;
    app(Dispatcher::class)->listen(DocumentPreviewed::class, function (DocumentPreviewed $e) use (&$dispatched) {
        $dispatched = true;
        expect($e->title)->toBe('ephemeral-title'); // sanity
    });

    // Create aggregate and commit
    $id = DocumentId::new();
    $s0  = Pillar::session();
    $s0->attach(Document::create($id, 'v0'));
    $s0->commit();

    // Baseline: 1 persisted event
    $before = iterator_to_array(Pillar::events($id));
    expect($before)->toHaveCount(1);

    // Emit ephemeral + commit
    $s1 = Pillar::session();
    $a1 = $s1->find($id);
    $a1->preview('ephemeral-title');
    $s1->commit();

    // Assert: ephemeral WAS dispatched
    expect($dispatched)->toBeTrue();

    // … but NOT persisted (still only the original created event)
    $after = iterator_to_array(Pillar::events($id));
    expect($after)->toHaveCount(1);
});

it('does not trigger unrelated projectors for an ephemeral event', function () {
    // TitleListProjector listens to DocumentCreated & DocumentRenamed, not DocumentPreviewed
    TitleListProjector::reset();

    // Create aggregate and commit — projector captures initial title
    $id = DocumentId::new();
    $s0  = Pillar::session();
    $s0->attach(Document::create($id, 'v0'));
    $s0->commit();

    expect(TitleListProjector::$seen)->toBe(['v0']);

    // Emit ephemeral + commit — projector should NOT react (no listener registered)
    $s1 = Pillar::session();
    $a1 = $s1->find($id);
    $a1->preview('ephemeral-title');
    $s1->commit();

    // Still only the original created title
    expect(TitleListProjector::$seen)->toBe(['v0']);
});