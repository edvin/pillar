<?php

use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Pillar\Aggregate\AggregateRegistry;
use Pillar\Event\EventReplayer;
use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;

// 1) replay() throws when filters match zero events
it('throws when replay finds no events (zero after filtering)', function () {
    // Fresh aggregate id with no events in store
    $id = DocumentId::from(Str::uuid()->toString());

    $streamId = app(AggregateRegistry::class)->toStreamName($id);
    // Valid range but no events for that aggregate → should throw
    expect(fn () => app(EventReplayer::class)->replay($streamId, null, 999, 1000))
        ->toThrow(RuntimeException::class, 'No events found for replay.');
});

// 2) stream() hits the fromSequence "continue" path
it('stream skips earlier events when fromSequence is set', function () {
    $id = DocumentId::new();

    // Build a three-event stream: created(1), rename(2), rename(3)
    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0'));
    $s->commit();

    $s = Pillar::session();
    $doc = $s->find($id);
    $doc->rename('v1');
    $s->commit();

    $s = Pillar::session();
    $doc = $s->find($id);
    $doc->rename('v2');
    $s->commit();

    // Ask replayer to start from sequence 3 → first two will be "continued" (skipped)
    $events = iterator_to_array(app(EventReplayer::class)->stream(
        streamId: app(AggregateRegistry::class)->toStreamName($id),
        fromSequence: 3
    ));

    $seqs = array_map(fn($e) => $e->sequence, $events);
    expect($seqs)->toEqual([3]);
});

// 3) stream() hits the fromDate "continue" path
it('stream skips earlier events when fromDate is set', function () {
    $id = DocumentId::new();

    // Control timestamps via Carbon's test clock (DatabaseEventStore uses Illuminate\Support\Carbon::now)
    Carbon::setTestNow(Carbon::create(2025, 1, 1, 0, 0, 0, 'UTC'));
    $s = Pillar::session();
    $s->attach(Document::create($id, 'v0'));
    $s->commit();

    Carbon::setTestNow(Carbon::create(2025, 1, 1, 0, 0, 10, 'UTC'));
    $s = Pillar::session();
    $doc = $s->find($id);
    $doc->rename('later');
    $s->commit();

    // fromDate between the two events → first will be "continued" (skipped)
    $events = iterator_to_array(app(EventReplayer::class)->stream(
        streamId: app(AggregateRegistry::class)->toStreamName($id),
        fromDate: '2025-01-01T00:00:05Z',
        toDate: null
    ));

    // Only the second (later) event should remain
    $types = array_map(fn($e) => $e->eventType, $events);
    expect($types)->toHaveCount(1);

    // Reset test clock to avoid leaking state
    Carbon::setTestNow();
});