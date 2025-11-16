<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventStore;
use Pillar\Facade\CommandBus;
use Pillar\Testing\CommandScenario;
use Tests\Fixtures\Document\Document;
use Tests\Fixtures\Document\DocumentCreated;
use Tests\Fixtures\Document\DocumentId;
use Tests\Fixtures\Document\DocumentRenamed;

/**
 * Simple test commands used only in these tests.
 */
class CreateDocumentCommand
{
    public function __construct(
        public DocumentId $id,
        public string $title,
    ) {}
}

class RenameDocumentCommand
{
    public function __construct(
        public DocumentId $id,
        public string $title,
    ) {}
}

class ExplodingCommand
{
    public function __construct(
        public DocumentId $id,
    ) {}
}

class NoopCommand
{
    public function __construct(
        public DocumentId $id,
    ) {}
}

/**
 * For all tests in this file, override the CommandBus binding with a fake that:
 *  - Appends events directly to the EventStore for known commands
 *  - Throws a DomainException for ExplodingCommand
 */
beforeEach(function () {
    /** @var EventStore $store */
    $store = app(EventStore::class);

    $fakeBus = new class($store) {
        public function __construct(
            private EventStore $store,
        ) {}

        public function dispatch(object $command): void
        {
            if ($command instanceof CreateDocumentCommand) {
                $this->store->append(
                    $command->id,
                    new DocumentCreated($command->id, $command->title),
                );
                return;
            }

            if ($command instanceof RenameDocumentCommand) {
                $this->store->append(
                    $command->id,
                    new DocumentRenamed($command->id, $command->title),
                );
                return;
            }

            if ($command instanceof ExplodingCommand) {
                throw new DomainException('boom');
            }

            // Unknown command types are ignored in these tests.
        }
    };

    app()->instance(CommandBus::class, $fakeBus);
});

it('throws when id does not point to an event-sourced aggregate', function () {
    $badId = new readonly class(Str::uuid()->toString()) extends AggregateRootId {
        public static function aggregateClass()
        {
            return \stdClass::class;
        }
    };

    expect(fn () => CommandScenario::for($badId))
        ->toThrow(InvalidArgumentException::class);
});

it('seeds history via given() and captures only newly emitted events', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->given(new DocumentCreated($id, 'v0')) // history
        ->whenCommand(new RenameDocumentCommand($id, 'v1')); // new event

    // Only the newly emitted event should be captured
    expect($scenario->emittedEvents())->toEqual([
        new DocumentRenamed($id, 'v1'),
    ]);

    // And the aggregate reconstructed from the repository should see full history
    $scenario->thenAggregate(function (Document $doc) {
        expect($doc->title())->toBe('v1');
    });
});

it('thenEvents passes when emitted events match', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenCommand(new RenameDocumentCommand($id, 'v1'));

    // Happy-path assertion: should not throw, and exercise chaining
    $scenario->thenEvents(new DocumentRenamed($id, 'v1'))->thenNoException();

    // And emittedEvents() still exposes the same list
    expect($scenario->emittedEvents())->toEqual([
        new DocumentRenamed($id, 'v1'),
    ]);
});

it('discovers current stream version when no history was given', function () {
    $id = DocumentId::new();

    // At this point, the stream is empty and lastKnownStreamSeq is null.
    // CommandScenario will call discoverCurrentStreamVersion(), which returns null,
    // then append a DocumentCreated event via the fake bus.
    $scenario = CommandScenario::for($id)
        ->whenCommand(new CreateDocumentCommand($id, 'v0'));

    expect($scenario->emittedEvents())->toEqual([
        new DocumentCreated($id, 'v0'),
    ]);

    $scenario->thenAggregate(function (Document $doc) {
        expect($doc->title())->toBe('v0');
    });
});

it('uses discoverCurrentStreamVersion when store already contains events', function () {
    $id = DocumentId::new();

    /** @var EventStore $store */
    $store = app(EventStore::class);

    // Seed history directly in the EventStore so that discoverCurrentStreamVersion()
    // has something to find (stream version = 1) even though we never called given().
    $store->append($id, new DocumentCreated($id, 'v0'));

    $scenario = CommandScenario::for($id)
        ->whenCommand(new RenameDocumentCommand($id, 'v1'));

    // Only the rename event should be captured as "new"
    expect($scenario->emittedEvents())->toEqual([
        new DocumentRenamed($id, 'v1'),
    ]);

    // The aggregate should see both created + renamed
    $scenario->thenAggregate(function (Document $doc) {
        expect($doc->title())->toBe('v1');
    });
});

it('supports the when() alias for commands', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->when(new RenameDocumentCommand($id, 'v1'));

    expect($scenario->emittedEvents())->toEqual([
        new DocumentRenamed($id, 'v1'),
    ]);
});

it('thenNoEvents passes when no events were emitted', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->whenCommand(new NoopCommand($id));

    // No events should have been captured
    expect($scenario->emittedEvents())->toBe([]);

    // And the assertion helper should succeed (not throw)
    $scenario->thenNoEvents();
});

it('given() is a no-op when called without events', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id);

    // Calling given() with no arguments should just return the same instance
    $result = $scenario->given();

    expect($result)->toBe($scenario);
    // And still no emitted events / history recorded at this point
    expect($scenario->emittedEvents())->toBe([]);
});

it('captures exceptions thrown by the command handler and exposes them via thrown()', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenCommand(new ExplodingCommand($id));

    $e = $scenario->thrown();

    expect($e)->toBeInstanceOf(DomainException::class)
        ->and($e->getMessage())->toBe('boom');

    $scenario->thenException(DomainException::class);
});

it('thenNoException passes when no exception was thrown', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenCommand(new RenameDocumentCommand($id, 'v1'));

    $scenario->thenNoException();

    expect($scenario->thrown())->toBeNull();
});

it('thenEvents throws when emitted events do not match', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenCommand(new RenameDocumentCommand($id, 'v1'));

    expect(fn () => $scenario->thenEvents(new DocumentRenamed($id, 'other')))
        ->toThrow(AssertionError::class);
});

it('thenNoEvents throws when events were emitted', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenCommand(new RenameDocumentCommand($id, 'v1'));

    expect(fn () => $scenario->thenNoEvents())
        ->toThrow(AssertionError::class);
});

it('thenException throws when no exception was thrown', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenCommand(new RenameDocumentCommand($id, 'v1'));

    expect(fn () => $scenario->thenException(RuntimeException::class))
        ->toThrow(AssertionError::class);
});

it('thenException throws when exception type does not match', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenCommand(new ExplodingCommand($id));

    expect(fn () => $scenario->thenException(RuntimeException::class))
        ->toThrow(AssertionError::class);
});

it('thenNoException throws when an exception was thrown', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id)
        ->given(new DocumentCreated($id, 'v0'))
        ->whenCommand(new ExplodingCommand($id));

    expect(fn () => $scenario->thenNoException())
        ->toThrow(AssertionError::class);
});

it('thenAggregate fails when the aggregate does not exist after the scenario', function () {
    $id = DocumentId::new();

    $scenario = CommandScenario::for($id);

    expect(fn () => $scenario->aggregate())
        ->toThrow(AssertionError::class, 'Aggregate not found after scenario.');
});
