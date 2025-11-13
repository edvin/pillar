<?php
declare(strict_types=1);

namespace Pillar\Console;

use Illuminate\Console\Command;
use Illuminate\Container\Attributes\Config;
use Pillar\Outbox\Lease\PartitionLeaseStore;
use Pillar\Outbox\Partitioner;

final class OutboxPartitionSyncCommand extends Command
{
    protected $signature = 'pillar:outbox:partitions:sync
        {--prune : Delete obsolete partitions not in the current keyspace}
        {--dry-run : Show what would happen without writing}';

    protected $description = 'Seed (and optionally prune) outbox partitions to match the current configuration.';

    public function __construct(
        private readonly PartitionLeaseStore $leases,
        private readonly Partitioner         $partitioner,
        #[Config('pillar.outbox.partition_count')]
        private readonly int                 $partitionCount = 64,
    )
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Build desired keyspace from the configured partitioner/partition_count
        $want = [];
        for ($i = 0; $i < max(0, $this->partitionCount); $i++) {
            $want[] = $this->partitioner->labelForIndex($i);
        }

        $dry = (bool)$this->option('dry-run');
        $prune = (bool)$this->option('prune');

        if ($dry) {
            $this->line('[DRY RUN] Desired partitions: ' . implode(', ', $want));
            $this->line('[DRY RUN] Would call seed(want)' . ($prune ? ' and pruneObsolete(want)' : ''));
            return self::SUCCESS;
        }

        // Create any missing partition rows (idempotent)
        if (method_exists($this->leases, 'seed')) {
            $this->leases->seed($want);
            $this->info('Seeded partition rows (idempotent).');
        } else {
            $this->warn('Lease store does not support seeding.');
        }

        // Optionally prune obsolete rows (only non-leased or expired are removed)
        if ($prune) {
            if (method_exists($this->leases, 'pruneObsolete')) {
                $deleted = $this->leases->pruneObsolete($want);
                $this->info("Pruned $deleted obsolete partitions.");
            } else {
                $this->warn('Lease store does not support pruning.');
            }
        } else {
            $this->line('Skip pruning (use --prune to remove obsolete rows).');
        }

        $this->info('Done.');
        return self::SUCCESS;
    }
}