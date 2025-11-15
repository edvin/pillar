<?php
declare(strict_types=1);

namespace Pillar\Outbox\Worker;

use Illuminate\Container\Attributes\Config;
use Illuminate\Support\Facades\DB;
use Pillar\Support\HandlesDatabaseDriverSpecifics;

class WorkerRegistry
{
    use HandlesDatabaseDriverSpecifics;

    public function __construct(
        #[Config('pillar.outbox.tables.workers')]
        private readonly string $table = 'outbox_workers',
        #[Config('pillar.outbox.worker.heartbeat_ttl')]
        private readonly int    $heartbeatTtl = 20,
    )
    {
    }

    public function join(WorkerIdentity $w): void
    {
        DB::table($this->table)->insertOrIgnore([
            'id' => $w->id,
            'hostname' => $w->hostname,
            'pid' => $w->pid,
            'started_at' => $this->dbNow(),
            'heartbeat_until' => $this->dbPlusSeconds($this->heartbeatTtl),
            'updated_at' => $this->dbNow(),
        ]);

        DB::table($this->table)
            ->where('id', $w->id)
            ->update([
                'hostname' => $w->hostname,
                'pid' => $w->pid,
                'heartbeat_until' => $this->dbPlusSeconds($this->heartbeatTtl),
                'updated_at' => $this->dbNow(),
            ]);
    }

    public function heartbeat(WorkerIdentity $w): void
    {
        DB::table($this->table)
            ->where('id', $w->id)
            ->update([
                'heartbeat_until' => $this->dbPlusSeconds($this->heartbeatTtl),
                'updated_at' => $this->dbNow(),
            ]);
    }

    public function leave(WorkerIdentity $w): void
    {
        DB::table($this->table)
            ->where('id', $w->id)
            ->delete();
    }

    /** @return list<string> active worker ids ordered for stable modulo */
    public function activeIds(): array
    {
        return DB::table($this->table)
            ->where('heartbeat_until', '>', $this->dbNow())
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public function purgeStale(): int
    {
        return DB::table($this->table)
            ->where('heartbeat_until', '<=', $this->dbNow())
            ->delete();
    }
}