<?php

namespace Pillar\Event;

use Carbon\Carbon;
use Generator;
use Illuminate\Support\Facades\DB;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Event\Fetch\EventFetchStrategyResolver;
use Pillar\Event\Stream\StreamResolver;
use Pillar\Serialization\ObjectSerializer;
use RuntimeException;

class DatabaseEventStore implements EventStore
{

    public function __construct(
        private StreamResolver             $streamResolver,
        private ObjectSerializer           $serializer,
        private EventAliasRegistry         $aliases,
        private EventFetchStrategyResolver $strategyResolver,
    )
    {
    }

    public function append(AggregateRootId $id, object $event, ?int $expectedSequence = null): int
    {
        $eventsTable = $this->streamResolver->resolve($id);
        $aggregateId = $id->value();

        return DB::transaction(function () use ($eventsTable, $aggregateId, $event, $expectedSequence) {
            // Ensure a counter row exists for this aggregate (portable across drivers)
            DB::table('aggregate_versions')->insertOrIgnore([
                'aggregate_id'  => $aggregateId,
                'last_sequence' => 0,
            ]);

            $driver = DB::connection()->getDriverName();

            // Atomically advance the per-aggregate version and get the value assigned to this transaction
            if ($driver === 'mysql') {
                // MySQL/MariaDB: use LAST_INSERT_ID to read the increment atomically
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

                $nextAggregateSequence = (int) DB::getPdo()->lastInsertId();

            } elseif ($driver === 'pgsql' || $driver === 'sqlite') {
                // PostgreSQL & SQLite (>= 3.35): UPDATE ... RETURNING last_sequence
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

                // For the no-expected case, we still expect exactly one row
                if (empty($rows)) {
                    throw new RuntimeException('Failed to advance aggregate version');
                }

                // DB::select returns an array of stdClass
                $nextAggregateSequence = (int) ($rows[0]->last_sequence ?? 0);

            } else {
                // Fallback: portable two-step (possible duplicate conflict if optimistic locking is disabled under extreme concurrency)
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

                $nextAggregateSequence = (int) DB::table('aggregate_versions')
                    ->where('aggregate_id', $aggregateId)
                    ->value('last_sequence');
            }

            // Insert the event with the computed per-aggregate version
            DB::table($eventsTable)->insert([
                'aggregate_id'       => $aggregateId,
                'aggregate_sequence' => $nextAggregateSequence,
                'event_type'         => $this->aliases->resolveAlias($event),
                'event_version'      => ($event instanceof VersionedEvent) ? $event::version() : 1,
                'correlation_id'     => EventContext::correlationId(),
                'event_data'         => $this->serializer->serialize($event),
                'occurred_at'        => Carbon::now('UTC')->format('Y-m-d H:i:s'),
            ]);

            return $nextAggregateSequence;
        });
    }

    public function load(AggregateRootId $id, int $afterAggregateSequence = 0): Generator
    {
        return $this->strategyResolver->resolve($id)->load($id, $afterAggregateSequence);
    }

    public function all(?AggregateRootId $aggregateId = null, ?string $eventType = null): Generator
    {
        return $this->strategyResolver->resolve()->all($aggregateId, $eventType);
    }

}