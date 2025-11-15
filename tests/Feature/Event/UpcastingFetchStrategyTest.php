<?php

use Illuminate\Support\Facades\DB;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\Upcaster;
use Pillar\Event\UpcasterRegistry;
use Pillar\Aggregate\AggregateRegistry;
use Tests\Fixtures\Document\DocumentId;

// A tiny event that REQUIRES 'b' to exist at construction time.
final class RequiresUpcastEvent {
    public function __construct(public string $a, public string $b) {}
}

// Upcaster adds the missing 'b'
final class AddBUpcaster implements Upcaster
{
    public function upcast(array $payload): array
    {
        $payload['b'] = $payload['b'] ?? 'upcasted';
        return $payload;
    }

    public static function eventClass(): string
    {
        return RequiresUpcastEvent::class;
    }

    public static function fromVersion(): int
    {
        return 1;
    }
}

it('uses upcaster path in mapToStoredEvents (toArray → upcast → fromArray → deserialize)', function () {
    // Register the upcaster so UpcasterRegistry::has($eventClass) is true
    app(UpcasterRegistry::class)->register(RequiresUpcastEvent::class, new AddBUpcaster());

    // Ensure a deterministic strategy (any DB strategy will hit mapToStoredEvents)
    config()->set('pillar.fetch_strategies.default', 'db_load_all');
    app()->forgetInstance(EventFetchStrategyResolver::class);

    // Create a stream row that would FAIL to deserialize without upcasting (missing "b")
    $id       = DocumentId::new();
    $streamId = app(AggregateRegistry::class)->toStreamName($id);
    $table    = 'events';

    $nextSeq      = (int) DB::table($table)->max('sequence') + 1;
    $nextStream   = 1;
    $occurred     = now('UTC')->format('Y-m-d H:i:s');

    DB::table($table)->insert([
        'sequence'        => $nextSeq,
        'stream_id'       => $streamId,
        'stream_sequence' => $nextStream,
        'event_type'      => RequiresUpcastEvent::class,
        // Intentionally missing "b"; upcaster will add it
        'event_data'      => json_encode(['a' => 'hello'], JSON_THROW_ON_ERROR),
        'event_version'   => 1,
        'occurred_at'     => $occurred,
        'correlation_id'  => 'C-1',
    ]);

    // Fetch via resolver → strategy → mapToStoredEvents (else branch)
    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    $events   = iterator_to_array($strategy->streamFor($id));

    expect($events)->toHaveCount(1);

    $evt = $events[0]->event;
    expect($evt)->toBeInstanceOf(RequiresUpcastEvent::class)
        ->and($evt->a)->toBe('hello')
        // proves the upcaster path ran
        ->and($evt->b)->toBe('upcasted');
});