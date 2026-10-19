<?php

declare(strict_types=1);

namespace Modules\Security\Services\Geo;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Modules\Security\Contracts\GeoBlockingProviderInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Support\AddressFamily;

/**
 * Service managing Geo-Blocking rules, country zone caching,
 * and compilation into nftables elements.
 */
class GeoBlockingService
{
    private string $firewallDir;

    private string $cacheDir;

    /**
     * Create a new GeoBlockingService instance.
     */
    public function __construct(
        private readonly GeoBlockingProviderInterface $provider,
        private readonly SecurityExecutorInterface $executor,
        ?string $firewallDir = null,
        ?string $cacheDir = null,
    ) {
        $this->firewallDir = rtrim($firewallDir ?? '/etc/tallpbx', '/');
        $this->cacheDir = rtrim($cacheDir ?? '/etc/tallpbx/geoip', '/');
    }

    /**
     * Synchronize and compile country zones for the current geo rule.
     *
     * @return array{status: string, v4_count: int, v6_count: int, error: ?string}
     */
    public function sync(?SecurityGeoRule $rule = null, bool $force = false): array
    {
        $rule = $rule ?? SecurityGeoRule::instance();

        $countries = $rule->countries ?? [];
        if (! is_array($countries)) {
            $countries = [];
        }

        if (! is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0750, true);
        }

        $allV4 = [];
        $allV6 = [];
        $errors = [];
        $cacheWriteFailed = false;

        $ttlSeconds = $rule->syncIntervalSeconds();
        if ($ttlSeconds === PHP_INT_MAX) {
            $ttlSeconds = 7 * 86400; // default 7 days TTL for cache freshness on manual/on_demand triggers
        }

        foreach ($countries as $countryCode) {
            $cc = strtoupper(trim((string) $countryCode));
            if ($cc === '') {
                continue;
            }

            $v4Cache = "{$this->cacheDir}/{$cc}.v4.zone";
            $v6Cache = "{$this->cacheDir}/{$cc}.v6.zone";

            $v4NeedsDownload = $force || $this->cacheNeedsDownload($v4Cache, $ttlSeconds);
            $v6NeedsDownload = $force || $this->cacheNeedsDownload($v6Cache, $ttlSeconds);

            if ($v4NeedsDownload || $v6NeedsDownload) {
                $ranges = $this->provider->fetchCountryRanges($cc);

                $fetchedV4 = ! empty($ranges['v4']);
                $fetchedV6 = ! empty($ranges['v6']);

                if (! $fetchedV4 && ! $fetchedV6) {
                    // Both families failed to fetch. Check if usable non-empty cache exists.
                    if (! $this->cacheNeedsDownload($v4Cache) || ! $this->cacheNeedsDownload($v6Cache)) {
                        Log::warning("Could not download fresh zones for {$cc}; using existing cached zones.");
                    } else {
                        $errors[] = "Failed to download IP zones for country: {$cc}";

                        continue;
                    }
                } else {
                    // Write only families that fetched successfully: never overwrite a valid cache
                    // with an empty string or empty line when one family fails.
                    if ($fetchedV4) {
                        if (@file_put_contents($v4Cache, implode("\n", $ranges['v4'])."\n") === false) {
                            $errors[] = "Failed to write zone cache for country: {$cc}";
                            $cacheWriteFailed = true;
                        }
                    }

                    if ($fetchedV6) {
                        if (@file_put_contents($v6Cache, implode("\n", $ranges['v6'])."\n") === false) {
                            $errors[] = "Failed to write zone cache for country: {$cc}";
                            $cacheWriteFailed = true;
                        }
                    }
                }
            }

            $countryHasV4 = false;
            $countryHasV6 = false;

            if (is_file($v4Cache)) {
                $lines = file($v4Cache, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $entry = trim($line);
                    if ($entry !== '' && ! str_starts_with($entry, '#') && AddressFamily::classify($entry) === 'ipv4') {
                        $allV4[] = $entry;
                        $countryHasV4 = true;
                    }
                }
            }

            if (is_file($v6Cache)) {
                $lines = file($v6Cache, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $entry = trim($line);
                    if ($entry !== '' && ! str_starts_with($entry, '#') && AddressFamily::classify($entry) === 'ipv6') {
                        $allV6[] = $entry;
                        $countryHasV6 = true;
                    }
                }
            }

            if ($rule->mode === 'whitelist') {
                if (! $countryHasV4 || ! $countryHasV6) {
                    $missingFamily = ! $countryHasV4 && ! $countryHasV6 ? 'IPv4 and IPv6' : (! $countryHasV4 ? 'IPv4' : 'IPv6');
                    $errors[] = "Incomplete IP zones for country {$cc} in whitelist mode (missing {$missingFamily})";
                }
            } elseif (! $countryHasV4 && ! $countryHasV6) {
                $errors[] = "Failed to download IP zones for country: {$cc}";
            }
        }

