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
 *
 * - tryLease(): acquire a lease if the partition is free/expired, or renew if already owned.
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
     * Acquire a lease for $partition if it's free/expired, or renew if already owned by $owner.
     * Returns true on success, false otherwise.
     */
    public function tryLease(array $partitions, string $owner, int $ttlSeconds): bool
    {
        // Guard: nothing to do
        if ($partitions === []) {
            return false;
        }

        // 1) Take any free/expired partitions in one batch
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
                'updated_at'  => $this->dbNow(),
            ]);

        // 2) Renew any we already own (does not bump epoch)
        $renewed = DB::table($this->table)
            ->whereIn('partition_key', $partitions)
            ->where('lease_owner', $owner)
            ->update([
                'lease_until' => $this->dbPlusSeconds($ttlSeconds),
                'updated_at'  => $this->dbNow(),
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
                'updated_at'  => $this->dbNow(),
            ]);

        return $res > 0;
    }

    /**
     * Release a lease you own (optional; otherwise it expires at lease_until).
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
                'updated_at'  => $this->dbNow(),
            ]);
    }

}