<?php

namespace Pillar\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Pillar\Aggregate\GenericAggregateId;
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
     *  php artisan pillar:replay-events --from-seq=100 --to-seq=200
     *  php artisan pillar:replay-events --from-date="2025-01-01T00:00:00Z" --to-date="2025-01-31T23:59:59Z"
     *  php artisan pillar:replay-events {aggregate_id} {event_type} --from-date="2025-01-01" --to-seq=500
     *
     * Arguments:
     *  - aggregate_id Optional UUID of an aggregate, or the string "null" to include all aggregates.
     *  - event_type Optional fully qualified event class to filter by.
     *
     * Options:
     *  --from-seq Only replay events with a global sequence >= this value (inclusive).
     *  --to-seq Only replay events with a global sequence <= this value (inclusive).
     *  --from-date Only replay events with occurred_at are >= this timestamp (inclusive). ISO-8601 or anything Carbon parses.
     *  --to-date Only replay events with occurred_at <= this timestamp (inclusive). ISO-8601 or anything Carbon parses.
     */
    protected $signature = 'pillar:replay-events
                            {aggregate_id? : Aggregate ID (UUID) to filter by, or "null" for all}
                            {event_type? : Fully-qualified event class to filter by}
                            {--from-seq= : Only replay events with global sequence >= this}
                            {--to-seq= : Only replay events with global sequence <= this}
                            {--from-date= : Only replay events with occurred_at >= this (ISO8601 or parseable)}
                            {--to-date= : Only replay events with occurred_at <= this (ISO8601 or parseable)}';

    /**
     * The console command description.
     */
    protected $description = 'Replay stored domain events to rebuild projections';

    public function __construct(private readonly EventReplayer $replayer)
    {
        parent::__construct();
    }

    /**
     * Execute the replay with optional filtering by aggregate, event type, sequence window, and/or date window.
     *
     * Date filters are parsed in UTC and compared inclusively against the `occurred_at` timestamp of each event.
     * Sequence filters use the global `sequence` number and are inclusive. The `to-seq` upper bound is short-circuited
     * since cross-aggregate reads are ordered by global sequence.
     *
     * @return int `Command::SUCCESS` on success or `Command::FAILURE` if validation or replay fails.
     */
    public function handle(): int
    {
        $aggregateArg = $this->argument('aggregate_id');
        $eventType = $this->argument('event_type') ?: null;

        // Normalize aggregate id
        $aggregateId = null;
        if ($aggregateArg !== null && strtolower((string) $aggregateArg) !== 'null') {
            $uuid = (string) $aggregateArg;
            if (!Str::isUuid($uuid)) {
                $this->error("Invalid aggregate_id: $aggregateArg. Must be a UUID.");
                return self::FAILURE;
            }

            $aggregateId = new GenericAggregateId($uuid);
        }

        $fromSeq = $this->option('from-seq') !== null ? (int)$this->option('from-seq') : null;
        $toSeq = $this->option('to-seq') !== null ? (int)$this->option('to-seq') : null;

        $fromDate = $this->option('from-date')
            ? CarbonImmutable::parse((string)$this->option('from-date'))->utc()->format('Y-m-d H:i:s')
            : null;
        $toDate = $this->option('to-date')
            ? CarbonImmutable::parse((string)$this->option('to-date'))->utc()->format('Y-m-d H:i:s')
            : null;

        $scope = match (true) {
            $aggregateId !== null && $eventType !== null => "aggregate {$aggregateId->value()} and event $eventType",
            $aggregateId !== null => "aggregate {$aggregateId->value()}",
            $eventType !== null => "event $eventType",
            default => 'all events',
        };

        $window = [];
        if ($fromSeq !== null) $window[] = "seq>=$fromSeq";
        if ($toSeq !== null) $window[] = "seq<=$toSeq";
        if ($fromDate) $window[] = "date>={$fromDate}Z";
        if ($toDate) $window[] = "date<={$toDate}Z";

        $this->info("Replaying $scope" . (count($window) ? ' [' . implode(', ', $window) . ']' : '') . '...');
        $this->newLine();

        try {
            $this->replayer->replay($aggregateId, $eventType, $fromSeq, $toSeq, $fromDate, $toDate);
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