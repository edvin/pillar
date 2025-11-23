<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Pillar\Aggregate\AggregateRegistry;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventReplayer;
use Pillar\Event\StoredEvent;
use Pillar\Event\EventStore;
use Pillar\Facade\Pillar;
use Pillar\Event\EventWindow;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;
use Tests\Fixtures\Projectors\TitleListProjector;

it('pillar:replay-events replays into projectors', function () {
    TitleListProjector::reset();

    $id = DocumentId::new();

    // produce events
    $s0 = Pillar::session();
    $s0->attach(Document::create($id, 'v0'));
    $s0->commit();

    $s1 = Pillar::session();
    $a1 = $s1->find($id);
    $a1->rename('v1');
    $s1->commit();

    // simulate rebuild: clear projector first
    TitleListProjector::reset();

    $exit = Artisan::call('pillar:replay-events', [
        'stream_id' => app(AggregateRegistry::class)->toStreamName($id)
    ]);

    expect($exit)->toBe(0);
    expect(TitleListProjector::$seen)->toBe(['v0', 'v1']);
});

it('parses --from-date/--to-date options and succeeds', function () {
    // Seed one event so replay has something to do
    $id = DocumentId::new();
    $s = Pillar::session();
    $s->attach(Document::create($id, 'hello'));
    $s->commit();

    $this->artisan('pillar:replay-events', [
        'stream_id' => 'null', // all
        '--from-date' => '1970-01-01T00:00:00Z',
        '--to-date' => '2100-01-01T00:00:00Z',
    ])->assertExitCode(Command::SUCCESS);
});

// 3) If the replayer throws, command prints error and returns FAILURE
it('prints error and returns FAILURE when the replayer throws', function () {
    // Fake EventStore whose global stream() throws on iteration but still satisfies Generator type
    $throwingStore = new class implements EventStore {
        public function append(AggregateRootId $id, object $event, ?int $expectedSequence = null): int
        {
            return 0;
        }

        public function getByGlobalSequence(int $sequence): ?StoredEvent
        {
            return null;
        }

        public function recent(int $limit): array
        {
            return [];
        }

        public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator
        {
            // Per-aggregate path: unused in this test, but must satisfy Generator type.
            yield from [];
        }

        public function stream(?EventWindow $window = null, ?string $eventType = null): Generator
        {
            // Global scan path: this is what EventReplayer will use for aggregate_id = 'null'.
            yield from [];
            throw new RuntimeException('boom');
        }
    };

    // Rebind EventStore, then rebuild EventReplayer so it sees our fake
    app()->instance(EventStore::class, $throwingStore);
    app()->forgetInstance(EventReplayer::class);

    $this->artisan('pillar:replay-events', [
        'stream_id' => 'null',
    ])
        ->expectsOutputToContain('Replay failed: boom')
        ->assertExitCode(Command::FAILURE);
});

// 4) Covers b first match() branch: aggregate_id AND event_type provided
it('prints scope when both aggregate and event type are provided', function () {
    // Seed events for a specific aggregate and event type (DocumentRenamed)
    $id = DocumentId::new();
    $s  = Pillar::session();
    $s->attach(Document::create($id, 'v0'));
    $s->commit();

    $s2 = Pillar::session();
    $a2 = $s2->find($id);
    $a2->rename('v1'); // ensure at least one DocumentRenamed
    $s2->commit();

    $streamId = app(AggregateRegistry::class)->toStreamName($id);

    $this->artisan('pillar:replay-events', [
        'stream_id' => $streamId,
        'event_type'   => DocumentRenamed::class,
    ])
        ->expectsOutputToContain("stream $streamId and event " . DocumentRenamed::class)
        ->assertExitCode(Command::SUCCESS);
});