<?php

namespace Pillar\Aggregate;

/**
 * Generic ID for cases where we only have a UUID (e.g., CLI replay filters)
 * and do not know the aggregate class. Never used on write paths.
 */
final readonly class GenericAggregateId extends AggregateRootId
{
    public static function aggregateClass(): string
    {
        // Not used on replay filter paths; satisfies abstract requirement.
        return \stdClass::class;
    }
}