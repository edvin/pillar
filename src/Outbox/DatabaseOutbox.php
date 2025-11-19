<?php

namespace Pillar\Outbox;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Pillar\Support\HandlesDatabaseDriverSpecifics;
use Pillar\Metrics\Metrics;
use Pillar\Metrics\Counter;
use Throwable;

class DatabaseOutbox implements Outbox
{
    use HandlesDatabaseDriverSpecifics;

    private Counter $outboxEnqueuedCounter;
    private Counter $outboxClaimedCounter;
    private Counter $outboxPublishedCounter;
    private Counter $outboxFailedCounter;

    public function __construct(
        #[Config('pillar.outbox.tables.outbox', 'outbox')]
        private readonly string $table,
        #[Config('pillar.outbox.worker.claim_ttl', 15)]
        private readonly int    $claimTtl,
        #[Config('pillar.outbox.worker.retry_backoff', 60)]
        private readonly int    $retryBackoff,
        Metrics $metrics
    )
    {
        $this->outboxEnqueuedCounter = $metrics->counter(
            'outbox_enqueued_total',
            ['partition']
        );

        $this->outboxClaimedCounter = $metrics->counter(
            'outbox_claimed_total',
            ['partition']
        );

        $this->outboxPublishedCounter = $metrics->counter(
            'outbox_published_total',
            ['partition']
        );

        $this->outboxFailedCounter = $metrics->counter(
            'outbox_failed_total',
            ['partition']
        );
    }

    public function enqueue(int $globalSequence, ?string $partition = null): void
    {
        DB::table($this->table)->insert([
            'global_sequence' => $globalSequence,
            'available_at' => $this->dbNow(),
            'partition_key' => $partition,
        ]);
        $this->outboxEnqueuedCounter->inc(1, [
            'partition' => $partition ?? '',
        ]);
    }

    /**
     * Claim a batch of pending outbox rows.
     *
     * Semantics:
     * - $partitions = []  → no partition filter (single worker / no partitioning).
     * - $partitions = ['p00','p07',...] → only claim from those partitions.
     *
     * Implementation notes:
     * - No N+1:
     *   - Postgres/SQLite: single UPDATE … RETURNING returns exactly the rows claimed.
     *   - MySQL/Generic :  SELECT candidate ids → batch UPDATE (set claim_token + bump available_at)
     *                      → SELECT rows WHERE claim_token = $token.
     * - Uses DB time via HandlesDatabaseDriverSpecifics for portability.
     *
     * @return OutboxMessage[]
     */
    public function claimPending(int $limit = 100, array $partitions = []): array
    {
        $token = (string)Str::uuid();

        $messages = match ($this->dbDriver()) {
            'pgsql' => $this->claimPendingPgsql($limit, $partitions, $token),
            'sqlite' => $this->claimPendingSqlite($limit, $partitions, $token),
            'mysql' => $this->claimPendingMysql($limit, $partitions, $token),
            default => $this->claimPendingGeneric($limit, $partitions, $token),
        };

        foreach ($messages as $message) {
            $this->outboxClaimedCounter->inc(1, [
                'partition' => $message->partitionKey ?? '',
            ]);
        }

        return $messages;
    }

    /** Build a raw SQL partition filter clause and its bound params. */
    private function buildPartitionClause(array $partitions): array
    {
        if ($partitions === []) {
            return ['', []];
        }
        $placeholders = implode(',', array_fill(0, count($partitions), '?'));
        return [" AND partition_key IN ($placeholders)", array_values($partitions)];
    }

    /**
     * @param array<object> $rows
     * @return OutboxMessage[]
     */
    private function hydrateRows(array $rows): array
    {
        return array_map(
            static fn(object $r): OutboxMessage => OutboxMessage::fromRow($r),
            $rows
        );
    }

    protected function claimPendingPgsql(int $limit, array $partitions, string $token): array
    {
        [$partClause, $partParams] = $this->buildPartitionClause($partitions);

        $sql = "WITH cte AS (
                    SELECT global_sequence
                    FROM {$this->table}
                    WHERE published_at IS NULL
                      AND available_at <= (NOW() AT TIME ZONE 'UTC')
                      {$partClause}
                    ORDER BY available_at, global_sequence
                    LIMIT ?
                )
                UPDATE {$this->table} o
                SET available_at = (NOW() + INTERVAL '{$this->claimTtl} seconds'),
                    claim_token  = ?
                FROM cte
                WHERE o.global_sequence = cte.global_sequence
                RETURNING o.global_sequence, o.attempts, o.available_at, o.published_at, o.partition_key, o.last_error";

