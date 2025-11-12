<?php
declare(strict_types=1);

namespace Pillar\Outbox\Worker;

/**
 * Immutable per-tick summary returned by WorkerRunner::tick().
 *
 * - desiredPartitions: the target set for this worker (per stable modulo)
 * - leasedPartitions : partitions we attempted to lease this tick
 * - releasedPartitions: partitions we released this tick
 * - ownedPartitions  : current owned set after lease/release
 * - activeWorkers    : number of active workers seen this tick (if provided by the runner)
 * - lastErrors      : recent errors captured this tick/window for operator display
 */
final class TickResult
{
    /**
     * @param list<string> $desiredPartitions
     * @param list<string> $leasedPartitions
     * @param list<string> $releasedPartitions
     * @param list<string> $ownedPartitions
     */
    public function __construct(
        public readonly bool  $renewedHeartbeat,
        public readonly int   $activeWorkers,
        public readonly array $desiredPartitions,
        public readonly array $leasedPartitions,
        public readonly array $releasedPartitions,
        public readonly array $ownedPartitions,
        public readonly int   $claimed,
        public readonly int   $published,
        public readonly int   $failed,
        public readonly int   $purgedStale,
        public readonly int   $backoffMs,
        public readonly float $durationMs,
        /** @var list<array{ts:string,msg:string,seq?:int}> */
        public readonly array $lastErrors = [],
    )
    {
    }

    public function hadWork(): bool
    {
        return ($this->published + $this->failed) > 0;
    }
}