        $allV4 = array_values(array_unique($allV4));
        $allV6 = array_values(array_unique($allV6));

        // Whitelist mode must never compile a partial allow-set: every
        // country that yielded an error or missing family would silently drop legitimate users at
        // the perimeter while the UI reports success.
        // Also, cache write failures or total download failures must fail the sync in any mode.
        $hasFatalErrors = ! empty($errors) && ($rule->mode === 'whitelist' || (empty($allV4) && empty($allV6)) || $cacheWriteFailed);

        if ($hasFatalErrors && ! empty($countries)) {
            $rule->last_status = 'failed';
            $rule->last_error = implode('; ', array_unique($errors));
            $rule->save();

            return [
                'status' => 'failed',
                'v4_count' => 0,
                'v6_count' => 0,
                'error' => $rule->last_error,
            ];
        }

        $rule->v4_cidrs_count = count($allV4);
        $rule->v6_cidrs_count = count($allV6);
        $rule->last_sync_at = now();
        $rule->last_status = 'success';
        $rule->last_error = null;
        $rule->save();

        // Compile set elements
        if (! $this->compileSetElementFile($rule, $allV4, $allV6)) {
            $rule->last_status = 'failed';
            $rule->last_error = 'Failed to write geo set elements to disk.';
            $rule->save();

            return [
                'status' => 'failed',
                'v4_count' => count($allV4),
                'v6_count' => count($allV6),
                'error' => $rule->last_error,
            ];
        }

        // Promote set elements via executor
        if (! $this->executor->updateExternalBlocklist()) {
            $rule->last_status = 'failed';
            $rule->last_error = 'Failed to load geo sets into kernel.';
            $rule->save();

            return [
                'status' => 'failed',
                'v4_count' => count($allV4),
                'v6_count' => count($allV6),
                'error' => $rule->last_error,
            ];
        }

