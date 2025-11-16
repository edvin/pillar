<?php

namespace Pillar\Testing;

use AssertionError;
use InvalidArgumentException;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Event\EventStore;
use Pillar\Event\EventWindow;
use Pillar\Event\StoredEvent;
use Pillar\Facade\CommandBus;
use Pillar\Repository\EventStoreRepository;
use Throwable;
use function is_subclass_of;

/**
 * Given / When / Then helper for testing command handlers end-to-end.
 *
 * This scenario:
 *  - Seeds history for a single aggregate stream via EventStore::append().
 *  - Dispatches commands through the real CommandBus.
 *  - Captures events newly emitted for that aggregate after each command.
 *  - Lets you assert on emitted events, thrown exceptions, and final aggregate state.
 *
 * Example:
 *
 *   $id = DocumentId::new();
 *
 *   CommandScenario::for($id)
 *       ->given(new DocumentCreated($id, 'v0'))
 *       ->whenCommand(new RenameDocument($id, 'v1'))
 *       ->thenEvents(new DocumentRenamed($id, 'v1'))
 *       ->thenAggregate(function (Document $doc) {
 *           expect($doc->title())->toBe('v1');
 *       });
 */
final class CommandScenario
{
    use TracksAssertions;

    private AggregateRootId $id;

    /** @var list<object> */
    private array $given = [];

    /** @var list<object> */
    private array $emitted = [];

    private ?Throwable $thrown = null;

    /**
     * Last known per-stream version for this aggregate.
     * Used as the starting cursor when fetching new events after a command.
     */
    private ?int $lastKnownStreamSeq = null;

    private function __construct(AggregateRootId $id)
    {
        $this->id = $id;

        $class = $id::aggregateClass();
        if (!is_subclass_of($class, EventSourcedAggregateRoot::class)) {
            throw new InvalidArgumentException(sprintf(
                'CommandScenario expects an EventSourcedAggregateRoot; got %s',
                $class,
            ));
        }
    }

    /**
     * Create a new scenario for a given aggregate id.
     */
    public static function for(AggregateRootId $id): self
    {
        return new self($id);
    }

    /**
     * Seed the aggregate stream with historical events using the real EventStore.
     *
     * @param object ...$events
     */
    public function given(object ...$events): self
    {
        if ($events === []) {
            return $this;
        }

        /** @var EventStore $store */
        $store = app(EventStore::class);

        $expected = $this->lastKnownStreamSeq;

        foreach ($events as $event) {
            $expected = $store->append($this->id, $event, $expected);
        }

        $this->given = array_merge($this->given, $events);
        $this->lastKnownStreamSeq = $expected;

        return $this;
    }

    /**
     * Dispatch a command through the real CommandBus and capture newly emitted events.
     */
    public function whenCommand(object $command): self
    {
        /** @var EventStore $store */
        $store = app(EventStore::class);
        /** @var CommandBus $bus */
        $bus = app(CommandBus::class);

        // Discover current per-stream version if we don't know it yet.
        $before = $this->lastKnownStreamSeq ?? $this->discoverCurrentStreamVersion($store);

        $this->thrown = null;
        $this->emitted = [];

        try {
            $bus->dispatch($command);
        } catch (Throwable $e) {
            $this->thrown = $e;
        }

        // Fetch events strictly AFTER the previous version for this stream.
        $window = EventWindow::afterStreamSeq($before ?? 0);

        $stored = iterator_to_array($store->streamFor($this->id, $window));

        $this->emitted = array_map(
            static fn(StoredEvent $e): object => $e->event,
            $stored,
        );

        // Update last known version for subsequent commands.
        if ($stored !== []) {
            /** @var StoredEvent $last */
            $last = end($stored);
            $this->lastKnownStreamSeq = $last->streamSequence;
        } else {
            $this->lastKnownStreamSeq = $before;
        }

        return $this;
    }

    /** Convenience alias so you can write ->when(...) with commands. */
    public function when(object $command): self
    {
        return $this->whenCommand($command);
    }

    /** @return list<object> */
    public function emittedEvents(): array
    {
        return $this->emitted;
    }

    public function thrown(): ?Throwable
    {
        return $this->thrown;
    }

    /**
     * Assert that the newly emitted events match exactly.
     *
     * @param object ...$expected
     */
    public function thenEvents(object ...$expected): self
    {
        if ($this->emitted != $expected) {
            throw new AssertionError(
                'Emitted events did not match expected.' .
                "\nExpected: " . var_export($expected, true) .
                "\nActual:   " . var_export($this->emitted, true),
            );
        }

        self::bumpAssertionCount();

        return $this;
    }

    /** Assert that no events were emitted by the last command. */
    public function thenNoEvents(): self
    {
        if ($this->emitted !== []) {
            throw new AssertionError(
                'Expected no emitted events, got: ' . var_export($this->emitted, true),
            );
        }

        self::bumpAssertionCount();

        return $this;
    }

    /** Assert that the last command threw an exception of the given type. */
    public function thenException(string $class): self
    {
        if ($this->thrown === null) {
            throw new AssertionError("Expected exception of type {$class}, but none was thrown.");
        }

        if (!\is_a($this->thrown, $class)) {
            throw new AssertionError(sprintf(
                'Expected exception of type %s, got %s',
                $class,
                get_debug_type($this->thrown),
            ));
        }

        self::bumpAssertionCount();

        return $this;
    }

    /** Assert that the last command did not throw. */
    public function thenNoException(): self
    {
        if ($this->thrown !== null) {
            throw new AssertionError(sprintf(
                'Expected no exception, got %s',
                get_debug_type($this->thrown),
            ));
        }

        self::bumpAssertionCount();

        return $this;
    }

    /**
     * Assert against the latest aggregate state as loaded from the EventStoreRepository.
     *
     * @param callable $assert fn(EventSourcedAggregateRoot $aggregate): void
     */
    public function thenAggregate(callable $assert): self
    {
        $aggregate = $this->aggregate();
        $assert($aggregate);

        self::bumpAssertionCount();

        return $this;
    }

    /**
     * Load the latest aggregate instance from the repository.
     */
    public function aggregate(): EventSourcedAggregateRoot
    {
        /** @var EventStoreRepository $repo */
        $repo = app(EventStoreRepository::class);

        $loaded = $repo->find($this->id, null);

        if ($loaded === null) {
            throw new AssertionError('Aggregate not found after scenario.');
        }

        // LoadedAggregate is a simple value object; we only care about the aggregate here.
        /** @var EventSourcedAggregateRoot $aggregate */
        $aggregate = $loaded->aggregate;

        return $aggregate;
    }

    /**
     * Discover the current per-stream sequence for this aggregate by scanning its stream.
     * Suitable for tests; production code should prefer EventStore::recent().
     */
    private function discoverCurrentStreamVersion(EventStore $store): ?int
    {
        $events = iterator_to_array($store->streamFor($this->id, null));

        if ($events === []) {
            return null;
        }

        /** @var StoredEvent $last */
        $last = end($events);

        return $last->streamSequence;
    }
}