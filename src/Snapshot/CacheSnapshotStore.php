<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Pillar\Logging\PillarLogger;

readonly class CacheSnapshotStore implements SnapshotStore
{
    public function __construct(private PillarLogger $logger) {}

    public function load(AggregateRootId $id): ?array
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

        $this->logger->debug('pillar.eventstore.snapshot_cache_hit', [
            'aggregate_type' => $id->aggregateClass(),
            'aggregate_id' => (string)$id,
            'snapshot_version' => $payload['snapshot_version'] ?? 0,
        ]);

        return [
            'aggregate' => $aggregate,
            'snapshot_version' => $payload['snapshot_version'] ?? 0,
        ];
    }

    public function save(AggregateRoot $aggregate, int $sequence): void
    {
        if (!$this->isSnapshottable($aggregate::class)) {
            return;
        }

        $payload = [
            'data' => $aggregate->toSnapshot(),
            'snapshot_version' => $sequence,
            'snapshot_created_at' => Carbon::now('UTC')->format('Y-m-d H:i:s'),
        ];

        $ttl = Config::get('pillar.snapshot.ttl');

        Cache::put(
            $this->cacheKey($aggregate::class, $aggregate->id()),
            $payload,
            $ttl === null ? null : Carbon::now('UTC')->modify("+$ttl seconds")
        );

        $this->logger->debug('pillar.eventstore.snapshot_saved', [
            'aggregate_type' => $aggregate::class,
            'aggregate_id' => (string)$aggregate->id(),
            'seq' => $sequence,
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