        $params = array_merge($partParams, [$limit, $token]);
        $rows = DB::select($sql, $params);

        return $this->hydrateRows($rows);
    }

    protected function claimPendingSqlite(int $limit, array $partitions, string $token): array
    {
        [$partClause, $partParams] = $this->buildPartitionClause($partitions);

        $sql = "UPDATE {$this->table}
                SET available_at = datetime('now', '+{$this->claimTtl} seconds'),
                    claim_token  = ?
                WHERE global_sequence IN (
                    SELECT global_sequence FROM {$this->table}
                    WHERE published_at IS NULL
                      AND available_at <= datetime('now')
                      {$partClause}
                    ORDER BY available_at, global_sequence
                    LIMIT ?
                )
                RETURNING global_sequence, attempts, available_at, published_at, partition_key, last_error";

        $params = array_merge([$token], $partParams, [$limit]);
        $rows = DB::select($sql, $params);

        return $this->hydrateRows($rows);
    }

    protected function claimPendingMysql(int $limit, array $partitions, string $token): array
    {
        [$partClause, $partParams] = $this->buildPartitionClause($partitions);

        $update = "UPDATE {$this->table} o
                   JOIN (
                       SELECT global_sequence FROM {$this->table}
                       WHERE published_at IS NULL
                         AND available_at <= UTC_TIMESTAMP()
                         {$partClause}
                       ORDER BY available_at, global_sequence
                       LIMIT ?
                   ) AS sel ON sel.global_sequence = o.global_sequence
                   SET o.available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$this->claimTtl} SECOND),
                       o.claim_token  = ?";

        $params = array_merge($partParams, [$limit, $token]);
        DB::update($update, $params);

        $rows = DB::table($this->table)
            ->where('claim_token', $token)
            ->orderBy('available_at')
            ->orderBy('global_sequence')
            ->get(['global_sequence', 'attempts', 'available_at', 'published_at', 'partition_key', 'last_error'])
            ->all();

        return $this->hydrateRows($rows);
    }

    protected function claimPendingGeneric(int $limit, array $partitions, string $token): array
    {
        $candidates = DB::table($this->table)
            ->whereNull('published_at')
            ->where('available_at', '<=', $this->dbNow())
            ->when($partitions !== [], fn($q) => $q->whereIn('partition_key', $partitions))
            ->orderBy('available_at')
            ->orderBy('global_sequence')
            ->limit($limit)
            ->get(['global_sequence']);

        if ($candidates->isEmpty()) {
            return [];
        }

        $ids = $candidates->pluck('global_sequence')->all();

        DB::table($this->table)
            ->whereIn('global_sequence', $ids)
            ->whereNull('published_at')
            ->where('available_at', '<=', $this->dbNow())
            ->update([
                'available_at' => $this->dbPlusSeconds($this->claimTtl),
                'claim_token' => $token,
            ]);

        $rows = DB::table($this->table)
            ->whereIn('global_sequence', $ids)
            ->where('claim_token', $token)
            ->orderBy('available_at')
            ->orderBy('global_sequence')
            ->get(['global_sequence', 'attempts', 'available_at', 'published_at', 'partition_key', 'last_error'])
            ->all();

        return $this->hydrateRows($rows);
    }

    public function markPublished(OutboxMessage $message): void
    {
        DB::table($this->table)
            ->where('global_sequence', $message->globalSequence)
            ->update([
                'published_at' => $this->dbNow(),
                'claim_token' => null,
            ]);
        $this->outboxPublishedCounter->inc(1, [
            'partition' => $message->partitionKey ?? '',
        ]);
    }

    public function markFailed(OutboxMessage $message, Throwable $error): void
    {
        DB::table($this->table)
            ->where('global_sequence', $message->globalSequence)
            ->update([
                'attempts' => DB::raw('attempts + 1'),
                'available_at' => $this->dbPlusSeconds($this->retryBackoff),
                'claim_token' => null,
                'last_error' => substr($error->getMessage(), 0, 1000),
            ]);
        $this->outboxFailedCounter->inc(1, [
            'partition' => $message->partitionKey ?? '',
        ]);
    }
}