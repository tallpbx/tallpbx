<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Support\AddressFamily;
use Modules\Security\Support\ThreatFeedSyncResult;

/**
 * Streams threat feed downloads to disk and compiles kernel set elements.
 *
 * The service never touches the database rows or the kernel itself: it
 * fetches, validates, and writes the set-element file the bounded helper
 * later loads. Every failure path is fail-open — the previously compiled
 * list stays in place and the caller receives a `failed` result.
 */
class ThreatFeedIngestionService
{
    /**
     * Directory holding the compiled kernel set-element files.
     */
    private string $firewallDir;

    /**
     * Directory for the temporary download files.
     */
    private string $tempDir;

    /**
     * Create the ingestion service instance.
     *
     * @param  string|null  $firewallDir  Optional ruleset directory override (defaults to /etc/tallpbx)
     * @param  string|null  $tempDir  Optional temp directory override (defaults to storage/app/threat-feeds)
     */
    public function __construct(?string $firewallDir = null, ?string $tempDir = null)
    {
        $this->firewallDir = rtrim($firewallDir ?? '/etc/tallpbx', '/');
        $this->tempDir = rtrim($tempDir ?? storage_path('app/threat-feeds'), '/');
    }

    /**
     * Download, validate, and compile one feed refresh.
     *
     * @param  SecurityThreatFeed  $feed  Feed whose cache validators and limits apply
     * @param  string  $url  Fully formed download URL from the provider driver
     * @param  bool  $force  Bypass the HTTP conditional cache (never the fail-open checks)
     */
    public function ingest(SecurityThreatFeed $feed, string $url, bool $force = false): ThreatFeedSyncResult
    {
        if (! is_dir($this->tempDir)) {
            @mkdir($this->tempDir, 0750, true);
        }

        // Never a fixed path under /tmp: it is world-writable (a local
        // symlink/DoS vector) and often a RAM-backed tmpfs that would defeat
        // the flat-memory goal of streaming to disk.
        $tempFile = tempnam($this->tempDir, 'feed-');
        if ($tempFile === false) {
            return ThreatFeedSyncResult::failed('Could not create a temporary download file.');
        }

        try {
            $response = Http::timeout(120)
                // decode_content lets the transport negotiate and decompress
                // gzip transparently while still streaming to the sink.
                ->withOptions(['decode_content' => true])
                ->withHeaders(array_filter([
                    'Accept-Encoding' => 'gzip',
                    'User-Agent' => 'TallPBX-ThreatFeed/1.0',
                    'If-None-Match' => $force ? null : $feed->etag,
                    'If-Modified-Since' => $force ? null : $feed->last_modified_header,
                ]))
                ->sink($tempFile)
                ->get($url);

            if ($response->status() === 304) {
                return ThreatFeedSyncResult::notModified();
            }

            if (! $response->successful()) {
                return ThreatFeedSyncResult::failed("The feed download failed with HTTP {$response->status()}.");
            }

            return $this->parseAndCompile($tempFile, $response);
        } catch (\Throwable $e) {
            return ThreatFeedSyncResult::failed('The feed download failed: '.$e->getMessage());
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Compile an empty set-element file that flushes both feed sets.
     *
     * Used when a feed is switched off: its elements must leave the kernel
     * immediately instead of waiting for the next sync to rewrite the file.
     */
    public function clear(): void
    {
        if (! $this->compileSetElementFile([], [])) {
            Log::error('Threat feed clear failed: the empty set-element file could not be written.');
        }
    }

    /**
     * Parse the downloaded list line by line and compile the set-element file.
     *
     * Streaming line reads keep PHP memory flat regardless of list size;
     * anything below the minimum entry count aborts the sync so a truncated
     * or empty download can never reach the kernel.
     */
    private function parseAndCompile(string $file, Response $response): ThreatFeedSyncResult
    {
        $minEntries = SecuritySetting::getInt('threat_feed_min_entries', 1000);

        $v4 = [];
        $v6 = [];
        $rejected = 0;

        $handle = fopen($file, 'r');
        if ($handle === false) {
            return ThreatFeedSyncResult::failed('The downloaded feed file could not be read.');
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $entry = trim($line);

                // Comment and blank lines carry no address; they are neither
                // compiled nor counted as rejections.
                if ($entry === '' || str_starts_with($entry, '#')) {
                    continue;
                }

                // The shared dual-stack validator accepts bare addresses and
                // CIDR ranges; filter_var() rejects the ranges this feed is
                // made of, so it is deliberately not used here.
                if (! AddressFamily::isValidAddressOrCidr($entry)) {
                    $rejected++;

                    continue;
                }

                if (AddressFamily::classify($entry) === 'ipv6') {
                    $v6[] = $entry;
                } else {
                    $v4[] = $entry;
                }
            }
        } finally {
            fclose($handle);
        }

        $total = count($v4) + count($v6);

        if ($total < $minEntries) {
            return ThreatFeedSyncResult::failed(
                "The downloaded list contains only {$total} valid entries, below the minimum of {$minEntries}; keeping the previous list."
            );
        }

        if (! $this->compileSetElementFile($v4, $v6)) {
            return ThreatFeedSyncResult::failed('The pending set-element file could not be written; keeping the previous list.');
        }

        return ThreatFeedSyncResult::success(
            $total,
            $rejected,
            $response->header('ETag') ?: null,
            $response->header('Last-Modified') ?: null,
        );
    }

    /**
     * Write the pending set-element file for the helper to promote and load.
     *
     * The flush statements and every add-element statement travel in ONE
     * file, which the helper loads as a single nft transaction, so the sets
     * are never observable in a half-updated state.
     *
     * @param  array<int, string>  $v4  Validated IPv4 addresses and ranges
     * @param  array<int, string>  $v6  Validated IPv6 addresses and ranges
     * @return bool Whether the pending file was written successfully
     */
    private function compileSetElementFile(array $v4, array $v6): bool
    {
        $feedLines = [
            '# TallPBX external blocklist kernel elements — generated '.now()->toIso8601String().'; do not edit.',
            'flush set inet tallpbx_filter external_blocklist_ips',
            'flush set inet tallpbx_filter external_blocklist_ips6',
        ];

        foreach ($this->chunkedAddElements('external_blocklist_ips', $v4) as $line) {
            $feedLines[] = $line;
        }

        foreach ($this->chunkedAddElements('external_blocklist_ips6', $v6) as $line) {
            $feedLines[] = $line;
        }

        if (! is_dir($this->firewallDir)) {
            @mkdir($this->firewallDir, 0750, true);
        }

        $pendingFile = $this->firewallDir.'/external_blocklist.nft.pending';
        $geoPending = $this->firewallDir.'/geo_blocking.nft.pending';

        // Write under the exclusive lock protocol shared with
        // GeoBlockingService (fopen 'c+' + LOCK_EX + snapshot geo + ftruncate/rewrite):
        // web-context geo toggles merge into this same file, and neither
        // writer may lose the other's elements through an interleaved
        // read-modify-write.
        $handle = @fopen($pendingFile, 'c+');
        if ($handle === false) {
            $geoContent = '';
            if (is_file($geoPending)) {
                $geoContent = @file_get_contents($geoPending) ?: '';
            } elseif (is_file($pendingFile)) {
                $existing = @file_get_contents($pendingFile) ?: '';
                if (($pos = strpos($existing, '# TallPBX Geo-Blocking kernel elements')) !== false) {
                    $geoContent = substr($existing, $pos);
                }
            }

            $lines = $feedLines;
            if (trim($geoContent) !== '') {
                $lines[] = trim($geoContent);
            }
            $content = implode("\n", $lines)."\n";

            // The plain-write fallback is checked too: a silent failure here
            // would leave the old elements in place while callers report success.
            if (@file_put_contents($pendingFile, $content) === false) {
                Log::error('Threat feed compile failed: the pending blocklist file is not writable.');

                return false;
            }

            return true;
        }

        try {
            flock($handle, LOCK_EX);

            // Snapshot any pending geo elements under the exclusive lock.
            // If geo_blocking.nft.pending exists, use its atomic snapshot;
            // otherwise, preserve any geo elements already merged in the stream.
            $geoContent = '';
            if (is_file($geoPending)) {
                $geoContent = @file_get_contents($geoPending) ?: '';
            } else {
                $existing = stream_get_contents($handle);
                if ($existing !== false && ($pos = strpos($existing, '# TallPBX Geo-Blocking kernel elements')) !== false) {
                    $geoContent = substr($existing, $pos);
                }
            }

            $lines = $feedLines;
            if (trim($geoContent) !== '') {
                $lines[] = trim($geoContent);
            }
            $content = implode("\n", $lines)."\n";

            ftruncate($handle, 0);
            rewind($handle);
            $written = fwrite($handle, $content);
            $flushed = fflush($handle);

            if ($written === false || $flushed === false) {
                Log::error('Threat feed compile failed: the pending blocklist file write failed.');

                return false;
            }

            return true;
        } finally {
            fclose($handle); // closing the handle releases the lock
        }
    }

    /**
     * Chunk a list into add-element statements of at most 256 entries.
     *
     * Bounded line length keeps every statement well inside what the nft
     * lexer accepts, no matter how long an individual address line is.
     *
     * @param  array<int, string>  $entries
     * @return array<int, string>
     */
    private function chunkedAddElements(string $setName, array $entries): array
    {
        $lines = [];

        foreach (array_chunk($entries, 256) as $chunk) {
            $lines[] = "add element inet tallpbx_filter {$setName} { ".implode(', ', $chunk).' }';
        }

        return $lines;
    }
}
