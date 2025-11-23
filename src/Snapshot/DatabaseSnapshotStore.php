<?php

namespace Pillar\Snapshot;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Logging\PillarLogger;

readonly class DatabaseSnapshotStore implements SnapshotStore
{
    public function __construct(
        private PillarLogger $logger,
        #[Config('pillar.snapshot.store.options.table', 'snapshots')]
        private string       $table,
    )
    {
    }

    public function load(AggregateRootId $id): ?Snapshot
    {
        if (!$this->isSnapshottable($id->aggregateClass())) {
            return null;
        }

        $row = DB::table($this->table)
            ->where('aggregate_type', $id->aggregateClass())
            ->where('aggregate_id', (string)$id)
            ->first();

        if (!$row) {
            $this->logger->debug('pillar.eventstore.snapshot_db_miss', [
                'aggregate_type' => $id->aggregateClass(),
                'aggregate_id' => (string)$id,
            ]);

            return null;
        }

        $payload = [
            'data' => is_array($row->data) ? $row->data : (array)json_decode($row->data, true),
            'snapshot_version' => (int)($row->snapshot_version ?? 0),
        ];

        $aggregate = $id->aggregateClass()::fromSnapshot($payload['data']);

        $this->logger->debug('pillar.eventstore.snapshot_db_hit', [
            'aggregate_type' => $id->aggregateClass(),
            'aggregate_id' => (string)$id,
            'snapshot_version' => $payload['snapshot_version'],
        ]);

        return new Snapshot($aggregate, $payload['snapshot_version']);
    }

    public function save(AggregateRoot $aggregate, int $sequence): void
    {
        if (!$this->isSnapshottable($aggregate::class)) {
            return;
        }

        $now = Carbon::now('UTC');

        $payload = [
            'data' => $aggregate->toSnapshot(),
            'snapshot_version' => $sequence,
            'snapshot_created_at' => $now->format('Y-m-d H:i:s'),
        ];

        DB::table($this->table)->updateOrInsert(
            [
                'aggregate_type' => $aggregate::class,
                'aggregate_id' => (string)$aggregate->id(),
            ],
            [
                'data' => json_encode($payload['data']),
                'snapshot_version' => $payload['snapshot_version'],
                'snapshot_created_at' => $payload['snapshot_created_at'],
            ]
        );

        $this->logger->debug('pillar.eventstore.snapshot_db_saved', [
            'aggregate_type' => $aggregate::class,
            'aggregate_id' => (string)$aggregate->id(),
            'seq' => $sequence,
        ]);
    }

    public function delete(AggregateRootId $id): void
    {
        DB::table($this->table)
            ->where('aggregate_type', $id->aggregateClass())
            ->where('aggregate_id', (string)$id)
            ->delete();

        $this->logger->debug('pillar.eventstore.snapshot_db_deleted', [
            'aggregate_type' => $id->aggregateClass(),
            'aggregate_id' => (string)$id,
        ]);
    }

    private function isSnapshottable(string $aggregateClass): bool
    {
        return is_subclass_of($aggregateClass, Snapshottable::class);
    }
}