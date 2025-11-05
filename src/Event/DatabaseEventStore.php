<?php

namespace Pillar\Event;

use Pillar\Aggregate\AggregateRootId;
use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Serialization\ObjectSerializer;
use Carbon\Carbon;
use Generator;

class DatabaseEventStore implements EventStore
{

    public function __construct(
        private ObjectSerializer           $serializer,
        private EventAliasRegistry         $aliases,
        #[Config('pillar.event_store.options.table')]
        private string                     $table,
        private EventFetchStrategyResolver $strategyResolver)
    {
    }

    public function append(AggregateRootId $id, object $event): int
    {
        return DB::table($this->table)->insertGetId([
            'aggregate_id' => $id->value(),
            'event_type' => $this->aliases->resolveAlias($event),
            'event_version' => ($event instanceof VersionedEvent) ? $event::version() : 1,
            'correlation_id' => EventContext::correlationId(),
            'event_data' => $this->serializer->serialize($event),
            'occurred_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ]);
    }

    public function load(AggregateRootId $id, ?int $afterSequence = null): Generator
    {
        return $this->strategyResolver->resolve($id)->load($id, $afterSequence);
    }

    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): Generator
    {
        return $this->strategyResolver->resolve()->all($aggregateId, $eventType);
    }

}