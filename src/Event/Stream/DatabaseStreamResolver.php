<?php

namespace Pillar\Event\Stream;

use Illuminate\Container\Attributes\Config;
use Pillar\Aggregate\AggregateRootId;

final class DatabaseStreamResolver implements StreamResolver
{
    public function __construct(
        // Global fallback stream/table
        #[Config('pillar.stream_resolver.options.default', 'events')]
        private readonly string $defaultTable = 'events',

        // Explicit per-type mapping: [ FQCN => stream_name ]
        #[Config('pillar.stream_resolver.options.per_aggregate_type', [])]
        private readonly array $aggregateOverrides = [],

        // Whether to build a per-instance stream name when no per-type override applies
        #[Config('pillar.stream_resolver.options.per_aggregate_id', false)]
        private readonly bool $perAggregateId = false,

        // Format for per-aggregate streams: 'default_id' | 'type_id'
        #[Config('pillar.stream_resolver.options.per_aggregate_id_format', 'default_id')]
        private readonly string $perAggregateIdFormat = 'default_id',
    ) {}

    public function resolve(?AggregateRootId $aggregateId): string
    {
        if ($aggregateId === null) {
            return $this->defaultTable;
        }

        $aggregateClass = $aggregateId->aggregateClass();

        // 1) Explicit per-type table
        if (isset($this->aggregateOverrides[$aggregateClass])) {
            return $this->aggregateOverrides[$aggregateClass];
        }

        // 2) Optional per-instance table
        if ($this->perAggregateId) {
            if ($this->perAggregateIdFormat === 'type_id') {
                // lowercased base name, e.g. "document_<uuid>"
                return sprintf('%s_%s', strtolower(class_basename($aggregateClass)), $aggregateId->value());
            }
            // default_id → "events_<uuid>"
            return sprintf('%s_%s', $this->defaultTable, $aggregateId->value());
        }

        // 3) Fallback to global default
        return $this->defaultTable;
    }
}