<?php

namespace Pillar\Snapshot;

use Illuminate\Container\Attributes\Config;
use Pillar\Aggregate\AggregateRoot;

/**
 * Snapshot cadence policy: take a snapshot every N events with an optional phase (offset).
 *
 * Rationale
 * ---------
 * • `threshold` controls the *frequency* (e.g., every 100 events).
 * • `offset` controls the *phase*—it shifts where in the sequence the cadence lands.
 *
 * Why an offset?
 * • Align the first snapshot with an already existing checkpoint (e.g., after a migration).
 * • Stagger snapshots across aggregates to reduce synchronized workload spikes (avoid a thundering herd).
 * • Change the phase of the cadence without changing its frequency.
 *
 * Behavior
 * --------
 * • A snapshot is only considered on commits that actually persist new events (`$delta > 0`).
 * • A non‑positive `threshold` disables snapshotting via this policy.
 * • The decision rule is: `(newSeq - offset) % threshold === 0`.
 *
 * Examples
 * --------
 * • threshold=100, offset=0   → snapshots at 100, 200, 300, …
 * • threshold=100, offset=37  → snapshots at 37, 137, 237, …
 * • threshold=50,  offset=49  → snapshots at 49, 99, 149, …
 */
final class CadenceSnapshotPolicy implements SnapshotPolicy
{
    /**
     * @param int $threshold Snapshot every N events (N &gt; 0). Set ≤ 0 to disable.
     * @param int $offset Phase shift for the cadence. Use to align/stagger snapshots.
     *
     * Notes:
     * • Choose 0 ≤ $offset &lt; $threshold to avoid surprises (values wrap via modulo).
     * • This policy makes a decision only when new events were appended in the current commit.
     */
    public function __construct(
        private readonly int $threshold = 25,  // snapshot every N events
        private readonly int $offset = 0        // snapshot when (newSeq - offset) % N === 0
    )
    {
    }

    /**
     * Decide whether to snapshot after a commit.
     *
     * The cadence triggers when `(newSeq - offset) % threshold === 0`, provided:
     * • `$delta &gt; 0` (the commit actually persisted new events), and
     * • `$threshold &gt; 0`.
     *
     * @param AggregateRoot $aggregate The aggregate being saved (not inspected here).
     * @param int $newSeq Persisted version after this commit.
     * @param int $prevSeq Persisted version before this commit.
     * @param int $delta Number of events added in this commit (`$newSeq - $prevSeq`).
     * @return bool         True if a snapshot should be taken at this version.
     */
    public function shouldSnapshot(AggregateRoot $aggregate, int $newSeq, int $prevSeq, int $delta): bool
    {
        if ($delta <= 0) return false;
        if ($this->threshold <= 0) return false;

        return (($newSeq - $this->offset) % $this->threshold) === 0;
    }

    public static function always(): self
    {
        return new self(threshold: 1);
    }

}
