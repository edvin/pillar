<?php

namespace Pillar\Snapshot;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Pillar\Aggregate\AggregateRootId;
use Pillar\Logging\PillarLogger;
use Pillar\Metrics\Counter;
use Pillar\Metrics\Metrics;

readonly class CacheSnapshotStore implements SnapshotStore
{
    private Counter $snapshotSaveCounter;

    public function __construct(
        private PillarLogger $logger,
        #[Config('pillar.snapshot.ttl')]
        private ?int         $ttl,
        Metrics              $metrics,
    )
    {
        $this->snapshotSaveCounter = $metrics->counter(
            'eventstore_snapshot_save_total',
            ['aggregate_type']
        );
    }

    public function load(AggregateRootId $id): ?Snapshot
    {
        if (!$this->isSnapshottable($id->aggregateClass())) {
            return null;
        }

        $payload = Cache::get($this->cacheKey($id->aggregateClass(), $id));

        if (!$payload) {
            $this->logger->debug('pillar.eventstore.snapshot_cache_miss', [
                'aggregate_type' => $id->aggregateClass(),
                'aggregate_id' => (string)$id,
            ]);
            return null;
        }

        $aggregate = $id->aggregateClass()::fromSnapshot($payload['data']);
        $version = $payload['snapshot_version'] ?? 0;

        $this->logger->debug('pillar.eventstore.snapshot_cache_hit', [
            'aggregate_type' => $id->aggregateClass(),
            'aggregate_id' => (string)$id,
            'snapshot_version' => $version,
        ]);

        return new Snapshot($aggregate, $version);
    }

    public function save(AggregateRootId $id, int $sequence, array $payload): void
    {
        $aggregateClass = $id->aggregateClass();

        if (!$this->isSnapshottable($aggregateClass)) {
            return;
        }

        $now = Carbon::now('UTC');

        $cachedPayload = [
            'data' => $payload,
            'snapshot_version' => $sequence,
            'snapshot_created_at' => $now->format('Y-m-d H:i:s'),
        ];

        Cache::put(
            $this->cacheKey($aggregateClass, $id),
            $cachedPayload,
            $this->ttl === null
                ? null
                : $now->copy()->addSeconds($this->ttl)
        );

        $this->logger->debug('pillar.eventstore.snapshot_saved', [
            'aggregate_type' => $id->aggregateClass(),
            'aggregate_id' => (string)$id,
            'seq' => $sequence,
        ]);

        $this->snapshotSaveCounter->inc(1, [
            'aggregate_type' => $id->aggregateClass(),
        ]);
    }

    public function delete(AggregateRootId $id): void
    {
        Cache::forget($this->cacheKey($id->aggregateClass(), $id));
    }

    private function isSnapshottable(string $aggregateClass): bool
    {
        return is_subclass_of($aggregateClass, Snapshottable::class);
    }

    private function cacheKey(string $aggregateClass, AggregateRootId $id): string
    {
        return sprintf('snapshot:%s:%s', str_replace('\\', '.', $aggregateClass), $id->value());
    }

}
