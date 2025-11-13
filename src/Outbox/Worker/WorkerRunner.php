<?php

namespace Pillar\Outbox\Worker;

use Illuminate\Container\Attributes\Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Pillar\Event\EventStore;
use Pillar\Outbox\Lease\PartitionLeaseStore;
use Pillar\Outbox\Outbox;
use Pillar\Outbox\Partitioner;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class WorkerRunner
{
    private WorkerIdentity $identity;
    private bool $joined = false;
    /** @var list<string> */
    private array $owned = [];
    private int $lastRenewNs = 0;
    /** @var list<string> */
    private array $cachedActiveWorkers = [];
    /** @var list<string> */
    private array $cachedDesired = [];

    public function __construct(
        private readonly WorkerRegistry      $registry,
        private readonly PartitionLeaseStore $leases,
        private readonly Outbox              $outbox,
        private readonly EventStore          $eventStore,
        private readonly Dispatcher          $dispatcher,
        private readonly ?string             $workerId = null,
        private readonly Partitioner         $partitioner,
        #[Config('pillar.outbox.worker.leasing')]
        private readonly bool                $leasing = true,
        #[Config('pillar.outbox.partition_count')]
        private readonly int                 $partitionCount = 16,
        #[Config('pillar.outbox.worker.batch_size')]
        private readonly int                 $batchSize = 100,
        #[Config('pillar.outbox.worker.lease_ttl')]
        private readonly int                 $leaseTtl = 15,
        #[Config('pillar.outbox.worker.lease_renew')]
        private readonly int                 $leaseRenew = 6,
        #[Config('pillar.outbox.worker.idle_backoff_ms')]
        private readonly int                 $idleBackoffMs = 200,
    )
    {
        $this->identity = new WorkerIdentity(
            $this->workerId ?? Str::uuid()->toString(),
            gethostname(),
            getmypid()
        );
    }

    /**
     * Execute a single work cycle and return a summary for optional CLI reporting.
     *
     * - Joins/heartbeats the worker registry
     * - Acquires/renews partition leases (if enabled)
     * - Claims a batch from the outbox and publishes them
     * - Opportunistically purges stale worker rows (shared, rate-limited)
     */
    public function tick(): TickResult
    {
        $t0 = (int)hrtime(true);

        $renewedHeartbeat = false;
        $leasedPartitions = [];
        $releasedPartitions = [];
        $desiredPartitions = [];
        $purged = 0;

        // One-time join
        if (!$this->joined) {
            $this->registry->join($this->identity);
            $this->joined = true;
        }

        // Heartbeat & lease renew on cadence
        $nowNs = (int)hrtime(true);
        $dueRenew = ($this->lastRenewNs === 0)
            || (($nowNs - $this->lastRenewNs) / 1e9 >= $this->leaseRenew);

        if ($dueRenew) {
            $this->registry->heartbeat($this->identity);
            $renewedHeartbeat = true;
            if ($this->leasing && $this->owned !== []) {
                $this->leases->renew($this->owned, $this->identity->id, $this->leaseTtl);
            }
            $this->lastRenewNs = $nowNs;
        }

        // Cache active worker ids; refresh only on renew cadence or first run
        if ($this->cachedActiveWorkers === [] || $dueRenew) {
            $this->cachedActiveWorkers = $this->activeWorkerIds();
        }
        $activeWorkersList = $this->cachedActiveWorkers;

        // Acquire partitions (leasing mode)
        $partitions = [];
        if ($this->leasing) {
            // Compute desired set for reporting; refresh when workers list changes
            if ($this->cachedDesired === [] || $dueRenew) {
                $this->cachedDesired = $this->targetPartitionsFromWorkers($activeWorkersList);
            }
            $desired = $this->cachedDesired;
            $desiredPartitions = $desired;

            // Decide if we need a full lease sync this tick:
            // - on renew cadence, or
            // - if we currently own nothing (startup/recover)
            $shouldSyncLeases = $dueRenew || $this->owned === [];

            if ($shouldSyncLeases) {
                // Sync from DB: what do we actually own right now?
                $ownedNow = $this->leases->ownedBy($this->identity->id, $desired);

                // Release any partitions we currently own but shouldn't
                $toRelease = array_values(array_diff($ownedNow, $desired));
                if ($toRelease !== []) {
                    $this->leases->release($toRelease, $this->identity->id);
                    $ownedNow = array_values(array_diff($ownedNow, $toRelease));
                }
                $releasedPartitions = $toRelease;

                // Try to lease any missing desired partitions
                $toLease = array_values(array_diff($desired, $ownedNow));
                if ($toLease !== []) {
                    $this->leases->tryLease($toLease, $this->identity->id, $this->leaseTtl);
                    $leasedPartitions = $toLease; // attempts this tick
                } else {
                    $leasedPartitions = [];
                }

                // Refresh ownership from DB (limit to desired for a stable view)
                $this->owned = $this->leases->ownedBy($this->identity->id, $desired);
            }

            // Claim only from what we believe we own (kept fresh by renew+periodic sync)
            $partitions = $this->owned;
        } else {
            // No leasing: claim from all partitions ("[]" = no filter)
            $partitions = [];
        }

        // Claim and publish a batch
        $messages = $this->outbox->claimPending($this->batchSize, $partitions);
        $processed = 0;
        $lastErrors = [];
        foreach ($messages as $m) {
            try {
                $stored = $this->eventStore->getByGlobalSequence($m->globalSequence);
                if ($stored === null) {
                    throw new RuntimeException('Stored event not found for sequence ' . $m->globalSequence);
                }
                $this->dispatcher->dispatch($stored->event);
                $this->outbox->markPublished($m);
                $processed++;
            } catch (Throwable $e) {
                $this->outbox->markFailed($m, $e);
                $lastErrors[] = [
                    'ts' => now()->toIso8601String(),
                    'msg' => $this->shortError($e),
                    'seq' => $m->globalSequence,
                ];
                if (count($lastErrors) > 5) {
                    array_shift($lastErrors);
                }
            }
        }

        $claimed = count($messages);
        $published = $processed;
        $failed = $claimed - $published;

        // Idle backoff (cooperative; CLI can still decide to ignore backoffMs)
        $backoffMs = 0;
        if ($processed === 0 && $this->idleBackoffMs > 0) {
            $backoffMs = $this->idleBackoffMs;
            usleep($this->idleBackoffMs * 1000);
        }

        // Purge stale workers at most once every 5 minutes across the fleet
        if (Cache::add('outbox:purge-stale:once', 1, now()->addMinutes(5))) {
            $purged = $this->registry->purgeStale();
            Log::info("Purged $purged stale workers");
        }

        $t1 = (int)hrtime(true);
        $durationMs = ($t1 - $t0) / 1e6;

        return new TickResult(
            renewedHeartbeat: $renewedHeartbeat,
            activeWorkers: count($activeWorkersList),
            desiredPartitions: $desiredPartitions,
            leasedPartitions: $leasedPartitions,
            releasedPartitions: $releasedPartitions,
            ownedPartitions: $this->owned,
            claimed: $claimed,
            published: $published,
            failed: $failed,
            purgedStale: $purged,
            backoffMs: $backoffMs,
            durationMs: $durationMs,
            lastErrors: $lastErrors,
        );
    }


    /** @return list<string> active worker ids (sorted) */
    private function activeWorkerIds(): array
    {
        $ids = $this->registry->activeIds();
        if (!in_array($this->identity->id, $ids, true)) {
            $ids[] = $this->identity->id; // ensure self is considered
        }
        sort($ids, SORT_STRING);
        return $ids;
    }

    /**
     * Compute target partitions using a pre-fetched list of active worker ids.
     * @param list<string> $workers
     * @return list<string>
     */
    private function targetPartitionsFromWorkers(array $workers): array
    {
        $count = max(0, $this->partitionCount);
        if ($count === 0) return [];

        $n = max(1, count($workers));
        $idx = array_search($this->identity->id, $workers, true);
        if ($idx === false) $idx = 0;

        $parts = [];
        for ($i = $idx; $i < $count; $i += $n) {
            $label = $this->partitioner->labelForIndex($i);
            if ($label !== null) {
                $parts[] = $label;
            }
        }
        return $parts;
    }

    /**
     * Partitions this worker should own based on stable modulo of active worker ids.
     * @return list<string> e.g. ['p00','p04',...]
     */
    private function targetPartitionsForMe(): array
    {
        $workers = $this->activeWorkerIds();
        return $this->targetPartitionsFromWorkers($workers);
    }

    private function shortError(Throwable $e): string
    {
        $class = new ReflectionClass($e)->getShortName();
        $msg = $e->getMessage();
        return $class . ': ' . $this->truncate($msg, 200);
    }

    private function truncate(string $s, int $max): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
        }
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }
}