<?php

namespace Pillar\Support;

use Pillar\Aggregate\AggregateSession;
use Pillar\Bus\CommandBusInterface;
use Pillar\Bus\QueryBusInterface;
use Generator;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventReplayer;
use Pillar\Event\StoredEvent;

/**
 * Convenience entry point for common Pillar operations used in apps and tests.
 */
final class PillarManager
{
    public function __construct(
        private readonly CommandBusInterface $commands,
        private readonly QueryBusInterface $queries,
        private readonly EventReplayer $replayer,
    ) {}

    /**
     * Get a fresh AggregateSession (unit of work).
     */
    public function session(): AggregateSession
    {
        return app(AggregateSession::class);
    }

    /**
     * Dispatch a command through the Command Bus.
     */
    public function dispatch(object $command): mixed
    {
        return $this->commands->dispatch($command);
    }

    /**
     * Ask a query through the Query Bus.
     *
     * @return mixed The query result
     */
    public function ask(object $query): mixed
    {
        return $this->queries->ask($query);
    }

    /**
     * Lazily stream events with optional filters. Bounds are inclusive; dates are UTC.
     * Mirrors the semantics used by the EventReplayer.
     *
     * @param AggregateRootId|null $aggregateId Restrict to a single aggregate (or null for all)
     * @param string|null          $eventType    Restrict to a single event class (FQCN), or null for all
     * @param int|null             $fromSequence Lower bound on global sequence (inclusive)
     * @param int|null             $toSequence   Upper bound on global sequence (inclusive)
     * @param string|null          $fromDate     Lower bound on occurred_at (inclusive), UTC ISO-8601 or Carbon-parseable
     * @param string|null          $toDate       Upper bound on occurred_at (inclusive), UTC ISO-8601 or Carbon-parseable
     *
     * @return Generator<int, StoredEvent>
     */
    public function events(
        ?AggregateRootId $aggregateId = null,
        ?string $eventType = null,
        ?int $fromSequence = null,
        ?int $toSequence = null,
        ?string $fromDate = null,
        ?string $toDate = null
    ): Generator
    {
        return $this->replayer->stream(
            $aggregateId,
            $eventType,
            $fromSequence,
            $toSequence,
            $fromDate,
            $toDate
        );
    }
}