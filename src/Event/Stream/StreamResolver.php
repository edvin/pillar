<?php

namespace Pillar\Event\Stream;

use Pillar\Aggregate\AggregateRootId;

/**
 * Defines how to resolve the underlying stream or table name for an aggregate root.
 *
 * Implementations may return different targets depending on aggregate type,
 * instance, tenant, or other context.
 */
interface StreamResolver
{
    /**
     * Resolves the stream or table name for the given aggregate root ID.
     *
     * If null, the default stream is returned.
     *
     * @param ?AggregateRootId $aggregateId
     * @return string
     */
    public function resolve(?AggregateRootId $aggregateId): string;
}