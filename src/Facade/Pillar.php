<?php

namespace Pillar\Facade;

use Illuminate\Support\Facades\Facade;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\AggregateSession;
use Pillar\Event\StoredEvent;
use Pillar\Support\PillarManager;

/**
 * Convenience facade for common Pillar operations.
 *
 * Methods:
 * - session(): fresh AggregateSession (unit of work)
 * - dispatch($command): send to the Command Bus
 * - ask($query): send to the Query Bus
 * - events(...): lazily stream StoredEvent (Generator) with inclusive bounds; dates are UTC
 *
 * @method static AggregateSession session()
 * @method static mixed dispatch(object $command)
 * @method static mixed ask(object $query)
 * @method static \Generator<int, StoredEvent> events(?AggregateRootId $aggregateId = null, ?string $eventType = null, ?int $fromSequence = null, ?int $toSequence = null, ?string $fromDate = null, ?string $toDate = null)
 * @see PillarManager
 * @mixin PillarManager
 */
class Pillar extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PillarManager::class;
    }
}