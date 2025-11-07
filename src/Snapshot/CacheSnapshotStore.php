<?php

namespace Pillar\Snapshot;

use Pillar\Aggregate\AggregateRoot;
use Pillar\Aggregate\AggregateRootId;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

class CacheSnapshotStore implements SnapshotStore
{
    public function load(AggregateRootId $id): ?array
    {
        /** @var class-string $aggregateClass */
        $aggregateClass = $id->aggregateClass();
        $this->assertAggregateType($aggregateClass);;

        $payload = Cache::get($this->cacheKey($aggregateClass, $id));

        if (!$payload) {
            return null;
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

    public function delete(AggregateRootId $id): void
    {
        Cache::forget($this->cacheKey($id->aggregateClass(), $id));
    }

    /**
     * Ensure the provided class is a valid Aggregate type for snapshots.
     *
     * We require it to extend AggregateRoot (which declares the static fromSnapshot contract)
     * and to actually implement a static fromSnapshot(array): self.
     *
     * @param class-string $aggregateClass
     * @throws InvalidArgumentException
     */
    private function assertAggregateType(string $aggregateClass): void
    {
        if (!is_subclass_of($aggregateClass, AggregateRoot::class)) {
            throw new InvalidArgumentException("Expected aggregate class extending " . AggregateRoot::class . ", got {$aggregateClass}.");
        }
    }

    private function cacheKey(string $aggregateClass, AggregateRootId $id): string
    {
        return sprintf('snapshot:%s:%s', str_replace('\\', '.', $aggregateClass), $id->value());
    }

}
