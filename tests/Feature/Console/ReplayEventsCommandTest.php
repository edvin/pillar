<?php

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
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
        'aggregate_id' => $id->value(),
    ]);

    expect($exit)->toBe(0);
    expect(TitleListProjector::$seen)->toBe(['v0', 'v1']);
});

// 1) Invalid aggregate UUID → hits catch (InvalidArgumentException) and returns FAILURE.
it('fails with an invalid aggregate_id and prints the validation error', function () {
    $this->artisan('pillar:replay-events', [
        'aggregate_id' => 'not-a-uuid',
    ])
        ->expectsOutputToContain('Invalid UUID')
        ->assertExitCode(Command::FAILURE);
});

// 2) Parse --from-date/--to-date and succeed (drives Carbon parse branch)
it('parses --from-date/--to-date options and succeeds', function () {
    // Seed one event so replay has something to do
    $id = DocumentId::new();
    $s = Pillar::session();
    $s->attach(Document::create($id, 'hello'));
    $s->commit();

    $this->artisan('pillar:replay-events', [
        'aggregate_id' => 'null', // all
        '--from-date' => '1970-01-01T00:00:00Z',
        '--to-date' => '2100-01-01T00:00:00Z',
    ])->assertExitCode(Command::SUCCESS);
});

// 3) If the replayer throws, command prints error and returns FAILURE
it('prints error and returns FAILURE when the replayer throws', function () {
    // Fake EventStore whose all() throws on iteration but still satisfies Generator type
    $throwingStore = new class implements EventStore {
        public function append(AggregateRootId $id, object $event, ?int $expectedSequence = null): int
        {
            return 0;
        }

        public function load(AggregateRootId $id, ?EventWindow $window = null): Generator
        {
            if (false) { yield; } // empty generator
        }

        public function all(?AggregateRootId $aggregateId = null, ?EventWindow $window = null, ?string $eventType = null): Generator
        {
            throw new \RuntimeException('boom');
            if (false) { yield; } // keep generator type
        }

        public function getByGlobalSequence(int $sequence): ?StoredEvent
        {
            return null;
        }

        public function resolveAggregateIdClass(string $aggregateId): ?string
        {
            return null;
        }
    };

    // Rebind EventStore, then rebuild EventReplayer so it sees our fake
    app()->instance(EventStore::class, $throwingStore);
    app()->forgetInstance(EventReplayer::class);

    $this->artisan('pillar:replay-events', [
        'aggregate_id' => 'null',
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

    $this->artisan('pillar:replay-events', [
        'aggregate_id' => $id->value(),
        'event_type'   => DocumentRenamed::class,
    ])
        ->expectsOutputToContain("aggregate {$id->value()} and event " . DocumentRenamed::class)
        ->assertExitCode(Command::SUCCESS);
});