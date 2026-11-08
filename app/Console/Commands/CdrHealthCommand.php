<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Reports the three symptoms that mean CDR records are not landing.
 *
 * Every layer of the ingestion path fails soft on purpose: the fast entry
 * re-serves through the full application, mod_json_cdr retries before it
 * spools, and the drain reconciles spooled files later. That design is right,
 * but it hides a broken path behind a normal-looking 200. This command turns
 * the silence into numbers, and any breach makes it exit non-zero so the
 * scheduler records a failure instead of a line nobody reads.
 */
class CdrHealthCommand extends Command
{
    /**
     * Console signature with the paths and thresholds an operator may override.
     *
     * @var string
     */
    protected $signature = 'cdr:health
        {--spool-dir=/var/log/freeswitch/json_cdr/failed : Directory of undelivered posts}
        {--stream=pbx:cdr:stream : Redis stream that buffers accepted posts}
        {--warn-pending=1000 : Pending stream entries that count as a backlog}
        {--nginx-error-log=/var/log/nginx/error.log : nginx error log to scan}
        {--log-lines=2000 : Trailing log lines inspected for failed posts}
        {--window=15 : Minutes of log history that count as recent}';

    /**
     * One-line description used by the console command list.
     *
     * @var string
     */
    protected $description = 'Report CDR records that failed to land';

    /**
     * Evaluate the three signals, print one line each, and fail on any breach.
     *
     * @return int SUCCESS when every signal is clean, FAILURE otherwise
     */
    public function handle(): int
    {
        $spooled = $this->spooledPosts((string) $this->option('spool-dir'));
        $backlog = $this->backlog((string) $this->option('stream'));
        $failed = $this->failedPosts(
            (string) $this->option('nginx-error-log'),
            (int) $this->option('log-lines'),
            (int) $this->option('window')
        );
        $warnPending = (int) $this->option('warn-pending');

        $this->line("spooled_posts={$spooled}");
        $this->line("backlog={$backlog}");
        $this->line("failed_posts={$failed}");

        $healthy = true;

        if ($spooled > 0) {
            $healthy = false;
            $this->warn("{$spooled} record(s) are spooled; mod_json_cdr could not deliver them and tpbx cdr:drain-spool must reconcile them.");
        }

        if ($backlog >= $warnPending) {
            $healthy = false;
            $this->warn("{$backlog} entries are waiting in the stream; the cdr worker is not keeping up.");
        }

        if ($failed > 0) {
            $healthy = false;
            $this->warn("{$failed} post(s) to /api/v1/cdr failed at the web server in the inspected log window.");
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Count files waiting in the failure spool.
     *
     * A missing directory means the module has never spooled anything, which
     * is the healthy case rather than an error.
     *
     * @param  string  $directory  Spool directory from configuration
     */
    private function spooledPosts(string $directory): int
    {
        if (! is_dir($directory)) {
            return 0;
        }

        return count(glob(rtrim($directory, '/').'/*') ?: []);
    }

    /**
     * Read the pending-entry count from the isolated CDR stream.
     *
     * @param  string  $stream  Stream key that buffers accepted posts
     */
    private function backlog(string $stream): int
    {
        return (int) Redis::connection('cdr')->xlen($stream);
    }

    /**
     * Count recent web-server failures for the CDR endpoint.
     *
     * nginx records upstream failures in its error log even though the CDR
     * location has request logging off, so this reads the tail of that file and
     * counts lines that mention the endpoint, a transport failure, and a
     * timestamp inside the window. The log keeps history, and an alert that
     * counted every old line would stay red for days after one bad afternoon.
     *
     * @param  string  $path  nginx error log
     * @param  int  $lines  How many trailing lines to inspect
     * @param  int  $minutes  How far back a failure still counts
     */
    private function failedPosts(string $path, int $lines, int $minutes = 15): int
    {
        if (! is_file($path)) {
            return 0;
        }

        $tail = $this->trailingLines($path, $lines);
        $cutoff = now()->subMinutes(max(1, $minutes));
        $failed = 0;

        foreach ($tail as $line) {
            if (! str_contains($line, '/api/v1/cdr')) {
                continue;
            }

            if (preg_match('/(upstream|FastCGI|timed out|no live upstreams)/i', $line) !== 1) {
                continue;
            }

            // nginx writes "Y/m/d H:i:s" in the host's local time; a line
            // whose stamp cannot be read is ignored rather than counted.
            $at = \DateTimeImmutable::createFromFormat('Y/m/d H:i:s', substr($line, 0, 19));

            if ($at === false || $at < $cutoff) {
                continue;
            }

            $failed++;
        }

        return $failed;
    }

    /**
     * Read the last lines of a file without loading a multi-gigabyte log.
     *
     * A fixed 512 KB window is enough for the line count this command needs
     * and keeps the scheduled run cheap on a busy host.
     *
     * @param  string  $path  File to read
     * @param  int  $lines  How many trailing lines to return
     * @return array<int, string> Trailing lines
     */
    private function trailingLines(string $path, int $lines): array
    {
        $handle = @fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        $size = (int) (fstat($handle)['size'] ?? 0);
        $window = min($size, 512 * 1024);

        if ($window > 0) {
            fseek($handle, -$window, SEEK_END);
        }

        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        $all = preg_split('/\R/', trim($contents)) ?: [];

        return $lines > 0 ? array_slice($all, -$lines) : $all;
    }
}
