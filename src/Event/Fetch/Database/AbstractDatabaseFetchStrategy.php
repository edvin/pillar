<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Illuminate\Container\Attributes\Config;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\AggregateRegistry;
use Pillar\Event\DatabaseEventMapper;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\EventWindow;
use Pillar\Event\StoredEvent;
use Pillar\Event\UpcasterRegistry;
use Pillar\Serialization\ObjectSerializer;

abstract class AbstractDatabaseFetchStrategy
{
    public function __construct(
        protected ObjectSerializer    $serializer,
        protected EventAliasRegistry  $aliases,
        protected UpcasterRegistry    $upcasters,
        protected DatabaseEventMapper $mapper,
        protected AggregateRegistry   $aggregates,
        #[Config('pillar.event_store.options.tables.events', 'events')]
        protected string              $eventsTable,
    )
    {
    }

    /**
     * Base query for a specific stream (aggregate) (no ordering yet).
     */
    protected function perAggregateBase(AggregateRootId $id): Builder
    {
        $streamId = $this->aggregates->toStreamName($id);

        return DB::table($this->eventsTable)
            ->where('stream_id', $streamId)
            ->select('*');
    }

    /**
     * Base query for global reads (single events table).
     */
    protected function globalBase(): Builder
    {
        return DB::table($this->eventsTable . ' as e')
            ->select('e.*');
    }

    /**
     * Apply an EventWindow's bounds to a query (per-stream / aggregate).
     */
    protected function applyPerAggregateWindow(Builder $query, ?EventWindow $window): void
    {
        if ($window === null) {
            return;
        }

        // Per-aggregate bounds (only meaningful when scanning a single aggregate)
        if ($window->afterStreamSequence !== null && $window->afterStreamSequence > 0) {
            $query->where('stream_sequence', '>', (int)$window->afterStreamSequence);
        }
        if ($window->toStreamSequence !== null) {
            $query->where('stream_sequence', '<=', (int)$window->toStreamSequence);
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
        return $query->reorder()->orderBy('stream_sequence');
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
            yield $this->mapper->map($row);
        }
    }
}