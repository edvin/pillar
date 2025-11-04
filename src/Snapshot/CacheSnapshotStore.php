<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use RuntimeException;

class CacheSnapshotStore implements SnapshotStore
{
    public function load(string $aggregateClass, AggregateRootId $id): ?array
    {
        $payload = Cache::get($this->cacheKey($aggregateClass, $id));

        if (!$payload) {
            return null;
        }

        if (!method_exists($aggregateClass, 'fromSnapshot')) {
            throw new RuntimeException("Aggregate $aggregateClass does not have a fromSnapshot method.");
        }

        $aggregate = $aggregateClass::fromSnapshot($payload['data']);

        return [
            'aggregate' => $aggregate,
            'snapshot_version' => $payload['snapshot_version'] ?? 0,
        ];
    }

    public function save(AggregateRoot $aggregate, int $sequence): void
    {
        $payload = [
            'data' => $aggregate->jsonSerialize(),
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

    public function delete(string $aggregateClass, AggregateRootId $id): void
    {
        Cache::forget($this->cacheKey($aggregateClass, $id));
    }

    private function cacheKey(string $aggregateClass, AggregateRootId $id): string
    {
        return sprintf('snapshot:%s:%s', str_replace('\\', '.', $aggregateClass), $id->value());
    }

}
