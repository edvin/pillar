<?php
declare(strict_types=1);

namespace Pillar\Outbox\Lease;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;
use Pillar\Support\HandlesDatabaseDriverSpecifics;

/**
 * DatabasePartitionLeaseStore
 *
 * DB-backed cooperative leasing for outbox partitions (e.g., p00..p63).
 * - Requires partitions to be pre-seeded via CLI (see: pillar:outbox:partitions:sync).
 * - tryLease(): acquire a lease if the partition is free/expired, or renew if already owned.
 * - ownedBy():  list active (non-expired) leases owned by a given worker, optionally filtered to a subset.
 * - renew():    extend an existing lease you already own.
 * - release():  relinquish a lease you own (optional; expiry also works).
 *
 * Uses the database clock for all timestamps to avoid cross-host clock skew.
 */
final class DatabasePartitionLeaseStore implements PartitionLeaseStore
{
    use HandlesDatabaseDriverSpecifics;

    public function __construct(
        #[Config('pillar.outbox.tables.partitions')]
        private readonly string $table = 'outbox_partitions',
    )
    {

    }

    /**
     * Seed the partitions table with the given keys (idempotent).
     * Intended to be called from a CLI command when partition_count changes.
     *
     * @param list<string> $partitions
     */
    public function seed(array $partitions): void
    {
        if ($partitions === []) {
            return;
        }

        // Build minimal rows; other columns are nullable / have defaults.
        $rows = [];
        foreach (array_unique($partitions) as $p) {
            $rows[] = [
                'partition_key' => $p,
                // Keep updated_at sensible for brand-new rows.
                'updated_at' => $this->dbNow(),
            ];
        }

        // Chunk to avoid oversized multi-insert statements.
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($this->table)->insertOrIgnore($chunk);
        }
    }

    /**
     * Delete partition rows that are not part of the current key space.
     * Only removes rows that are unleased or already expired.
     * @param list<string> $keep Keys to keep (current keyspace).
     * @return int  Rows deleted.
     */
    public function pruneObsolete(array $keep): int
    {
        if ($keep === []) {
            return 0;
        }

        return DB::table($this->table)
            ->whereNotIn('partition_key', $keep)
            ->where(function ($q) {
                $q->whereNull('lease_owner')
                    ->orWhere('lease_until', '<=', $this->dbNow());
            })
            ->delete();
    }

    /**
     * Acquire a lease for $partition if it's free/expired, or renew if already owned by $owner.
     * Returns true on success, false otherwise.
     * Note: partition rows must be pre-seeded; see seed().
     */
    public function tryLease(array $partitions, string $owner, int $ttlSeconds): bool
    {
        // Guard: nothing to do
        if ($partitions === []) {
            return false;
        }

        // 1) Renew any we already own (does not bump epoch)
        $renewed = DB::table($this->table)
            ->whereIn('partition_key', $partitions)
            ->where('lease_owner', $owner)
            ->update([
                'lease_until' => $this->dbPlusSeconds($ttlSeconds),
                'updated_at' => $this->dbNow(),
            ]);

        // 2) Take any free/expired partitions in one batch
        $took = DB::table($this->table)
            ->whereIn('partition_key', $partitions)
            ->where(function ($q) {
                $q->whereNull('lease_until')
                    ->orWhere('lease_until', '<', $this->dbNow());
            })
            ->update([
                'lease_owner' => $owner,
                'lease_until' => $this->dbPlusSeconds($ttlSeconds),
                'lease_epoch' => DB::raw('lease_epoch + 1'),
                'updated_at' => $this->dbNow(),
            ]);

        return ($took + $renewed) > 0;
    }

    /**
     * Extend a lease you already own. Returns true if extended.
     */
    public function renew(array $partitions, string $owner, int $ttlSeconds): bool
    {
        if ($partitions === []) {
            return false;
        }

        $res = DB::table($this->table)
            ->whereIn('partition_key', $partitions)
            ->where('lease_owner', $owner)
            ->update([
                'lease_until' => $this->dbPlusSeconds($ttlSeconds),
                'updated_at' => $this->dbNow(),
            ]);

        return $res > 0;
    }

    /**
     * Return the list of partition_keys currently leased by $owner and not expired.
     * If $limitTo is provided, the result is intersected with that subset.
     *
     * @param string $owner
     * @param list<string>|null $limitTo
     * @return list<string>
     */
    public function ownedBy(string $owner, ?array $limitTo = null): array
    {
        $q = DB::table($this->table)
            ->where('lease_owner', $owner)
            ->where('lease_until', '>', $this->dbNow());

        if ($limitTo !== null && $limitTo !== []) {
            $q->whereIn('partition_key', $limitTo);
        }

        /** @var list<string> $keys */
        $keys = $q->orderBy('partition_key')
            ->pluck('partition_key')
            ->all();

        return $keys;
    }

    /**
     * Release a lease you own (optional; otherwise it expires at lease_until).
     * Note: partition rows must be pre-seeded; see seed().
     */
    public function release(array $partitions, string $owner): void
    {
        if ($partitions === []) {
            return;
        }

        DB::table($this->table)
            ->whereIn('partition_key', $partitions)
            ->where('lease_owner', $owner)
            ->update([
                'lease_owner' => null,
                'lease_until' => null,
                'updated_at' => $this->dbNow(),
            ]);
    }

}