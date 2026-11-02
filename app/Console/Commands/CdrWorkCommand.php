<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cdr\CdrRedisIngest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Digests buffered call detail records from the Redis stream into MariaDB.
 *
 * Runs continuously under its systemd unit, or once with --once for manual
 * runs and health checks. Every data guarantee lives in the digest cycle;
 * this command only owns the loop, the idle pause, the memory ceiling, the
 * SIGTERM shutdown, and the backlog warning.
 */
class CdrWorkCommand extends Command
{
    protected $signature = 'cdr:work
        {--chunk=100 : Stream entries per digest cycle}
        {--sleep=250000 : Idle pause between cycles, in microseconds}
        {--memory=512 : Stop after exceeding this memory usage, in megabytes}
        {--once : Digest a single batch and exit}
        {--warn-pending=1000 : Warn when more stream entries than this remain, 0 disables}';

    protected $description = 'Digest buffered call detail records from the Redis stream into MariaDB';

    /**
     * Run the digest loop.
     */
    public function handle(CdrRedisIngest $ingest): int
    {
        $chunk = max(1, (int) $this->option('chunk'));
        $sleep = max(0, (int) $this->option('sleep'));
        $memoryLimit = max(64, (int) $this->option('memory'));
        $warnPending = (int) $this->option('warn-pending');
        $once = (bool) $this->option('once');
        $stop = false;
        $lastBacklogCheck = 0;

        // systemd stops the worker with SIGTERM; finish the current cycle
        // first so a shutdown can never cut a batch in half.
        if (function_exists('pcntl_async_signals') && defined('SIGTERM')) {
            pcntl_async_signals(true);

            foreach ([SIGTERM, SIGINT] as $signal) {
                pcntl_signal($signal, function () use (&$stop): void {
                    $stop = true;
                });
            }
        }

        while (! $stop) {
            try {
                $processed = $ingest->digest($chunk);
            } catch (Throwable $exception) {
                // The digest cycle keeps every entry when it fails, so
                // logging and pausing loses nothing.
                $this->error('CDR digest cycle failed: '.$exception->getMessage());

                if ($once) {
                    return self::FAILURE;
                }

                usleep($sleep);

                continue;
            }

            if ($once) {
                $this->info("Digested {$processed} call record(s).");
                $this->warnWhenBacklogged($ingest, $warnPending);

                return self::SUCCESS;
            }

            if ($processed === 0) {
                // Check the backlog at most once a minute while idle so the
                // journal stays readable.
                if (time() - $lastBacklogCheck >= 60) {
                    $lastBacklogCheck = time();
                    $this->warnWhenBacklogged($ingest, $warnPending);
                }

                usleep($sleep);
            } else {
                // Cooperative yield while draining sustained load.
                usleep(1000);
            }

            if (memory_get_usage(true) / 1024 / 1024 >= $memoryLimit) {
                $this->warn('Memory limit reached; cycling worker.');

                return self::SUCCESS;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Surface a growing stream backlog before a reboot puts it at risk.
     */
    private function warnWhenBacklogged(CdrRedisIngest $ingest, int $threshold): void
    {
        if ($threshold <= 0) {
            return;
        }

        try {
            $pending = $ingest->getPendingCount();
        } catch (Throwable) {
            // The backlog hint is best-effort; Redis availability is the
            // ingestion endpoint's problem to report.
            return;
        }

        if ($pending > $threshold) {
            Log::warning('CDR stream backlog is growing.', ['pending' => $pending, 'threshold' => $threshold]);
            $this->warn("CDR stream backlog: {$pending} entries awaiting digestion.");
        }
    }
}
