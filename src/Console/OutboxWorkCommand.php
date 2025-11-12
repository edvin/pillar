<?php
declare(strict_types=1);
// @codeCoverageIgnoreStart

namespace Pillar\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Pillar\Outbox\Worker\TickResult;
use Pillar\Outbox\Worker\WorkerRunner;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Helper\TableCell;
use Symfony\Component\Console\Terminal;

final class OutboxWorkCommand extends Command
{
    protected $signature = 'pillar:outbox:work
        {--no-leasing : Disable partition leasing (single-worker mode)}
        {--once : Run a single tick and exit}
        {--json : Emit one JSON line per tick}
        {--silent : Don\'t print anything (still runs)}
        {--interval-ms=0 : Extra sleep between ticks in milliseconds (default 0)}
        {--window=0 : Aggregate stats over this many seconds in interactive mode}';

    protected $description = 'Process the Pillar transactional outbox.';

    public function handle(WorkerRunner $runner): int
    {
        // Optionally override leasing from CLI
        if ($this->option('no-leasing')) {
            Config::set('pillar.outbox.worker.leasing', false);
        }

        $once = (bool)$this->option('once');
        $silent = (bool)$this->option('silent');
        $jsonMode = (bool)$this->option('json');
        $intervalMs = (int)$this->option('interval-ms');

        $windowSeconds = (int) $this->option('window');
        $acc = [
            'since'        => microtime(true),
            'ticks'        => 0,
            'claimed'      => 0,
            'published'    => 0,
            'failed'       => 0,
            'purged'       => 0,
            'durTotal'     => 0.0,
            'lastOwned'    => 0,
            'lastDesired'  => 0,
            'lastLeased'   => 0,
            'lastReleased' => 0,
            'lastWorkers'  => null,
            'lastBackoff'  => 0,
            'lastHeartbeatAt'=> null,
        ];
        $lastHeartbeatAt = null;

        // rolling error buffer for interactive display
        $errors = [];
        $errorLimit = 5;

        // Choose output style
        $interactive = $this->isInteractiveMode($silent, $jsonMode);

        do {
            $result = $runner->tick();

            // capture recent errors from this tick
            if (!empty($result->lastErrors)) {
                foreach ($result->lastErrors as $err) {
                    $errors[] = $err;
                    if (count($errors) > $errorLimit) {
                        array_shift($errors);
                    }
                }
            }

            // accumulate window stats (interactive only, but cheap)
            $acc['ticks']++;
            $acc['claimed']   += $result->claimed;
            $acc['published'] += $result->published;
            $acc['failed']    += $result->failed;
            $acc['purged']    += $result->purgedStale;
            $acc['durTotal']  += $result->durationMs;
            $acc['lastOwned']    = count($result->ownedPartitions);
            $acc['lastDesired']  = count($result->desiredPartitions);
            $acc['lastLeased']   = count($result->leasedPartitions);
            $acc['lastReleased'] = count($result->releasedPartitions);
            $acc['lastWorkers']  = property_exists($result, 'activeWorkers') ? $result->activeWorkers : $acc['lastWorkers'];
            $acc['lastBackoff']  = $result->backoffMs;
            if ($result->renewedHeartbeat) {
                $lastHeartbeatAt = microtime(true);
            }
            $acc['lastHeartbeatAt'] = $lastHeartbeatAt;

            $elapsed   = microtime(true) - $acc['since'];
            $remaining = $windowSeconds > 0 ? max(0, $windowSeconds - (int)$elapsed) : 0;

            if (!$silent) {
                if ($jsonMode) {
                    $this->line($this->toJsonLine($result));
                } elseif ($interactive) {
                    if ($windowSeconds > 0) {
                        $this->renderInteractiveAggregated($acc, $result, $remaining, $once, $errors);
                    } else {
                        $this->renderInteractive($result, $lastHeartbeatAt, $errors);
                    }
                } else {
                    $this->renderLine($result);
                }
            }

            if ($intervalMs > 0) {
                usleep($intervalMs * 1000);
            }

            if ($windowSeconds > 0 && $elapsed >= $windowSeconds) {
                // reset accumulation window
                $acc['since'] = microtime(true);
                $acc['ticks'] = 0;
                $acc['claimed'] = $acc['published'] = $acc['failed'] = $acc['purged'] = 0;
                $acc['durTotal'] = 0.0;
            }
        } while (!$once);

        return self::SUCCESS;
    }

    private function isInteractiveMode(bool $silent, bool $json): bool
    {
        if ($silent || $json) {
            return false;
        }
        // honor non-TTY environments
        return $this->input->isInteractive();
    }

    private function renderInteractive(TickResult $r, ?float $lastHeartbeatAt = null, array $errors = []): void
    {
        $this->clearScreen();

        $hb = '—';
        if ($lastHeartbeatAt !== null) {
            $delta = microtime(true) - $lastHeartbeatAt;
            $hb = ($delta < 1.0) ? 'now' : sprintf('%.0f s ago', $delta);
        }
        $workers = property_exists($r, 'activeWorkers') ? (string)$r->activeWorkers : '—';

        $termWidth = (new Terminal())->getWidth();

        $summaryRows = [
            ['⏱  Tick duration', sprintf('%.2f ms', $r->durationMs)],
            ['❤️  Heartbeat', $hb],
            ['👥  Workers', $workers],
        ];
        $throughputRows = [
            ['📦  Claimed',   (string)$r->claimed],
            ['✅  Published', (string)$r->published],
            ['❌  Failed',    (string)$r->failed],
            ['🧹  Purged stale', (string)$r->purgedStale],
            ['😴  Backoff',   $r->backoffMs ? $r->backoffMs.' ms' : '—'],
        ];
        $partRows = [
            ['🎯  Target (desired)', (string)count($r->desiredPartitions)],
            ['🔐  Owned (target)',  (string)count($r->ownedPartitions)],
            ['📝  Lease attempts (tick)', (string)count($r->leasedPartitions)],
            ['🔓  Released (tick)', (string)count($r->releasedPartitions)],
        ];

        if ($termWidth >= 140) {
            $this->renderThreeUp('Summary', $summaryRows, 'Throughput', $throughputRows, 'Partitions', $partRows);
        } elseif ($termWidth >= 100) {
            $this->renderTwoUp('Summary', $summaryRows, 'Throughput', $throughputRows);
            $this->renderSection('Partitions', $partRows);
        } else {
            $this->renderSection('Summary', $summaryRows);
            $this->renderSection('Throughput', $throughputRows);
            $this->renderSection('Partitions', $partRows);
        }

        if (!empty($errors)) {
            $this->renderErrorsSection($errors);
        }

        if (!$this->option('once')) {
            $this->line('Press Ctrl+C to stop.');
        }
    }

    private function renderInteractiveAggregated(array $acc, TickResult $r, int $remaining, bool $once, array $errors = []): void
    {
        $this->clearScreen();

        $avgMs = $acc['ticks'] > 0 ? $acc['durTotal'] / $acc['ticks'] : 0.0;
        $hb = '—';
        if (!empty($acc['lastHeartbeatAt'])) {
            $delta = microtime(true) - (float)$acc['lastHeartbeatAt'];
            $hb = ($delta < 1.0) ? 'now' : sprintf('%.0f s ago', $delta);
        }
        $workers = $acc['lastWorkers'] !== null ? (string)$acc['lastWorkers'] : '—';

        $termWidth = (new Terminal())->getWidth();

        $summaryRows = [
            ['⏱  Avg tick (window)', sprintf('%.2f ms', $avgMs)],
            ['❤️  Heartbeat', $hb],
            ['👥  Workers', $workers],
            ['⏳  Next refresh in', $remaining > 0 ? $remaining.' s' : 'now'],
            ['🔁  Ticks (window)', (string)$acc['ticks']],
        ];
        $throughputRows = [
            ['📦  Claimed',   (string)$acc['claimed']],
            ['✅  Published', (string)$acc['published']],
            ['❌  Failed',    (string)$acc['failed']],
            ['🧹  Purged stale', (string)$acc['purged']],
            ['😴  Backoff (last)', $acc['lastBackoff'] ? $acc['lastBackoff'].' ms' : '—'],
        ];
        $partRows = [
            ['🎯  Target (desired)', (string)$acc['lastDesired']],
            ['🔐  Owned (target)',  (string)$acc['lastOwned']],
            ['📝  Lease attempts (tick)', (string)$acc['lastLeased']],
            ['🔓  Released (tick)', (string)$acc['lastReleased']],
        ];

        if ($termWidth >= 140) {
            $this->renderThreeUp('Summary', $summaryRows, 'Throughput Σ', $throughputRows, 'Partitions', $partRows);
        } elseif ($termWidth >= 100) {
            $this->renderTwoUp('Summary', $summaryRows, 'Throughput Σ', $throughputRows);
            $this->renderSection('Partitions', $partRows);
        } else {
            $this->renderSection('Summary', $summaryRows);
            $this->renderSection('Throughput Σ', $throughputRows);
            $this->renderSection('Partitions', $partRows);
        }

        if (!empty($errors)) {
            $this->renderErrorsSection($errors);
        }

        if (!$once) {
            $this->line('Press Ctrl+C to stop.');
        }
    }

    private function renderSection(string $title, array $rows): void
    {
        $this->output->writeln("<options=bold>$title</>");
        $table = new Table($this->output);
        $table->setStyle($this->compactTableStyle());
        $table->setHeaders(['', '']);
        $table->setRows($rows);
        $table->render();
        $this->newLine();
    }

    private function compactTableStyle(): TableStyle
    {
        $style = new TableStyle();
        // Minimal borders to avoid stretching across very wide terminals
        $style
            ->setHorizontalBorderChars('─')
            ->setVerticalBorderChars('│')
            ->setCrossingChars(
                '┼', // cross
                '┌', // top-left
                '┬', // top-mid
                '┐', // top-right
                '┤', // mid-right
                '┘', // bottom-right
                '┴', // bottom-mid
                '└', // bottom-left
                '├', // mid-left
                '├', // top-left-bottom
                '┼', // top-mid-bottom
                '┤'  // top-right-bottom
            );
        return $style;
    }

    /**
     * Render two small tables side-by-side inside one Table (four columns: L label, L val, R label, R val).
     * Falls back to single-column sections when terminal width is narrow.
     */
    private function renderTwoUp(string $leftTitle, array $leftRows, string $rightTitle, array $rightRows): void
    {
        $table = new Table($this->output);
        $table->setStyle($this->compactTableStyle());

        $header = [
            new TableCell("<options=bold>$leftTitle</>", ['colspan' => 2]),
            new TableCell("<options=bold>$rightTitle</>", ['colspan' => 2]),
        ];
        $table->setHeaders($header);

        $rows = max(count($leftRows), count($rightRows));
        for ($i = 0; $i < $rows; $i++) {
            $l = $leftRows[$i]  ?? ['',''];
            $r = $rightRows[$i] ?? ['',''];
            $table->addRow([$l[0], $l[1], $r[0], $r[1]]);
        }

        $table->render();
        $this->newLine();
    }

    /**
     * Render three compact sections side-by-side (six columns: L label, L val, M label, M val, R label, R val).
     */
    private function renderThreeUp(string $leftTitle, array $leftRows, string $midTitle, array $midRows, string $rightTitle, array $rightRows): void
    {
        $table = new Table($this->output);
        $table->setStyle($this->compactTableStyle());

        $header = [
            new TableCell("<options=bold>$leftTitle</>", ['colspan' => 2]),
            new TableCell("<options=bold>$midTitle</>", ['colspan' => 2]),
            new TableCell("<options=bold>$rightTitle</>", ['colspan' => 2]),
        ];
        $table->setHeaders($header);

        $rows = max(count($leftRows), count($midRows), count($rightRows));
        for ($i = 0; $i < $rows; $i++) {
            $l = $leftRows[$i] ?? ['',''];
            $m = $midRows[$i]  ?? ['',''];
            $r = $rightRows[$i]?? ['',''];
            $table->addRow([$l[0], $l[1], $m[0], $m[1], $r[0], $r[1]]);
        }

        $table->render();
        $this->newLine();
    }

    /**
     * Render a small table of recent errors: time, sequence, message.
     * @param list<array{ts:string,msg:string,seq?:int}> $errors
     */
    private function renderErrorsSection(array $errors): void
    {
        $this->output->writeln('<options=bold>Recent errors</>');
        $table = new Table($this->output);
        $table->setStyle($this->compactTableStyle());
        $table->setHeaders(['Time', 'Seq', 'Error']);

        $rows = [];
        foreach ($errors as $e) {
            $ts  = $e['ts'] ?? '';
            $seq = array_key_exists('seq', $e) ? (string)$e['seq'] : '—';
            $msg = $this->truncate((string)($e['msg'] ?? ''), 160);
            $rows[] = [$ts, $seq, $msg];
        }
        $table->setRows($rows);
        $table->render();
        $this->newLine();
    }

    private function renderLine(TickResult $r): void
    {
        $flags = [];
        if ($r->renewedHeartbeat) {
            $flags[] = '♥';
        }
        if ($r->purgedStale) {
            $flags[] = "purged={$r->purgedStale}";
        }
        if ($r->backoffMs) {
            $flags[] = "backoff={$r->backoffMs}ms";
        }

        $workers = property_exists($r, 'activeWorkers') ? " workers={$r->activeWorkers}" : '';
        $this->line(sprintf(
            'claimed=%d published=%d failed=%d desired=%d owned=%d leased=%d released=%d dur=%.2fms%s %s',
            $r->claimed,
            $r->published,
            $r->failed,
            count($r->desiredPartitions),
            count($r->ownedPartitions),
            count($r->leasedPartitions),
            count($r->releasedPartitions),
            $r->durationMs,
            $workers,
            $flags ? implode(' ', $flags) : ''
        ));
    }

    private function toJsonLine(TickResult $r): string
    {
        return json_encode([
            'claimed'             => $r->claimed,
            'published'           => $r->published,
            'failed'              => $r->failed,
            'duration_ms'         => $r->durationMs,
            'backoff_ms'          => $r->backoffMs,
            'renewed_heartbeat'   => $r->renewedHeartbeat,
            'purged_stale'        => $r->purgedStale,
            'active_workers'      => property_exists($r, 'activeWorkers') ? $r->activeWorkers : null,
            'desired_partitions'  => $r->desiredPartitions,
            'owned_partitions'    => $r->ownedPartitions,
            'leased_partitions'   => $r->leasedPartitions,
            'released_partitions' => $r->releasedPartitions,
            'desired_count'       => count($r->desiredPartitions),
            'owned_count'         => count($r->ownedPartitions),
            'leased_count'        => count($r->leasedPartitions),
            'released_count'      => count($r->releasedPartitions),
            'ts'                  => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES);
    }

    private function fmtList(array $xs): string
    {
        if (!$xs) return '—';
        return implode(', ', $xs);
    }

    private function fmtFlat(array $xs): string
    {
        return implode(',', $xs);
    }

    private function truncate(string $s, int $max): string
    {
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : $s;
        }
        return strlen($s) > $max ? substr($s, 0, $max - 1) . '…' : $s;
    }

    private function clearScreen(): void
    {
        // ANSI clear screen & move cursor to 0,0
        $this->output->write("\033[2J\033[;H");
    }
}
// @codeCoverageIgnoreEnd