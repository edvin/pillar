<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Illuminate\Container\Attributes\Config;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\Fetch\EventFetchStrategy;
use Pillar\Event\EventAliasRegistry;
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

    public function load(AggregateRootId $id, int $afterAggregateSequence = 0): Generator
    {
        // Page forward using the per-aggregate version as the cursor, ascending
        $cursor = max(0, (int) $afterAggregateSequence);

        while (true) {
            $query = $this->baseQuery($id)
                ->where('aggregate_id', $id->value());

            if ($cursor > 0) {
                $query->where('aggregate_sequence', '>', $cursor);
            }

            $rows = $query
                ->reorder()
                ->orderBy('aggregate_sequence', 'asc')
                ->limit($this->chunkSize)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($this->mapToStoredEvents($rows) as $e) {
                yield $e;
            }

            // Advance cursor to last row's per-aggregate sequence
            $last = $rows->last();
            $cursor = (int) ($last->aggregate_sequence ?? $cursor);

            if ($rows->count() < $this->chunkSize) {
                break; // final page
            }
        }
    }

    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): Generator
    {
        // Use keyset pagination to guarantee stable, forward-only ordering.
        // For a specific aggregate, page by per-aggregate sequence; otherwise by global sequence.
        $cursor = 0;
        $orderColumn = $aggregateId ? 'aggregate_sequence' : 'sequence';

        while (true) {
            $query = $this->baseQuery($aggregateId);

            if ($aggregateId) {
                $query->where('aggregate_id', $aggregateId->value());
            }

            if ($eventType) {
                $query->where('event_type', $eventType);
            }

            if ($cursor > 0) {
                $query->where($orderColumn, '>', $cursor);
            }

            $rows = $query
                ->reorder($orderColumn)
                ->limit($this->chunkSize)
                ->get();

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($this->mapToStoredEvents($rows) as $e) {
                yield $e;
            }

            // Advance the cursor using the last row of this page.
            $last = $rows->last();
            $cursor = (int)($last->{$orderColumn} ?? 0);

            if ($rows->count() < $this->chunkSize) {
                break; // final, short page
            }
        }
    }
}