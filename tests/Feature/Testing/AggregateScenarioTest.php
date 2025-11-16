<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Pillar\Testing\AggregateScenario;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;
use Pillar\Aggregate\AggregateRootId;

it('throws when id does not point to an event-sourced aggregate', function () {
    $badId = new readonly class(Str::uuid()->toString()) extends AggregateRootId {
        public static function aggregateClass()
        {
            return stdClass::class;
        }
    };

    expect(fn () => AggregateScenario::for($badId))
        ->toThrow(InvalidArgumentException::class);
});

it('rehydrates given events into the aggregate', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'));

    $aggregate = $scenario->aggregate();

    expect($aggregate)->toBeInstanceOf(Document::class)
        ->and($aggregate->title())->toBe('v0');
});

it('rehydrates lazily when no given events are present', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id);

    $aggregate = $scenario->aggregate();

    expect($aggregate)->toBeInstanceOf(Document::class);
});

it('executes whenAggregate and captures emitted events', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(fn (Document $doc) => $doc->rename('v1'));

    expect($scenario->emittedEvents())->toEqual([
        new DocumentRenamed($id, 'v1'),
    ])->and($scenario->thrown())->toBeNull();
});

it('supports the when() alias', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->when(fn (Document $doc) => $doc->rename('v1'));

    expect($scenario->emittedEvents())->toEqual([
        new DocumentRenamed($id, 'v1'),
    ]);
});

it('captures exceptions thrown by whenAggregate', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(function (Document $doc) {
            throw new DomainException('boom');
        });

    $e = $scenario->thrown();

    expect($e)->toBeInstanceOf(DomainException::class)
        ->and($e->getMessage())->toBe('boom');

    $scenario->thenException(DomainException::class);
});

it('thenNoException passes when no exception was thrown', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(fn (Document $doc) => $doc->rename('v1'));

    $scenario->thenNoException();

    expect($scenario->thrown())->toBeNull();
});


it('thenEvents throws when emitted events do not match', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(fn (Document $doc) => $doc->rename('v1'));

    expect(fn () => $scenario->thenEvents(new DocumentRenamed($id, 'other')))
        ->toThrow(AssertionError::class);
});

it('thenEvents passes when emitted events match', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(fn (Document $doc) => $doc->rename('v1'));

    // Happy path: should not throw and should return the same scenario instance
    $result = $scenario->thenEvents(new DocumentRenamed($id, 'v1'));

    expect($result)->toBe($scenario);
});


it('thenNoEvents throws when events were emitted', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(fn (Document $doc) => $doc->rename('v1'));

    expect(fn () => $scenario->thenNoEvents())
        ->toThrow(AssertionError::class);
});

it('thenNoEvents passes when no events were emitted', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(function (Document $doc) {
            // no-op: do not emit any events
        });

    // Happy path: should not throw and should return the same scenario instance
    $result = $scenario->thenNoEvents();

    expect($result)->toBe($scenario);
});

it('thenException throws when no exception was thrown', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(fn (Document $doc) => $doc->rename('v1'));

    expect(fn () => $scenario->thenException(RuntimeException::class))
        ->toThrow(AssertionError::class);
});

it('thenException throws when exception type does not match', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(function (Document $doc) {
            throw new DomainException('boom');
        });

    expect(fn () => $scenario->thenException(RuntimeException::class))
        ->toThrow(AssertionError::class);
});

it('thenNoException throws when an exception was thrown', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(function (Document $doc) {
            throw new DomainException('boom');
        });

    expect(fn () => $scenario->thenNoException())
        ->toThrow(AssertionError::class);
});

it('thenAggregate passes the current aggregate instance for assertions', function () {
    $id = DocumentId::new();

    AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(fn (Document $doc) => $doc->rename('v1'))
        ->thenAggregate(function (Document $doc) {
            expect($doc->title())->toBe('v1');
        });
});

it('supports fixing logical time via at()', function () {
    $id = DocumentId::new();
    $time = CarbonImmutable::parse('2025-01-01T12:00:00Z');

    // CarbonImmutable branch
    $scenario = AggregateScenario::for($id)
        ->at($time)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(fn (Document $doc) => $doc->rename('v1'));

    expect($scenario->emittedEvents())->toHaveCount(1);

    // string branch
    AggregateScenario::for($id)
        ->at('2025-02-01T00:00:00Z')
        ->given(new DocumentCreated($id, 'v0'))
        ->whenAggregate(fn (Document $doc) => $doc->rename('v1'));
});

it('can reset given events and rehydrate a fresh aggregate', function () {
    $id = DocumentId::new();

    $scenario = AggregateScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'));

    $first = $scenario->aggregate();
    expect($first->title())->toBe('v0');

    // Overwrite history
    $scenario->given(new DocumentCreated($id, 'v1'));

    $second = $scenario->aggregate();
    expect($second)->toBeInstanceOf(Document::class)
        ->and($second->title())->toBe('v1');
});