        return [
            'status' => 'success',
            'v4_count' => count($allV4),
            'v6_count' => count($allV6),
            'error' => null,
        ];
    }

    /**
     * Clear all compiled geo sets from the kernel.
     */
    public function clear(): void
    {
        $rule = SecurityGeoRule::instance();

        if (! $this->compileSetElementFile($rule, [], [])) {
            Log::error('Geo set clear failed: the empty set-element file could not be written.');
        }

        $this->executor->updateExternalBlocklist();
    }

    /**
     * Whether a zone cache file must be re-downloaded: a missing, zero-byte,
     * unreadable, or expired file cannot contribute ranges and must never pass
     * the fail-closed whitelist guard as if it held valid content.
     */
    public function cacheNeedsDownload(string $path, ?int $ttlSeconds = null): bool
    {
        if (! file_exists($path)) {
            return true;
        }

        $size = @filesize($path);

        if ($size === false || $size === 0) {
            return true;
        }

        if ($ttlSeconds !== null && $ttlSeconds > 0) {
            $mtime = @filemtime($path);
            if ($mtime === false || (time() - $mtime) > $ttlSeconds) {
                return true;
            }
        }

        return false;
    }

    /**
     * Write the pending set element file for geo sets.
     * Note: We populate geo_ips and geo_ips6 in the canonical external_blocklist.nft.pending
     * (or load it together with external blocklist) so both share the atomic transaction.
     *
     * @return bool Whether the pending file was published and merged successfully
     */
    public function compileSetElementFile(SecurityGeoRule $rule, array $v4, array $v6): bool
    {
        if (! is_dir($this->firewallDir)) {
            @mkdir($this->firewallDir, 0750, true);
        }

        $targetFile = $this->firewallDir.'/geo_blocking.nft.pending';

        $setNameV4 = ($rule->mode === 'whitelist') ? 'geo_allowed_ips' : 'geo_blocked_ips';
        $setNameV6 = ($rule->mode === 'whitelist') ? 'geo_allowed_ips6' : 'geo_blocked_ips6';

        $lines = [
            '# TallPBX Geo-Blocking kernel elements — generated '.now()->toIso8601String().'; do not edit.',
        ];

        if (empty($v4) && empty($v6)) {
            // Clearing sets: flush both modes so all kernel geo sets are emptied
            $lines[] = 'flush set inet tallpbx_filter geo_blocked_ips';
            $lines[] = 'flush set inet tallpbx_filter geo_blocked_ips6';
            $lines[] = 'flush set inet tallpbx_filter geo_allowed_ips';
            $lines[] = 'flush set inet tallpbx_filter geo_allowed_ips6';
        } else {
            // Target-mode only flush: never flush the alternate mode's sets
            // during compilation. When switching between whitelist and blacklist,
            // the previous mode's kernel set remains populated until the new chain
            // rule is loaded, preventing an empty allow-set drop window (DM-3).
            $lines[] = "flush set inet tallpbx_filter {$setNameV4}";
            $lines[] = "flush set inet tallpbx_filter {$setNameV6}";
        }

        // Elements are compiled whenever a country set is provided, even
        // while the rule is still disabled: populating the kernel sets
        // BEFORE the perimeter chain is armed is what makes enablement
        // race-free. Sets no chain references are simply unused.
        foreach ($this->chunkedAddElements($setNameV4, $v4) as $line) {
            $lines[] = $line;
        }
        foreach ($this->chunkedAddElements($setNameV6, $v6) as $line) {
            $lines[] = $line;
        }

        // Atomic publish: write to a temp sibling then rename, so the hourly
        // threat-feed sync (which reads this file) never observes a
        // half-written geo block. Both steps are checked: a failed publish
        // must fail the sync instead of reporting success while the helper
        // loads stale elements.
        $tmpFile = $targetFile.'.tmp';
        if (@file_put_contents($tmpFile, implode("\n", $lines)."\n") === false
            || ! @rename($tmpFile, $targetFile)) {
            Log::error('Geo set compile failed: the pending file could not be published.');

            return false;
        }

        // Also merge into external_blocklist.nft.pending if it exists or create alongside
        return $this->mergeWithExternalBlocklistPending($targetFile);
    }

    /**
     * Merges geo lines into external_blocklist.nft.pending so updateExternalBlocklist applies both atomically.
     *
     * The merge runs under an exclusive flock: the hourly (root, scheduler)
     * threat-feed sync rewrites this same file through
     * ThreatFeedIngestionService, and neither writer may lose the other's
     * elements through an interleaved read-modify-write.
     *
     * @return bool Whether the merged content was written successfully
     */
    protected function mergeWithExternalBlocklistPending(string $geoPendingFile): bool
    {
        $externalPending = $this->firewallDir.'/external_blocklist.nft.pending';
        $externalActive = $this->firewallDir.'/external_blocklist.nft';

        $geoContent = file_get_contents($geoPendingFile) ?: '';

        // Write the merged result back under the exclusive lock protocol
        // shared with ThreatFeedIngestionService (fopen 'c+' + LOCK_EX +
        // stream_get_contents + filter + ftruncate/rewrite), falling back
        // to a plain write only when the file cannot be opened at all.
        // The @ suppresses the stream warning for unwritable paths (such
        // as a directory) so the fallback branch handles the failure.
        $handle = @fopen($externalPending, 'c+');
        if ($handle === false) {
            $existingContent = file_exists($externalActive) ? (file_get_contents($externalActive) ?: '') : '';
            $filteredLines = [];
            foreach (explode("\n", $existingContent) as $line) {
                if (str_starts_with($line, '# TallPBX Geo-Blocking kernel elements')) {
                    break;
                }
                $filteredLines[] = $line;
            }
            if (trim(implode('', $filteredLines)) === '') {
                $filteredLines = [
                    'flush set inet tallpbx_filter external_blocklist_ips',
                    'flush set inet tallpbx_filter external_blocklist_ips6',
                ];
            }
            $combined = implode("\n", array_filter($filteredLines))."\n".$geoContent;

            // The plain-write fallback is checked too: a silent failure here
            // would leave the old elements in place while callers report success.
            if (@file_put_contents($externalPending, $combined) === false) {
                Log::error('Geo set merge failed: the shared pending file is not writable.');

                return false;
            }

            return true;
        }

        try {
            flock($handle, LOCK_EX);

            $existingContent = stream_get_contents($handle);
            if ($existingContent === false || trim($existingContent) === '') {
                // If pending is freshly created or empty, seed from active external_blocklist.nft if present
                if (file_exists($externalActive)) {
                    $existingContent = file_get_contents($externalActive) ?: '';
                } else {
                    $existingContent = '';
                }
            }

            // Cut everything from the geo header marker onward: the geo block
            // is always the trailing section, so this removes any previously
            // merged geo content together with its header line in one stroke.
            // (Filtering by set names alone let stale header comments survive
            // every re-merge and accumulate into this root-executed file.)
            $filteredLines = [];
            foreach (explode("\n", $existingContent) as $line) {
                if (str_starts_with($line, '# TallPBX Geo-Blocking kernel elements')) {
                    break;
                }
                $filteredLines[] = $line;
            }

            if (trim(implode('', $filteredLines)) === '') {
                $filteredLines = [
                    'flush set inet tallpbx_filter external_blocklist_ips',
                    'flush set inet tallpbx_filter external_blocklist_ips6',
                ];
            }

            $combined = implode("\n", array_filter($filteredLines))."\n".$geoContent;

            ftruncate($handle, 0);
            rewind($handle);
            $written = fwrite($handle, $combined);
            $flushed = fflush($handle);

            if ($written === false || $flushed === false) {
                Log::error('Geo set merge failed: the shared pending file write failed.');

                return false;
            }

            return true;
        } finally {
            fclose($handle); // closing the handle releases the lock
        }
    }

    /**
     * Chunk entries into add-element lines of at most 256 items.
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
