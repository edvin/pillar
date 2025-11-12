<?php

namespace Pillar\Event;

use Carbon\Carbon;
use Generator;
use Illuminate\Support\Facades\DB;
use Pillar\Support\HandlesDatabaseDriverSpecifics;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\Stream\StreamResolver;
use Pillar\Outbox\Outbox;
use Pillar\Outbox\Partitioner;
use Pillar\Serialization\ObjectSerializer;
use RuntimeException;

class DatabaseEventStore implements EventStore
{
    use HandlesDatabaseDriverSpecifics;

    public function __construct(
        private StreamResolver             $streamResolver,
        private ObjectSerializer           $serializer,
        private EventAliasRegistry         $aliases,
        private EventFetchStrategyResolver $strategyResolver,
        private PublicationPolicy          $publicationPolicy,
        private Outbox                     $outbox,
        private Partitioner                $partitioner
    )
    {
    }

    protected function driver(): string
    {
        return DB::connection()->getDriverName();
    }

    public function append(AggregateRootId $id, object $event, ?int $expectedSequence = null): int
    {
        $eventsTable = $this->streamResolver->resolve($id);
        $aggregateId = $id->value();
        $aggregateIdClass = $id::class;

        return DB::transaction(callback: function () use ($eventsTable, $aggregateId, $aggregateIdClass, $event, $expectedSequence) {
            // Ensure a counter row exists for this aggregate (portable across drivers)
            DB::table('aggregate_versions')->insertOrIgnore([
                'aggregate_id' => $aggregateId,
                'aggregate_id_class' => $aggregateIdClass,
                'last_sequence' => 0,
            ]);

            $driver = $this->driver();
            $nextAggregateSequence = match ($driver) {
                'mysql' => $this->advanceSequenceMysql($aggregateId, $expectedSequence),
                'pgsql', 'sqlite' => $this->advanceSequencePgsqlSqlite($aggregateId, $expectedSequence),
                default => $this->advanceSequenceGeneric($aggregateId, $expectedSequence),
            };

            $insertedSequence = DB::table($eventsTable)->insertGetId([
                'aggregate_id' => $aggregateId,
                'aggregate_sequence' => $nextAggregateSequence,
                'event_type' => $this->aliases->resolveAlias($event::class) ?? $event::class,
                'event_version' => ($event instanceof VersionedEvent) ? $event::version() : 1,
                'correlation_id' => EventContext::correlationId(),
                'event_data' => $this->serializer->serialize($event),
                'occurred_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
            ], 'sequence');

            if ($this->publicationPolicy->shouldPublish($event)) {
                $partition = $this->partitioner->keyForBucket($aggregateId);
                $this->outbox->enqueue($insertedSequence, $partition);
            }

            return $nextAggregateSequence;
        });
    }

    public function load(AggregateRootId $id, ?EventWindow $window = null): Generator
    {
        return $this->strategyResolver->resolve($id)->load($id, $window);
    }

    public function all(?AggregateRootId $aggregateId = null, ?EventWindow $window = null, ?string $eventType =
    null): Generator
    {
        return $this->strategyResolver->resolve($aggregateId)->all($aggregateId, $window, $eventType);
    }

