<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\EventWindow;
use Pillar\Event\StoredEvent;
use Pillar\Event\Stream\StreamResolver;
use Pillar\Event\UpcasterRegistry;
use Pillar\Serialization\ObjectSerializer;

abstract class AbstractDatabaseFetchStrategy
{
    public function __construct(
        protected ObjectSerializer   $serializer,
        protected EventAliasRegistry $aliases,
        protected UpcasterRegistry   $upcasters,
        protected StreamResolver     $streamResolver,
    )
    {
    }

    /**
     * Base query for a specific aggregate id (no ordering yet).
     */
    protected function perAggregateBase(AggregateRootId $id): Builder
    {
        $table = $this->streamResolver->resolve($id);

        return DB::table($table)
            ->where('aggregate_id', $id->value())
            ->select('*')
            ->selectRaw('? as aggregate_id_class', [$id::class]);
    }

    /**
     * Base query for global reads
     */
    protected function globalBase(): Builder
    {
        $table = $this->streamResolver->resolve(null);

        // For global queries we join in the aggregate_id_class, only used by UI to reconstruct aggregates for display
        return DB::table($table . ' as e')
            ->leftJoin('aggregate_versions as av', 'av.aggregate_id', '=', 'e.aggregate_id')
            ->select('e.*', 'av.aggregate_id_class');
    }

    /**
     * Apply an EventWindow's bounds to a query (per-aggregate).
     */
    protected function applyPerAggregateWindow(Builder $query, ?EventWindow $window): void
    {
        if ($window === null) {
            return;
        }

        // Per-aggregate bounds (only meaningful when scanning a single aggregate)
        if ($window->afterAggregateSequence !== null && $window->afterAggregateSequence > 0) {
            $query->where('aggregate_sequence', '>', (int)$window->afterAggregateSequence);
        }
        if ($window->toAggregateSequence !== null) {
            $query->where('aggregate_sequence', '<=', (int)$window->toAggregateSequence);
        }

        // Also apply global/time bounds
        $this->applyGlobalAndTimeBounds($query, $window);
    }

    /**
     * Apply an EventWindow's bounds to a query (global).
     */
    protected function applyGlobalWindow(Builder $query, ?EventWindow $window): void
    {
        if ($window === null) {
            return;
        }

        // Per-aggregate bounds are intentionally NOT applied here.
        $this->applyGlobalAndTimeBounds($query, $window);
    }

    /**
     * Apply global sequence and time bounds shared by both per-aggregate and global scans.
     * Semantics: "after*" is exclusive; "to*" is inclusive. Times are UTC, formatted as Y-m-d H:i:s.
     */
    private function applyGlobalAndTimeBounds(Builder $query, EventWindow $window): void
    {
        // Global bounds
        if ($window->afterGlobalSequence !== null && $window->afterGlobalSequence > 0) {
            $query->where('sequence', '>', (int)$window->afterGlobalSequence);
        }
        if ($window->toGlobalSequence !== null) {
            $query->where('sequence', '<=', (int)$window->toGlobalSequence);
        }

        // Time bounds (UTC). `after` is exclusive, `to` is inclusive.
        if ($window->afterDateUtc !== null) {
            $query->where('occurred_at', '>', $window->afterDateUtc->format('Y-m-d H:i:s'));
        }
        if ($window->toDateUtc !== null) {
            $query->where('occurred_at', '<=', $window->toDateUtc->format('Y-m-d H:i:s'));
        }
    }

    /**
     * Deterministic ordering helpers.
     */
    protected function orderPerAggregateAsc(Builder $query): Builder
    {
        return $query->reorder()->orderBy('aggregate_sequence');
    }

    protected function orderGlobalAsc(Builder $query): Builder
    {
        return $query->reorder()->orderBy('sequence');
    }

    /**
     * Convert raw rows to StoredEvent objects.
     *
     * @param iterable $rows
     * @return Generator<StoredEvent>
     */
    protected function mapToStoredEvents(iterable $rows): Generator
    {
        foreach ($rows as $row) {
            $eventClass = $this->aliases->resolveClass($row->event_type);
            $fromVersion = $row->event_version ?? 1;
            $toVersion = $fromVersion;
            $upcasters = [];

            if ($this->upcasters->has($eventClass)) {
                $data = $this->serializer->toArray($row->event_data);
                $result = $this->upcasters->upcast($eventClass, $fromVersion, $data);
                $data = $this->serializer->fromArray($result->payload);
                $event = $this->serializer->deserialize($eventClass, $data);

                $toVersion = $result->toVersion;;
                $upcasters = $result->upcasters;
            } else {
                $event = $this->serializer->deserialize($eventClass, $row->event_data);
            }

            yield new StoredEvent(
                event: $event,
                sequence: (int)$row->sequence,
                aggregateSequence: (int)$row->aggregate_sequence,
                aggregateId: $row->aggregate_id,
                eventType: $row->event_type,
                storedVersion: $fromVersion,
                eventVersion: $toVersion,
                occurredAt: (string)$row->occurred_at,
                correlationId: $row->correlation_id,
                aggregateIdClass: $row->aggregate_id_class,
                upcasters: $upcasters
            );
        }
    }
}