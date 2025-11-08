<?php

namespace Pillar\Console;

use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Pillar\Aggregate\GenericAggregateId;
use Pillar\Event\EventReplayer;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

final class ReplayEventsCommand extends Command
{
    /**
     * Usage:
     *  php artisan pillar:replay-events
     *  php artisan pillar:replay-events {aggregate_id}
     *  php artisan pillar:replay-events {aggregate_id} {event_type}
     *  php artisan pillar:replay-events null {event_type}
     *  php artisan pillar:replay-events --from-seq=100 --to-seq=200
     *  php artisan pillar:replay-events --from-date="2025-01-01T00:00:00Z" --to-date="2025-01-31T23:59:59Z"
     */
    protected $signature = 'pillar:replay-events
                            {aggregate_id? : Aggregate ID (UUID) to filter by, or "null" for all}
                            {event_type? : Fully-qualified event class to filter by}
                            {--from-seq= : Only replay events with global sequence >= this}
                            {--to-seq= : Only replay events with global sequence <= this}
                            {--from-date= : Only replay events with occurred_at >= this (ISO8601 or parseable)}
                            {--to-date= : Only replay events with occurred_at <= this (ISO8601 or parseable)}';

    protected $description = 'Replay stored domain events to rebuild projections';

    public function __construct(private readonly EventReplayer $replayer)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        // Gather inputs (args/options)
        $aggregateArg = $this->argument('aggregate_id');
        $eventType    = $this->argument('event_type') ?: null;

        $fromSeq  = $this->option('from-seq') !== null ? (int) $this->option('from-seq') : null;
        $toSeq    = $this->option('to-seq') !== null ? (int) $this->option('to-seq') : null;
        $fromDate = $this->option('from-date') ? (string) $this->option('from-date') : null;
        $toDate   = $this->option('to-date') ? (string) $this->option('to-date') : null;

        // If nothing meaningful provided, run a friendly wizard
        $needsWizard = $aggregateArg === null
            && $eventType === null
            && $fromSeq === null
            && $toSeq === null
            && $fromDate === null
            && $toDate === null;

        if ($needsWizard) {
            $scope = select(
                label: 'Replay scope',
                options: [
                    'all'        => 'All events',
                    'aggregate'  => 'By aggregate ID',
                    'event'      => 'By event type (FQCN)',
                    'both'       => 'Aggregate + Event type',
                ],
                default: 'all',
                hint: 'Pick what subset of events to replay.'
            );

            if ($scope === 'aggregate' || $scope === 'both') {
                $aggregateArg = text(
                    label: 'Aggregate ID (UUID) or "null" for all',
                    default: 'null',
                    validate: function (string $v) {
                        if (strtolower($v) === 'null') return null;
                        return Str::isUuid($v) ? null : 'Please enter a valid UUID or "null".';
                    }
                );
            }

            if ($scope === 'event' || $scope === 'both') {
                $eventType = text(
                    label: 'Event class (FQCN, optional)',
                    default: '',
                    validate: function (string $v) {
                        if ($v === '') return null;
                        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $v)
                            ? null
                            : 'Please enter a valid FQCN or leave empty.';
                    },
                    hint: 'Example: Context\\Document\\Event\\DocumentRenamed'
                ) ?: null;
            }

            // Optional windows
            if (confirm('Filter by global sequence range?', default: false)) {
                $fromSeq = (int) (text(
                    label: 'From sequence (inclusive, empty to skip)',
                    default: '',
                    validate: fn (string $v) => ($v === '' || ctype_digit($v)) ? null : 'Enter a non-negative integer or leave empty.'
                ) ?: 0);
                $toSeqRaw = text(
                    label: 'To sequence (inclusive, empty to skip)',
                    default: '',
                    validate: fn (string $v) => ($v === '' || ctype_digit($v)) ? null : 'Enter a non-negative integer or leave empty.'
                );
                $toSeq = $toSeqRaw === '' ? null : (int) $toSeqRaw;
                if ($fromSeq === 0) $fromSeq = null;
            }

            if (confirm('Filter by occurred_at date range?', default: false)) {
                $fromDate = text(
                    label: 'From date/time (ISO8601 or parseable, empty to skip)',
                    default: '',
                    validate: fn (string $v) => ($v === '' ? null : $this->validateDate($v))
                ) ?: null;

                $toDate = text(
                    label: 'To date/time (ISO8601 or parseable, empty to skip)',
                    default: '',
                    validate: fn (string $v) => ($v === '' ? null : $this->validateDate($v))
                ) ?: null;
            }
        }

        // Normalize aggregate id
        $aggregateId = null;
        if ($aggregateArg !== null && strtolower((string) $aggregateArg) !== 'null') {
            $uuid = (string) $aggregateArg;
            try {
                $aggregateId = new GenericAggregateId($uuid);
            } catch (InvalidArgumentException $invalidUuid) {
                $this->error($invalidUuid->getMessage());
                return self::FAILURE;
            }
        }

        // Parse/normalize dates to UTC "Y-m-d H:i:s"
        $fromDateNorm = $fromDate
            ? CarbonImmutable::parse($fromDate)->utc()->format('Y-m-d H:i:s')
            : null;
        $toDateNorm = $toDate
            ? CarbonImmutable::parse($toDate)->utc()->format('Y-m-d H:i:s')
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
        if ($fromDateNorm) $window[] = "date>={$fromDateNorm}Z";
        if ($toDateNorm) $window[] = "date<={$toDateNorm}Z";

        $this->info("Replaying $scope" . (count($window) ? ' [' . implode(', ', $window) . ']' : '') . '...');

        // Spinner + timing (no new concepts; EventReplayer API unchanged)
        $start = microtime(true);
        $ok = spin(
            callback: function () use ($aggregateId, $eventType, $fromSeq, $toSeq, $fromDateNorm, $toDateNorm) {
                $this->replayer->replay($aggregateId, $eventType, $fromSeq, $toSeq, $fromDateNorm, $toDateNorm);
                return true;
            },
            message: 'Replaying events…'
        );

        if ($ok !== true) {
            // Shouldn’t happen; spin throws on failure
            $this->error('Replay failed.');
            return self::FAILURE;
        }

        $elapsed = microtime(true) - $start;
        $this->newLine();
        $this->info(sprintf('✅ Replay completed in %.2fs', $elapsed));
        if ($this->getOutput()->isVerbose()) {
            $this->line(sprintf('Peak memory: %.2f MB', memory_get_peak_usage(true) / (1024 * 1024)));
        }

        return self::SUCCESS;
    }

    private function validateDate(string $input): ?string
    {
        try {
            CarbonImmutable::parse($input);
            return null; // valid
        } catch (Exception $e) {
            return 'Unable to parse date/time. Use ISO8601 or a format Carbon understands.';
        }
    }
}
