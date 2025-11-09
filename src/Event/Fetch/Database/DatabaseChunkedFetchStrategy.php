<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Illuminate\Container\Attributes\Config;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\EventWindow;
use Pillar\Event\Fetch\EventFetchStrategy;
use Pillar\Event\Stream\StreamResolver;
use Pillar\Event\UpcasterRegistry;
use Pillar\Serialization\ObjectSerializer;

class DatabaseChunkedFetchStrategy extends AbstractDatabaseFetchStrategy implements EventFetchStrategy
{
    private int $chunkSize;

    public function __construct(
        ObjectSerializer         $serializer,
        EventAliasRegistry       $aliases,
        UpcasterRegistry         $upcasters,
        protected StreamResolver $streamResolver,
        #[Config('pillar.fetch_strategies.available.db_chunked.options.chunk_size', 1000)]
        int                      $chunkSize,
    )
    {
        parent::__construct($serializer, $aliases, $upcasters, $streamResolver);
        $this->chunkSize = $chunkSize;
    }

    public function load(AggregateRootId $id, ?EventWindow $window = null): Generator
    {
        $after = $window?->afterAggregateSequence ?? 0;
        $toAgg = $window?->toAggregateSequence;
        $toGlob = $window?->toGlobalSequence;
        $toDate = $window?->toDateUtc;

        while (true) {
            // Build a per-page window starting after the moving cursor
            $pageWindow = new EventWindow(
                afterAggregateSequence: $after,
                toAggregateSequence: $toAgg,
                toGlobalSequence: $toGlob,
                toDateUtc: $toDate,
            );

            $qb = $this->perAggregateBase($id);
            $this->applyPerAggregateWindow($qb, $pageWindow);
            $qb = $this->orderPerAggregateAsc($qb)->limit($this->chunkSize);

            $rows = $qb->get();
            if ($rows->isEmpty()) {
                break;
            }

            foreach ($this->mapToStoredEvents($rows) as $stored) {
                yield $stored;
                $after = $stored->aggregateSequence; // advance cursor
            }

            if ($rows->count() < $this->chunkSize) {
                break; // final page
            }

            if ($toAgg !== null && $after >= $toAgg) {
                break; // reached upper bound
            }
        }
    }

    public function all(?AggregateRootId $aggregateId = null, ?EventWindow $window = null, ?string $eventType = null):
Generator
    {
        // Page forward using keyset pagination; use per-aggregate or global column depending on filter.
        $cursor = 0;
        $perAgg = $aggregateId !== null;

        while (true) {
            $qb = $perAgg ? $this->perAggregateBase($aggregateId) : $this->globalBase();

            if ($window) {
                if ($perAgg) {
                    $this->applyPerAggregateWindow($qb, $window);
                } else {
                    $this->applyGlobalWindow($qb, $window);
                }
            }

            if ($eventType) {
                $qb->where('event_type', $eventType);
            }

            if ($cursor > 0) {
                $qb->where($perAgg ? 'aggregate_sequence' : 'sequence', '>', $cursor);
            }

            $qb = $perAgg ? $this->orderPerAggregateAsc($qb) : $this->orderGlobalAsc($qb);
            $rows = $qb->limit($this->chunkSize)->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($this->mapToStoredEvents($rows) as $stored) {
                yield $stored;
                $cursor = $perAgg ? $stored->aggregateSequence : $stored->sequence; // advance cursor
            }

            if ($rows->count() < $this->chunkSize) {
                break; // final page
            }
        }
    }
}