<?php
// @codeCoverageIgnoreStart

namespace Pillar\Console;

use Carbon\CarbonImmutable;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Pillar\Event\EventReplayer;
use Throwable;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\spin;

final class ReplayEventsCommand extends Command
{
    protected $signature = 'pillar:replay-events
                            {stream_id? : Stream ID to filter by, or "null" for all}
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
        $streamArg = $this->argument('stream_id');
        $eventType    = $this->argument('event_type') ?: null;

        $fromSeq  = $this->option('from-seq') !== null ? (int) $this->option('from-seq') : null;
        $toSeq    = $this->option('to-seq') !== null ? (int) $this->option('to-seq') : null;
        $fromDate = $this->option('from-date') ? (string) $this->option('from-date') : null;
        $toDate   = $this->option('to-date') ? (string) $this->option('to-date') : null;

        $needsWizard = $streamArg === null
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
                    'aggregate'  => 'By stream ID',
                    'event'      => 'By event type (FQCN)',
                    'both'       => 'Stream ID + Event type',
                ],
                default: 'all',
                hint: 'Pick what subset of events to replay.'
            );

            if ($scope === 'aggregate' || $scope === 'both') {
                $streamArg = text(
                    label: 'Stream ID or "null" for all',
                    default: 'null',
                    validate: function (string $v) {
                        if (strtolower($v) === 'null') {
                            return null;
                        }
                        return $v !== '' ? null : 'Please enter a non-empty stream id or "null".';
                    }
                );
            }

            if ($scope === 'event' || $scope === 'both') {
                $eventType = text(
                    label: 'Event class (FQCN, optional)',
                    validate: function (string $v) {
                        if ($v === '') return null;
                        return preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $v)
                            ? null
                            : 'Please enter a valid FQCN or leave empty.';
                    },
                    hint: 'Example: Context\\Document\\Event\\DocumentRenamed'
                ) ?: null;
            }

            if (confirm('Filter by global sequence range?', default: false)) {
                $fromSeq = (int) (text(
                    label: 'From sequence (inclusive, empty to skip)',
                    validate: fn (string $v) => ($v === '' || ctype_digit($v)) ? null : 'Enter a non-negative integer or leave empty.'
                ) ?: 0);
                $toSeqRaw = text(
                    label: 'To sequence (inclusive, empty to skip)',
                    validate: fn (string $v) => ($v === '' || ctype_digit($v)) ? null : 'Enter a non-negative integer or leave empty.'
                );
                $toSeq = $toSeqRaw === '' ? null : (int) $toSeqRaw;
                if ($fromSeq === 0) $fromSeq = null;
            }

            if (confirm('Filter by occurred_at date range?', default: false)) {
                $fromDate = text(
                    label: 'From date/time (ISO8601 or parseable, empty to skip)',
                    validate: fn (string $v) => ($v === '' ? null : $this->validateDate($v))
                ) ?: null;

                $toDate = text(
                    label: 'To date/time (ISO8601 or parseable, empty to skip)',
                    validate: fn (string $v) => ($v === '' ? null : $this->validateDate($v))
                ) ?: null;
            }
        }

        // Normalize stream id: treat as opaque stream name, with "null" as sentinel for global replay.
        $streamId = null;
        if ($streamArg !== null && strtolower((string) $streamArg) !== 'null') {
            $streamId = (string) $streamArg;
        }

        // Normalize dates to UTC CarbonImmutable
        $fromDateUtc = $fromDate ? CarbonImmutable::parse($fromDate)->utc() : null;
        $toDateUtc   = $toDate ? CarbonImmutable::parse($toDate)->utc() : null;

        $scope = match (true) {
            $streamId !== null && $eventType !== null => "stream {$streamId} and event $eventType",
            $streamId !== null => "stream {$streamId}",
            $eventType !== null => "event $eventType",
            default => 'all events',
        };

        $window = [];
        if ($fromSeq !== null) $window[] = "seq>=$fromSeq";
        if ($toSeq !== null) $window[] = "seq<=$toSeq";
        if ($fromDateUtc) $window[] = "date>={$fromDateUtc->format('Y-m-d H:i:s\\Z')}";
        if ($toDateUtc)   $window[] = "date<={$toDateUtc->format('Y-m-d H:i:s\\Z')}";

        $this->info("Replaying $scope" . (count($window) ? ' [' . implode(', ', $window) . ']' : '') . '...');

        $start = microtime(true);

        try {
            spin(
                callback: function () use ($streamId, $eventType, $fromSeq, $toSeq, $fromDateUtc, $toDateUtc) {
                    $this->replayer->replay($streamId, $eventType, $fromSeq, $toSeq, $fromDateUtc, $toDateUtc);
                },
                message: 'Replaying events…'
            );
        } catch (Throwable $e) {
            $this->newLine();
            $this->error('Replay failed: ' . $e->getMessage());
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
            return null;
        } catch (Exception) {
            return 'Unable to parse date/time. Use ISO8601 or a format Carbon understands.';
        }
    }
}
// @codeCoverageIgnoreEnd