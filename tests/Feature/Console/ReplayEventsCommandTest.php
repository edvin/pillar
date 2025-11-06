<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Projectors\TitleListProjector;

it('pillar:replay-events replays into projectors', function () {
    TitleListProjector::reset();

    $id  = DocumentId::from(Str::uuid()->toString());

    // produce events
    $s0 = Pillar::session();
    $s0->add(Document::create($id, 'v0'));
    $s0->commit();

    $s1 = Pillar::session();
    $a1 = $s1->find($id);
    $a1->rename('v1');
    $s1->commit();

    // simulate rebuild: clear projector first
    TitleListProjector::reset();

    $exit = Artisan::call('pillar:replay-events', [
        'aggregate_id' => $id->value(),
    ]);

    expect($exit)->toBe(0);
    expect(TitleListProjector::$seen)->toBe(['v0', 'v1']);
});