    public function getByGlobalSequence(int $sequence): ?StoredEvent
    {
        // NOTE: This assumes a single default events stream/table. If you route
        // events to multiple tables, we must consider adding a cross-stream locator.
        $eventsTable = $this->streamResolver->resolve(null);

        $row = DB::table($eventsTable)
            ->where('sequence', $sequence)
            ->first([
                'sequence',
                'aggregate_id',
                'aggregate_sequence',
                'event_type',
                'event_version',
                'event_data',
                'occurred_at',
                'correlation_id',
            ]);

        if (!$row) {
            return null;
        }

        // Resolve event class from alias or accept fully-qualified class names
        $type = (string)$row->event_type;
        $class = $this->aliases->resolveClass($type) ?? $type;

        // event_data may arrive as string (JSON) or as decoded array/stdClass from the driver
        $raw = $row->event_data;
        $wire = is_string($raw) ? $raw : json_encode($raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $event = $this->serializer->deserialize($class, $wire);

        return new StoredEvent(
            event: $event,
            sequence: (int)$row->sequence,
            aggregateSequence: (int)$row->aggregate_sequence,
            aggregateId: (string)$row->aggregate_id,
            eventType: $type,
            storedVersion: (int)$row->event_version,
            eventVersion: (int)$row->event_version,
            occurredAt: (string)$row->occurred_at,
            correlationId: $row->correlation_id ?? null
        );
    }

    /**
     * MySQL/MariaDB: atomically advance aggregate version via LAST_INSERT_ID trick.
     * @throws ConcurrencyException
     */
    private function advanceSequenceMysql(string $aggregateId, ?int $expectedSequence): int
    {
        if ($expectedSequence === null) {
            $updated = DB::update(
                'UPDATE aggregate_versions
                 SET last_sequence = LAST_INSERT_ID(last_sequence + 1)
                 WHERE aggregate_id = ?',
                [$aggregateId]
            );
        } else {
            $updated = DB::update(
                'UPDATE aggregate_versions
                 SET last_sequence = LAST_INSERT_ID(last_sequence + 1)
                 WHERE aggregate_id = ? AND last_sequence = ?',
                [$aggregateId, $expectedSequence]
            );
        }

        if ($expectedSequence !== null && $updated === 0) {
            throw new ConcurrencyException(
                sprintf('Concurrency conflict for aggregate %s (expected %d).', $aggregateId, $expectedSequence)
            );
        }

        return (int)DB::getPdo()->lastInsertId();
    }

    /**
     * PostgreSQL & SQLite: UPDATE ... RETURNING last_sequence.
     * @throws ConcurrencyException
     */
    private function advanceSequencePgsqlSqlite(string $aggregateId, ?int $expectedSequence): int
    {
        $sql = 'UPDATE aggregate_versions SET last_sequence = last_sequence + 1 WHERE aggregate_id = ?';
        $params = [$aggregateId];
        if ($expectedSequence !== null) {
            $sql .= ' AND last_sequence = ?';
            $params[] = $expectedSequence;
        }
        $sql .= ' RETURNING last_sequence';

        $rows = DB::select($sql, $params);

        if ($expectedSequence !== null && empty($rows)) {
            throw new ConcurrencyException(
                sprintf('Concurrency conflict for aggregate %s (expected %d).', $aggregateId, $expectedSequence)
            );
        }

        // @codeCoverageIgnoreStart
        if (empty($rows)) {
            throw new RuntimeException('Failed to advance aggregate version');
        }
        // @codeCoverageIgnoreEnd

        return (int)($rows[0]->last_sequence ?? 0);
    }

    /**
     * Generic portable fallback: conditional UPDATE then SELECT last_sequence.
     * @throws ConcurrencyException
     */
    private function advanceSequenceGeneric(string $aggregateId, ?int $expectedSequence): int
    {
        $q = DB::table('aggregate_versions')->where('aggregate_id', $aggregateId);
        if ($expectedSequence !== null) {
            $q->where('last_sequence', $expectedSequence);
        }
        $updated = $q->update(['last_sequence' => DB::raw('last_sequence + 1')]);

        if ($expectedSequence !== null && $updated === 0) {
            throw new ConcurrencyException(
                sprintf('Concurrency conflict for aggregate %s (expected %d).', $aggregateId, $expectedSequence)
            );
        }

        return (int)DB::table('aggregate_versions')
            ->where('aggregate_id', $aggregateId)
            ->value('last_sequence');
    }

    public function resolveAggregateIdClass(string $aggregateId): ?string
    {
        return DB::table('aggregate_versions')
            ->where('aggregate_id', $aggregateId)
            ->value('aggregate_id_class') ?: null;
    }
}