<?php

namespace Pillar\Event;

use Carbon\Carbon;
use Generator;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Aggregate\AggregateRegistry;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Outbox\Outbox;
use Pillar\Outbox\Partitioner;
use Pillar\Serialization\ObjectSerializer;

class DatabaseEventStore implements EventStore
{
    public function __construct(
        private AggregateRegistry          $aggregates,
        private ObjectSerializer           $serializer,
        private EventAliasRegistry         $aliases,
        private EventFetchStrategyResolver $strategyResolver,
        private PublicationPolicy          $publicationPolicy,
        private Outbox                     $outbox,
        private Partitioner                $partitioner,
        private DatabaseEventMapper        $mapper,
        #[Config('pillar.event_store.options.tables.events', 'events')]
        private string                     $eventsTable = 'events',
    )
    {
    }

    public function append(AggregateRootId $id, object $event, ?int $expectedSequence = null): int
    {
        $streamId = $this->aggregates->toStreamName($id);

        return DB::transaction(function () use ($streamId, $event, $expectedSequence) {
            // Lock this stream's rows and determine the current last per-stream sequence
            $lastSequence = DB::table($this->eventsTable)
                ->where('stream_id', $streamId)
                ->lockForUpdate()
                ->max('stream_sequence');

            $lastSequence = $lastSequence ?? 0;

            if ($expectedSequence !== null && $lastSequence !== $expectedSequence) {
                throw new ConcurrencyException(
                    sprintf(
                        'Concurrency conflict for stream %s (expected %d, actual %d).',
                        $streamId,
                        $expectedSequence,
                        $lastSequence
                    )
                );
            }

            $nextStreamSequence = $lastSequence + 1;

            $insertedSequence = DB::table($this->eventsTable)->insertGetId([
                'stream_id' => $streamId,
                'stream_sequence' => $nextStreamSequence,
                'event_type' => $this->aliases->resolveAlias($event::class) ?? $event::class,
                'event_version' => ($event instanceof VersionedEvent) ? $event::version() : 1,
                'correlation_id' => EventContext::correlationId(),
                'event_data' => $this->serializer->serialize($event),
                // 'metadata' can be populated later when we decide what to store there
                'occurred_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
            ], 'sequence');

            if ($this->publicationPolicy->shouldPublish($event)) {
                $partition = $this->partitioner->partitionKeyForAggregate($streamId);
                $this->outbox->enqueue($insertedSequence, $partition);
            }

            return $nextStreamSequence;
        });
    }

    public function streamFor(AggregateRootId $id, ?EventWindow $window = null): Generator
    {
        return $this->strategyResolver->resolve($id)->streamFor($id, $window);
    }

    public function stream(?EventWindow $window = null, ?string $eventType = null): Generator
    {
        return $this->strategyResolver->resolve()->stream(null, $window, $eventType);
    }

    public function getByGlobalSequence(int $sequence): ?StoredEvent
    {
        $row = DB::table($this->eventsTable)
            ->where('sequence', $sequence)
            ->first([
                'sequence',
                'stream_id',
                'stream_sequence',
                'event_type',
                'event_version',
                'event_data',
                'occurred_at',
                'correlation_id',
            ]);

        if (!$row) {
            return null;
        }

        return $this->mapper->map($row);
    }
    public function recent(int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }

        $latestPerStream = DB::table($this->eventsTable)
            ->select('stream_id', DB::raw('MAX(sequence) as max_sequence'))
            ->groupBy('stream_id');

        $rows = DB::table($this->eventsTable . ' as e')
            ->joinSub($latestPerStream, 'latest', function ($join) {
                $join->on('e.stream_id', '=', 'latest.stream_id')
                    ->on('e.sequence', '=', 'latest.max_sequence');
            })
            ->orderByDesc('e.sequence')
            ->limit($limit)
            ->get([
                'e.sequence',
                'e.stream_id',
                'e.stream_sequence',
                'e.event_type',
                'e.event_version',
                'e.event_data',
                'e.occurred_at',
                'e.correlation_id',
            ]);

        if ($rows->isEmpty()) {
            return [];
        }

        $out = [];

        foreach ($rows as $row) {
            $out[] = $this->mapper->map($row);
        }

        return $out;
    }
}