<?php

namespace Pillar\Event\Fetch\Database;

use Generator;
use Illuminate\Container\Attributes\Config;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\Fetch\EventFetchStrategy;
use Pillar\Event\EventAliasRegistry;
use Pillar\Event\UpcasterRegistry;
use Pillar\Serialization\ObjectSerializer;

class DatabaseChunkedFetchStrategy extends AbstractDatabaseFetchStrategy implements EventFetchStrategy
{
    private int $chunkSize;

    public function __construct(
        ObjectSerializer   $serializer,
        EventAliasRegistry $aliases,
        UpcasterRegistry   $upcasters,
        #[Config('pillar.event_store.options.table')]
        string             $table,
        #[Config('pillar.fetch_strategies.db.chunked.options.chunk_size', 500)]
        int                $chunkSize,
    )
    {
        parent::__construct($serializer, $aliases, $upcasters, $table);
        $this->chunkSize = $chunkSize;
    }

    public function load(AggregateRootId $id, int $afterSequence = 0): Generator
    {
        $query = $this->baseQuery()->where('aggregate_id', $id->value());

        if ($afterSequence > 0) {
            $query->where('sequence', '>', $afterSequence);
        }

        $page = 1;
        do {
            $chunk = $query->orderBy('sequence')
                ->forPage($page++, $this->chunkSize)
                ->get();

            if ($chunk->isEmpty()) {
                break;
            }

            yield from $this->mapToStoredEvents($chunk);
        } while ($chunk->count() === $this->chunkSize);
    }

    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): Generator
    {
        $query = $this->baseQuery();

        if ($aggregateId) {
            $query->where('aggregate_id', $aggregateId->value());
        }

        if ($eventType) {
            $query->where('event_type', $eventType);
        }

        $page = 1;
        do {
            $chunk = $query->orderBy('sequence')
                ->forPage($page++, $this->chunkSize)
                ->get();

            if ($chunk->isEmpty()) {
                break;
            }

            yield from $this->mapToStoredEvents($chunk);
        } while ($chunk->count() === $this->chunkSize);
    }
}