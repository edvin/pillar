<?php

namespace Pillar\Console;

use Pillar\Aggregate\AggregateRootId;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Pillar\Event\EventReplayer;
use Throwable;

class ReplayEventsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Usage:
     *  php artisan pillar:replay-events
     *  php artisan pillar:replay-events {aggregate_id}
     *  php artisan pillar:replay-events {aggregate_id} {event_type}
     *  php artisan pillar:replay-events null {event_type}
     */
    protected $signature = 'pillar:replay-events
                            {aggregate_id? : Aggregate ID (UUID) to filter by, or "null" for all}
                            {event_type? : Fully-qualified event class to filter by}';

    /**
     * The console command description.
     */
    protected $description = 'Replay stored domain events to rebuild projections';

    public function __construct(private readonly EventReplayer $replayer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $aggregateArg = $this->argument('aggregate_id');
        $eventType = $this->argument('event_type') ?: null;

        // Normalize aggregate id
        $aggregateId = null;
        if ($aggregateArg !== null && strtolower((string)$aggregateArg) !== 'null') {
            try {
                $aggregateId = AggregateRootId::from($aggregateArg);
            } catch (InvalidArgumentException $e) {
                $this->error("Invalid aggregate_id: $aggregateArg. {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        $scope = match (true) {
            $aggregateId !== null && $eventType !== null => "aggregate {$aggregateId->value()} and event $eventType",
            $aggregateId !== null => "aggregate {$aggregateId->value()}",
            $eventType !== null => "event $eventType",
            default => 'all events',
        };

        $this->info("Replaying $scope...");
        $this->newLine();

        try {
            $this->replayer->replay($aggregateId, $eventType);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error("Replay failed: {$e->getMessage()}");
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('✅ Replay completed successfully.');
        return self::SUCCESS;
    }
}