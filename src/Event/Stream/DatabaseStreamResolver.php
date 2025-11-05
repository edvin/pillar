<?php

namespace Pillar\Event\Stream;

use Illuminate\Container\Attributes\Config;
use Pillar\Aggregate\AggregateRootId;

/**
 * Resolves a database table name for the given aggregate root.
 *
 * This resolver supports flexible database event stream partitioning strategies:
 *  - default table ('events')
 *  - per aggregate type (mapped in config)
 *  - per aggregate ID (creates a unique table name per aggregate instance)
 *
 * These strategies can be used for multi-tenancy, event stream scaling, or operational isolation.
 *
 * The per-aggregate ID mode supports different naming formats controlled by the
 * `per_aggregate_id_format` configuration option:
 *  - 'default_id': table name is "{defaultTable}_{aggregateId}"
 *  - 'type_id': table name is "{defaultTable}_{aggregateType}_{aggregateId}"
 */
class DatabaseStreamResolver implements StreamResolver
{
    public function __construct(
        #[Config('pillar.stream_resolver.options.default', 'events')]
        private string $defaultTable,

        #[Config('pillar.stream_resolver.options.per_aggregate_type', [])]
        private array  $aggregateOverrides,

        #[Config('pillar.stream_resolver.options.per_aggregate_id', false)]
        private bool   $perAggregateId,

        #[Config('pillar.stream_resolver.options.per_aggregate_id_format', 'default_id')]
        private string $perAggregateIdFormat,
    )
    {
    }

    public function resolve(?AggregateRootId $aggregateId): string
    {
        if ($aggregateId === null) {
            return $this->defaultTable;
        }

        $aggregateClass = $aggregateId->aggregateClass();

        // Explicit per-type table
        if (isset($this->aggregateOverrides[$aggregateClass])) {
            return $this->aggregateOverrides[$aggregateClass];
        }

        // Optional per-instance table
        if ($this->perAggregateId) {
            return match ($this->perAggregateIdFormat) {
                'type_id' => sprintf(
                    '%s_%s',
                    strtolower(class_basename($aggregateClass)),
                    $aggregateId
                ),
                default => sprintf('%s_%s', $this->defaultTable, $aggregateId),
            };
        }

        // Fallback to global default
        return $this->defaultTable;
    }
}