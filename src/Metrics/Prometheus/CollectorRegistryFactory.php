<?php

namespace Pillar\Metrics\Prometheus;

use Illuminate\Container\Attributes\Config;
use Prometheus\CollectorRegistry;
use Prometheus\Storage\Adapter;
use Prometheus\Storage\InMemory;
use Prometheus\Storage\Redis as RedisAdapter;

/**
 * Creates and caches a single Prometheus CollectorRegistry instance.
 *
 * This is where we wire namespace, storage adapter etc. based on config.
 */
final class CollectorRegistryFactory
{
    private ?CollectorRegistry $registry = null;

    public function __construct(
        #[Config('pillar.metrics.prometheus.namespace')]
        private ?string $namespace = 'pillar',

        #[Config('pillar.metrics.prometheus.storage.driver')]
        private ?string $driver = 'in_memory',

        #[Config('pillar.metrics.prometheus.storage.redis.host')]
        private ?string $redisHost = '127.0.0.1',

        #[Config('pillar.metrics.prometheus.storage.redis.port')]
        private ?int    $redisPort = 6379,

        #[Config('pillar.metrics.prometheus.storage.redis.timeout')]
        private ?float  $redisTimeout = 0.1,

        #[Config('pillar.metrics.prometheus.storage.redis.auth')]
        private ?string $redisAuth = null,

        #[Config('pillar.metrics.prometheus.storage.redis.database')]
        private ?int    $redisDatabase = 0,
    )
    {
    }

    public function get(): CollectorRegistry
    {
        if ($this->registry !== null) {
            return $this->registry;
        }

        $storage = $this->createStorageAdapter();

        $this->registry = new CollectorRegistry(
            $storage,
            $this->namespace ?? 'pillar',
        );

        return $this->registry;
    }

    private function createStorageAdapter(): Adapter
    {
        $driver = $this->driver ?? 'in_memory';

        return match ($driver) {
            'redis' => $this->createRedisAdapter(),
            default => new InMemory(), // safe default
        };
    }

    private function createRedisAdapter(): Adapter
    {
        if (!class_exists(\Redis::class)) {
            throw new \RuntimeException(
                "Prometheus storage driver 'redis' selected, but ext-redis is not installed."
            );
        }

        $redis = new \Redis();
        $redis->connect(
            $this->redisHost ?? '127.0.0.1',
            $this->redisPort ?? 6379,
            $this->redisTimeout ?? 0.1,
        );

        if ($this->redisAuth !== null) {
            $redis->auth($this->redisAuth);
        }

        if ($this->redisDatabase !== null) {
            $redis->select($this->redisDatabase);
        }

        return RedisAdapter::fromExistingConnection($redis);
    }
}