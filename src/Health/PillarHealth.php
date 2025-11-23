<?php
// @codeCoverageIgnoreStart

namespace Pillar\Health;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pillar\Logging\PillarLogger;
use Throwable;

final class PillarHealth
{
    public function __construct(
        private PillarLogger $logger,

        // Event store table name (matches pillar.php)
        #[Config('pillar.event_store.options.tables.events')]
        private ?string $eventsTable = 'events',

        // Outbox tables (matches pillar.php)
        #[Config('pillar.outbox.tables.outbox')]
        private ?string $outboxTable = 'outbox',

        #[Config('pillar.outbox.tables.partitions')]
        private ?string $outboxPartitionsTable = 'outbox_partitions',

        #[Config('pillar.outbox.tables.workers')]
        private ?string $outboxWorkersTable = 'outbox_workers',

        // Metrics config (for a lightweight backend check)
        #[Config('pillar.metrics.driver')]
        private ?string $metricsDriver = 'none',

        #[Config('pillar.metrics.prometheus.storage.driver')]
        private ?string $metricsStorageDriver = 'in_memory',

        #[Config('pillar.metrics.prometheus.storage.redis.host')]
        private ?string $metricsRedisHost = null,

        #[Config('pillar.metrics.prometheus.storage.redis.port')]
        private ?int $metricsRedisPort = null,
    ) {
    }

    /**
     * Run all Pillar health checks and return a structured result.
     *
     * Shape:
     *  [
     *      'status' => 'ok'|'degraded'|'down',
     *      'checks' => [
     *          'database'       => ['status' => 'ok', 'details' => null],
     *          'events_table'   => ['status' => 'ok', 'details' => null],
     *          'outbox_tables'  => ['status' => 'ok', 'details' => null],
     *          'metrics_backend'=> ['status' => 'skipped', 'details' => '...'],
     *      ],
     *  ]
     */
    public function check(): array
    {
        $checks = [
            'database'        => $this->checkDatabase(),
            'events_table'    => $this->checkEventsTable(),
            'outbox_tables'   => $this->checkOutboxTables(),
            'metrics_backend' => $this->checkMetricsBackend(),
        ];

        $status = 'ok';

        foreach ($checks as $result) {
            if ($result['status'] === 'down') {
                $status = 'down';
                break;
            }

            if ($result['status'] === 'degraded' && $status === 'ok') {
                $status = 'degraded';
            }
        }

        return [
            'status' => $status,
            'checks' => $checks,
        ];
    }

    private function checkDatabase(): array
    {
        try {
            DB::select('select 1');

            return [
                'status'  => 'ok',
                'details' => null,
            ];
        } catch (Throwable $e) {
            $this->logger->error('Pillar health check: database ping failed', [
                'exception' => $e,
            ]);

            return [
                'status'  => 'down',
                'details' => $e->getMessage(),
            ];
        }
    }

    private function checkEventsTable(): array
    {
        $table = $this->eventsTable ?: 'events';

        try {
            if (!Schema::hasTable($table)) {
                $message = sprintf('Events table "%s" does not exist.', $table);

                $this->logger->warning('Pillar health check: events table missing', [
                    'table' => $table,
                ]);

                return [
                    'status'  => 'down',
                    'details' => $message,
                ];
            }

            return [
                'status'  => 'ok',
                'details' => null,
            ];
        } catch (Throwable $e) {
            $this->logger->error('Pillar health check: failed to inspect events table', [
                'table'     => $table,
                'exception' => $e,
            ]);

            return [
                'status'  => 'degraded',
                'details' => $e->getMessage(),
            ];
        }
    }

    private function checkOutboxTables(): array
    {
        $tables = [
            'outbox'     => $this->outboxTable ?: 'outbox',
            'partitions' => $this->outboxPartitionsTable ?: 'outbox_partitions',
            'workers'    => $this->outboxWorkersTable ?: 'outbox_workers',
        ];

        try {

            $missing = array_filter($tables, function ($table) {
                return !Schema::hasTable($table);
            });

            if ($missing !== []) {
                $this->logger->warning('Pillar health check: one or more outbox tables are missing', [
                    'missing' => $missing,
                ]);

                return [
                    'status'  => 'down',
                    'details' => 'Missing outbox tables: ' . implode(', ', array_values($missing)),
                ];
            }

            return [
                'status'  => 'ok',
                'details' => null,
            ];
        } catch (Throwable $e) {
            $this->logger->error('Pillar health check: failed to inspect outbox tables', [
                'tables'    => $tables,
                'exception' => $e,
            ]);

            return [
                'status'  => 'degraded',
                'details' => $e->getMessage(),
            ];
        }
    }

    private function checkMetricsBackend(): array
    {
        $driver = $this->metricsDriver ?? 'none';

        if ($driver === 'none') {
            return [
                'status'  => 'skipped',
                'details' => 'Metrics driver is disabled (pillar.metrics.driver=none).',
            ];
        }

        if ($driver !== 'prometheus') {
            return [
                'status'  => 'degraded',
                'details' => sprintf('Unknown metrics driver "%s".', $driver),
            ];
        }

        $storage = $this->metricsStorageDriver ?? 'in_memory';

        if ($storage === 'in_memory') {
            return [
                'status'  => 'ok',
                'details' => 'Prometheus metrics enabled using in-memory storage.',
            ];
        }

        if ($storage !== 'redis') {
            return [
                'status'  => 'degraded',
                'details' => sprintf('Unknown Prometheus storage driver "%s".', $storage),
            ];
        }

        // For redis storage, do a very light connectivity probe.
        if (!class_exists(\Redis::class)) {
            $this->logger->warning('Pillar health check: metrics storage redis selected but ext-redis is missing.');

            return [
                'status'  => 'down',
                'details' => 'Prometheus storage set to redis but ext-redis is not installed.',
            ];
        }

        $host = $this->metricsRedisHost ?: '127.0.0.1';
        $port = $this->metricsRedisPort ?: 6379;

        try {
            $redis = new \Redis();
            $redis->connect($host, $port, 0.1);
            $redis->close();

            return [
                'status'  => 'ok',
                'details' => sprintf('Prometheus metrics using Redis at %s:%d.', $host, $port),
            ];
        } catch (Throwable $e) {
            $this->logger->error('Pillar health check: Redis metrics backend is unreachable', [
                'host'      => $host,
                'port'      => $port,
                'exception' => $e,
            ]);

            return [
                'status'  => 'down',
                'details' => $e->getMessage(),
            ];
        }
    }
}
// @codeCoverageIgnoreEnd