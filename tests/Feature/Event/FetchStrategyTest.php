<?php

use Pillar\Event\DatabaseEventStore;
use Pillar\Event\EventReplayer;
use Pillar\Event\Fetch\Database\DatabaseCursorFetchStrategy;
use Pillar\Event\Fetch\Database\DatabaseLoadAllStrategy;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Facade\Pillar;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;

dataset('strategies', [
    ['db_load_all', DatabaseLoadAllStrategy::class],
    ['db_streaming', DatabaseCursorFetchStrategy::class],
]);

it('load() applies afterAggregateSequence filter', function (string $default, string $expectedClass) {
    // Build stream with aggregate_sequence 1..4
    $id  = DocumentId::new();
    $s   = Pillar::session();
    $s->add(Document::create($id, 'v0')); // seq 1
    $s->commit();

    foreach (['v1', 'v2', 'v3'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t); // seq 2..4
        $sx->commit();
    }

    // Force strategy and refresh singletons
    config()->set('pillar.fetch_strategies.default', $default);
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);

    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    expect($strategy)->toBeInstanceOf($expectedClass);

    // afterAggregateSequence = 2 → only 3,4
    $rows = array_map(
        fn($e) => $e->aggregateSequence,
        iterator_to_array($strategy->load($id, 2))
    );

    expect($rows)->toEqual([3, 4]);
})->with('strategies');

it('all() applies eventType filter', function (string $default, string $expectedClass) {
    // Create mixed event types: 1x created, 3x renamed
    $id  = DocumentId::new();
    $s   = Pillar::session();
    $s->add(Document::create($id, 'v0')); // DocumentCreated
    $s->commit();

    foreach (['v1', 'v2', 'v3'] as $t) {
        $sx = Pillar::session();
        $a  = $sx->find($id);
        $a->rename($t); // DocumentRenamed
        $sx->commit();
    }

    // Force strategy and refresh singletons
    config()->set('pillar.fetch_strategies.default', $default);
    app()->forgetInstance(EventFetchStrategyResolver::class);
    app()->forgetInstance(DatabaseEventStore::class);
    app()->forgetInstance(EventReplayer::class);

    $strategy = app(EventFetchStrategyResolver::class)->resolve($id);
    expect($strategy)->toBeInstanceOf($expectedClass);

    // Filter to only DocumentRenamed
    $renamed = iterator_to_array($strategy->all($id, DocumentRenamed::class));
    $types   = array_unique(array_map(fn($e) => $e->eventType, $renamed));

    expect($renamed)->toHaveCount(3)
        ->and($types)->toEqual([DocumentRenamed::class]);

    // Sanity: created-only is 1
    $created = iterator_to_array($strategy->all($id, DocumentCreated::class));
    expect($created)->toHaveCount(1)
        ->and($created[0]->eventType)->toBe(DocumentCreated::class);
})->with('strategies');