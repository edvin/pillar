<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

class CacheSnapshotStore implements SnapshotStore
{
    public function load(AggregateRootId $id): ?array
    {
        if (!$this->isSnapshottable($id->aggregateClass())) {
            return null;
        }

        $payload = Cache::get($this->cacheKey($id->aggregateClass(), $id));

        if (!$payload) {
            return null;
        }

        $aggregate = $id->aggregateClass()::fromSnapshot($payload['data']);

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
