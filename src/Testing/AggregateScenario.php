<?php

namespace Pillar\Testing;

use AssertionError;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\EventSourcedAggregateRoot;
use Pillar\Event\EventContext;
use Throwable;

/**
 * Given / When / Then helper for testing event-sourced aggregates in isolation.
 *
 * Typical usage:
 *
 *   $id = DocumentId::new();
 *
 *   AggregateScenario::for($id)
 *       ->given(new DocumentCreated($id, 'v0'))
 *       ->whenAggregate(fn (Document $doc) => $doc->rename('v1'))
 *       ->thenEvents(new DocumentRenamed($id, 'v1'))
 *       ->thenAggregate(function (Document $doc) {
 *           expect($doc->title())->toBe('v1');
 *       });
 *
 * You can:
 *   - Call aggregate methods directly via whenAggregate()/when()
 *   - Chain multiple whenAggregate() calls on the same instance
 *   - Inspect emitted events and thrown exceptions
 *   - Inspect the final aggregate state via aggregate()/thenAggregate()
 *   - Optionally fix the logical “now” via at()
 */
final class AggregateScenario
{
    use TracksAssertions;

    private AggregateRootId $id;

    /** @var list<object> */
    private array $given = [];

    /** @var list<object> */
    private array $emitted = [];

    private ?Throwable $thrown = null;

    private ?CarbonImmutable $time = null;

    private ?EventSourcedAggregateRoot $aggregate = null;

    private function __construct(AggregateRootId $id)
    {
        $this->id = $id;

        $class = $id::aggregateClass();
        if (! is_subclass_of($class, EventSourcedAggregateRoot::class)) {
            throw new InvalidArgumentException(sprintf(
                'AggregateScenario expects an EventSourcedAggregateRoot; got %s',
                $class,
            ));
        }
    }

    /**
     * Factory for a new scenario.
     */
    public static function for(AggregateRootId $id): self
    {
        return new self($id);
    }

    /**
     * Fix the logical "now" for this scenario.
     * This timestamp will be used for EventContext::initialize() in whenAggregate().
     */
    public function at(CarbonImmutable|string $time): self
    {
        $this->time = $time instanceof CarbonImmutable
            ? $time->setTimezone('UTC')
            : new CarbonImmutable($time, 'UTC');

        return $this;
    }

    /**
     * Seed the aggregate with historical events.
     * These are re-applied during the first rehydrate() call.
     *
     * @param object ...$events
     */
    public function given(object ...$events): self
    {
        $this->given = $events;
        $this->aggregate = null; // force rehydrate on next use

        return $this;
    }

    /**
     * Execute a domain action directly on the aggregate.
     *
     * The same aggregate instance is reused across multiple whenAggregate()
     * calls, so you can build multi-step scenarios.
     *
     * @param callable $action fn(EventSourcedAggregateRoot $aggregate): void
     */
    public function whenAggregate(callable $action): self
    {
        // New logical operation → fresh event context
        EventContext::clear();

        if ($this->aggregate === null) {
            $this->aggregate = $this->rehydrate();
        }

        // Only capture events from this action, not from history or previous steps
        $this->aggregate->clearRecordedEvents();
        $this->thrown = null;

        EventContext::initialize(
            occurredAt: $this->time,
            correlationId: 'test-'.spl_object_id($this),
            aggregateRootId: $this->aggregate->id(),
        );

        try {
            $action($this->aggregate);
        } catch (Throwable $e) {
            $this->thrown = $e;
        }

        $this->emitted = $this->aggregate->recordedEvents();

        EventContext::clear();

        return $this;
    }

    /**
     * Convenience alias, so you can write ->when(...) if you like.
     */
    public function when(callable $action): self
    {
        return $this->whenAggregate($action);
    }

    /**
     * Return the events emitted by the last whenAggregate() call.
     *
     * @return list<object>
     */
    public function emittedEvents(): array
    {
        return $this->emitted;
    }

    /**
     * Return the exception (if any) thrown by the last whenAggregate() call.
     */
    public function thrown(): ?Throwable
    {
        return $this->thrown;
    }

    /**
     * Assert that the emitted events from the last step match exactly.
     *
     * @param object ...$expected
     */
    public function thenEvents(object ...$expected): self
    {
        if ($this->emitted != $expected) {
            throw new AssertionError(
                'Emitted events did not match expected.'.
                "\nExpected: ".var_export($expected, true).
                "\nActual:   ".var_export($this->emitted, true),
            );
        }

        self::bumpAssertionCount();

        return $this;
    }

    /**
     * Assert that no events were emitted in the last step.
     */
    public function thenNoEvents(): self
    {
        if ($this->emitted !== []) {
            throw new AssertionError(
                'Expected no emitted events, got: '.var_export($this->emitted, true),
            );
        }

        self::bumpAssertionCount();

        return $this;
    }

    /**
     * Assert that the last step threw an exception of the given type.
     */
    public function thenException(string $class): self
    {
        if ($this->thrown === null) {
            throw new AssertionError("Expected exception of type {$class}, but none was thrown.");
        }

        if (! is_a($this->thrown, $class)) {
            throw new AssertionError(sprintf(
                'Expected exception of type %s, got %s',
                $class,
                get_debug_type($this->thrown),
            ));
        }

        self::bumpAssertionCount();

        return $this;
    }

    /**
     * Assert that the last step did not throw.
     */
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
     * Assert against the current aggregate instance.
     *
     * Example:
     *
     *   ->thenAggregate(function (Document $doc) {
     *       expect($doc->title())->toBe('v1');
     *   });
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
     * Get the current aggregate instance (after all given/when steps).
     */
    public function aggregate(): EventSourcedAggregateRoot
    {
        if ($this->aggregate === null) {
            $this->aggregate = $this->rehydrate();
        }

        return $this->aggregate;
    }

    /**
     * Build an aggregate instance by replaying the "given" events.
     *
     * This mirrors what EventStoreRepository does: it calls the aggregate's
     * parameterless constructor and drives state via apply().
     */
    private function rehydrate(): EventSourcedAggregateRoot
    {
        /** @var class-string<EventSourcedAggregateRoot> $class */
        $class = $this->id::aggregateClass();

        /** @var EventSourcedAggregateRoot $aggregate */
        $aggregate = new $class();

        if ($this->given === []) {
            return $aggregate;
        }

        // Reconstitution path: apply historical events with isReconstituting() = true
        EventContext::clear();
        EventContext::initialize(
            correlationId: 'test-rehydrate-'.spl_object_id($this),
            reconstituting: true,
        );

        foreach ($this->given as $event) {
            $aggregate->apply($event);
        }

        // History should not show up as "newly recorded" events
        $aggregate->clearRecordedEvents();

        EventContext::clear();

        return $aggregate;
    }
}