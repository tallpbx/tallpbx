<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Cdr\CdrBatchWriter;
use App\Services\Cdr\CdrPayloadParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reconciles spooled call records into MariaDB.
 *
 * When every delivery attempt failed, mod_json_cdr leaves the raw JSON in its
 * error directory. This command reads those files, feeds them through the
 * same parser and batch writer as the stream digest, and removes each file
 * only after its row is committed or its payload is safely quarantined — so
 * an interrupted drain retries instead of losing records.
 */
class CdrDrainSpoolCommand extends Command
{
    protected $signature = 'cdr:drain-spool
        {--spool-dir= : Directory holding failed-delivery files}
        {--quarantine-dir= : Directory for unparseable payloads}
        {--batch=100 : Maximum files per run}
        {--grace=2 : Ignore files younger than this many seconds}';

    protected $description = 'Reconcile spooled call detail records into MariaDB';

    /**
     * Drain one batch of spooled files.
     */
    public function handle(CdrBatchWriter $writer): int
    {
        $spool = (string) ($this->option('spool-dir') ?: config('freeswitch.cdr.spool_dir', '/var/log/freeswitch/json_cdr/failed'));
        $quarantine = (string) ($this->option('quarantine-dir') ?: config('freeswitch.cdr.quarantine_dir', '/var/log/freeswitch/json_cdr/quarantine'));
        $batch = max(1, (int) $this->option('batch'));
        $grace = max(0, (int) $this->option('grace'));

        $files = is_dir($spool) ? (glob($spool.'/*.json') ?: []) : [];

        if ($files === []) {
            $this->info('No spooled records.');

            return self::SUCCESS;
        }

        sort($files);
        $parser = new CdrPayloadParser($quarantine);

        $rows = [];
        $drainable = [];
        $quarantined = 0;
        $skipped = 0;

        foreach ($files as $file) {
            if (count($drainable) >= $batch) {
                break;
            }

            // A fresh file may still be mid-write by mod_json_cdr.
            if (filemtime($file) > time() - $grace) {
                $skipped++;

                continue;
            }

            $contents = (string) file_get_contents($file);
            $name = basename($file);
            $row = $parser->parse($contents);

            if ($row === null) {
                // Keep the file when the quarantine copy failed.
                if ($parser->quarantine($name, $contents)) {
                    if (@unlink($file)) {
                        $quarantined++;
                    }
                } else {
                    Log::warning('CDR spool quarantine failed; file kept.', ['file' => $file]);
                }

                continue;
            }

            $rows[] = $row;
            $drainable[] = $file;
        }

        try {
            // One transaction for the batch: a failure keeps every file so the
            // next run retries the same records.
            $writer->write($rows);
        } catch (Throwable $exception) {
            $this->error('CDR spool drain failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $drained = 0;

        foreach ($drainable as $file) {
            if (@unlink($file)) {
                $drained++;
            } else {
                // The row is committed; the next run re-drains it into an
                // update thanks to the (call_uuid, leg) key.
                Log::warning('CDR spool file could not be removed after commit; it will be re-drained.', ['file' => $file]);
            }
        }

        $this->info("Drained {$drained} spooled record(s); quarantined {$quarantined}.");

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} file(s) that may still be open.");
        }

        return self::SUCCESS;
    }
}
