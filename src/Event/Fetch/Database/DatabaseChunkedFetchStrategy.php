<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Illuminate\Container\Attributes\Config;
use Pillar\Aggregate\AggregateRegistry;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\DatabaseEventMapper;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\EventWindow;
use Pillar\Event\Fetch\EventFetchStrategy;
use Pillar\Event\UpcasterRegistry;
use Pillar\Serialization\ObjectSerializer;

class DatabaseChunkedFetchStrategy extends AbstractDatabaseFetchStrategy implements EventFetchStrategy
{
    private int $chunkSize;

    public function __construct(
        ObjectSerializer    $serializer,
        EventAliasRegistry  $aliases,
        UpcasterRegistry    $upcasters,
        DatabaseEventMapper $mapper,
        AggregateRegistry   $aggregates,
        #[Config('pillar.event_store.options.tables.events', 'events')]
        protected string    $eventsTable,
        #[Config('pillar.fetch_strategies.available.db_chunked.options.chunk_size', 1000)]
        int                 $chunkSize,
    )
    {
        parent::__construct($serializer, $aliases, $upcasters, $mapper, $aggregates, $eventsTable);
        $this->chunkSize = $chunkSize;
    }

    public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator
    {
        $after = $window?->afterStreamSequence ?? 0;
        $toAgg = $window?->toStreamSequence;
        $toGlob = $window?->toGlobalSequence;
        $toDate = $window?->toDateUtc;

        while (true) {
            // Build a per-page window starting after the moving cursor
            $pageWindow = new EventWindow(
                afterStreamSequence: $after,
                toStreamSequence: $toAgg,
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
                $after = $stored->streamSequence; // advance cursor
            }

            if ($rows->count() < $this->chunkSize) {
                break; // final page
            }

            if ($toAgg !== null && $after >= $toAgg) {
                break; // reached upper bound
            }
        }
    }

    public function stream(?EventWindow $window = null, ?string $eventType = null): Generator
    {
        // Page forward using keyset pagination over the global sequence.
        $cursor = 0;

        while (true) {
            $qb = $this->globalBase();

            if ($window) {
                $this->applyGlobalWindow($qb, $window);
            }

            if ($eventType) {
                $qb->where('event_type', $eventType);
            }

            if ($cursor > 0) {
                $qb->where('sequence', '>', $cursor);
            }

            $qb   = $this->orderGlobalAsc($qb);
            $rows = $qb->limit($this->chunkSize)->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($this->mapToStoredEvents($rows) as $stored) {
                yield $stored;
                $cursor = $stored->sequence; // advance cursor along the global sequence
            }

            if ($rows->count() < $this->chunkSize) {
                break; // final page
            }
        }
    }
}