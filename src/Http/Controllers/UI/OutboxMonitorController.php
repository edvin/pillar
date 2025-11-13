<?php
// @codeCoverageIgnoreStart

namespace Pillar\Http\Controllers\UI;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Pillar\Outbox\Worker\WorkerRegistry;
use Pillar\Outbox\Partitioner;

final class OutboxMonitorController extends Controller
{
    public function __construct(
        private readonly WorkerRegistry $registry,
        private readonly Partitioner $partitioner,

    ) {}

    public function index()
    {
        return view('pillar-ui::outbox');
    }

    /** JSON: active workers + meta */
    public function workers()
    {
        $now = CarbonImmutable::now('UTC');

        $rows = DB::table(config('pillar.outbox.tables.workers', 'outbox_workers'))
            ->orderBy('id')
            ->get([
                'id','hostname','pid','started_at','heartbeat_until','updated_at',
            ])
            ->map(function ($r) use ($now) {
                $hb = $r->heartbeat_until ? new CarbonImmutable($r->heartbeat_until) : null;
                $started = $r->started_at ? new CarbonImmutable($r->started_at) : null;
                return [
                    'id' => $r->id,
                    'hostname' => $r->hostname,
                    'pid' => (int)$r->pid,
                    'started_at' => $started?->toIso8601String(),
                    'heartbeat_until' => $hb?->toIso8601String(),
                    'status' => ($hb && $hb->greaterThan($now)) ? 'active' : 'stale',
                    'ttl_sec' => $hb ? max(0, $hb->diffInSeconds($now, absolute: false) * -1) : null,
                ];
            })
            ->all();

        // dynamic discovery: show how many should be targeted per worker
        $activeIds = $this->registry->activeIds();
        $partitionCount = (int) config('pillar.outbox.partition_count', 64);
        $byWorker = [];
        if ($partitionCount > 1) {
            $n = max(1, count($activeIds));
            foreach ($activeIds as $idx => $wid) {
                // Deterministic stable-modulo assignment: this worker gets i = idx, idx+n, idx+2n, ...
                $idxs = range($idx, $partitionCount - 1, $n);
                $labels = [];
                foreach ($idxs as $i) {
                    $label = $this->partitioner->labelForIndex($i);
                    if ($label !== null) {
                        $labels[] = $label;
                    }
                }
                $byWorker[$wid] = $labels;
            }
        }

        return response()->json([
            'items' => $rows,
            'active_ids' => $activeIds,
            'target_partitions' => $byWorker,
        ]);
    }

    /** JSON: current partitions status */
    public function partitions()
    {
        $now = CarbonImmutable::now('UTC');
        $table = config('pillar.outbox.tables.partitions', 'outbox_partitions');

        $rows = DB::table($table)
            ->orderBy('partition_key')
            ->get(['partition_key','lease_owner','lease_until','updated_at','lease_epoch'])
            ->map(function ($r) use ($now) {
                $until = $r->lease_until ? new CarbonImmutable($r->lease_until) : null;
                $owned = ($r->lease_owner !== null) && ($until && $until->greaterThan($now));
                return [
                    'partition_key' => $r->partition_key,
                    'lease_owner' => $r->lease_owner,
                    'lease_epoch' => (int)($r->lease_epoch ?? 0),
                    'lease_until' => $until?->toIso8601String(),
                    'owned' => $owned,
                    'ttl_sec' => $until ? max(0, $until->diffInSeconds($now, absolute: false) * -1) : null,
                ];
            })
            ->all();

        return response()->json(['items' => $rows]);
    }

    /** JSON: outbox messages (pending|published) */
    public function messages(Request $req)
    {
        $table = config('pillar.outbox.tables.outbox', 'outbox');
        $status = (string) $req->query('status', 'pending'); // pending|published|all
        $limit = max(1, min((int)$req->query('limit', 100), 500));

        $q = DB::table($table);
        if ($status === 'pending') {
            $q->whereNull('published_at');
        } elseif ($status === 'published') {
            $q->whereNotNull('published_at');
        }
        $rows = $q->orderByDesc(DB::raw('COALESCE(published_at, available_at)'))
            ->limit($limit)
            ->get([
                'global_sequence','partition_key','available_at','published_at','attempts','last_error'
            ])->map(function ($r) {
                return [
                    'seq' => (int)$r->global_sequence,
                    'partition' => $r->partition_key,
                    'available_at' => $r->available_at ? CarbonImmutable::parse($r->available_at)->toIso8601String() : null,
                    'published_at' => $r->published_at ? CarbonImmutable::parse($r->published_at)->toIso8601String() : null,
                    'attempts' => (int)$r->attempts,
                    'last_error' => $r->last_error,
                ];
            })->all();

        return response()->json(['items' => $rows]);
    }

    /** JSON: simple metrics (backlog + published last hour sparkline) */
    public function metrics()
    {
        $table = config('pillar.outbox.tables.outbox', 'outbox');
        $now = CarbonImmutable::now('UTC');
        $hourAgo = $now->subHour();

        $backlog = (int) DB::table($table)->whereNull('published_at')->count();

        $published = DB::table($table)
            ->whereNotNull('published_at')
            ->where('published_at', '>=', $hourAgo)
            ->orderBy('published_at')
            ->get(['published_at'])
            ->all();

        // bucket by minute in PHP (portable)
        $buckets = [];
        for ($i = 59; $i >= 0; $i--) {
            $minute = $now->subMinutes($i)->format('Y-m-d H:i');
            $buckets[$minute] = 0;
        }
        foreach ($published as $r) {
            $m = CarbonImmutable::parse($r->published_at)->format('Y-m-d H:i');
            if (isset($buckets[$m])) $buckets[$m]++;
        }

        return response()->json([
            'backlog' => $backlog,
            'published_1h' => array_values($buckets),
            'labels_1h' => array_keys($buckets),
        ]);
    }
}
// @codeCoverageIgnoreEnd