<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Modules\Security\Contracts\GeoBlockingProviderInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityGeoRule;

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

        foreach ($countries as $countryCode) {
            $cc = strtoupper(trim((string) $countryCode));
            if ($cc === '') {
                continue;
            }

            $v4Cache = "{$this->cacheDir}/{$cc}.v4.zone";
            $v6Cache = "{$this->cacheDir}/{$cc}.v6.zone";

            $needsDownload = $force || ! file_exists($v4Cache) || ! file_exists($v6Cache);

            if ($needsDownload) {
                $ranges = $this->provider->fetchCountryRanges($cc);

                if (empty($ranges['v4']) && empty($ranges['v6'])) {
                    // Fail-open check: if we have existing cached zones, use them rather than failing completely
                    if (file_exists($v4Cache) || file_exists($v6Cache)) {
                        Log::warning("Could not download fresh zones for {$cc}; using existing cached zones.");
                    } else {
                        $errors[] = "Failed to download IP zones for country: {$cc}";

                        continue;
                    }
                } else {
                    // Zone cache writes must succeed: a silently missing cache
                    // file would shrink the allow-set on the next whitelist sync.
                    if (@file_put_contents($v4Cache, implode("\n", $ranges['v4'])."\n") === false
                        || @file_put_contents($v6Cache, implode("\n", $ranges['v6'])."\n") === false) {
                        $errors[] = "Failed to write zone cache for country: {$cc}";

                        continue;
                    }
                }
            }

            if (file_exists($v4Cache)) {
                $lines = file($v4Cache, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $entry = trim($line);
                    if ($entry !== '') {
                        $allV4[] = $entry;
                    }
                }
            }

            if (file_exists($v6Cache)) {
                $lines = file($v6Cache, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($lines as $line) {
                    $entry = trim($line);
                    if ($entry !== '') {
                        $allV6[] = $entry;
                    }
                }
            }
        }

        $allV4 = array_values(array_unique($allV4));
        $allV6 = array_values(array_unique($allV6));

        // Whitelist mode must never compile a partial allow-set: every
        // country that yielded no entries would be silently dropped at
        // the perimeter while the UI reports success.
        $whitelistIncomplete = $rule->mode === 'whitelist' && ! empty($errors) && ! empty($countries);

        if ($whitelistIncomplete || (! empty($errors) && empty($allV4) && empty($allV6) && ! empty($countries))) {
            $rule->last_status = 'failed';
            $rule->last_error = implode('; ', $errors);
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
        $this->compileSetElementFile($rule, $allV4, $allV6);

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
        $this->compileSetElementFile($rule, [], []);
        $this->executor->updateExternalBlocklist();
    }

    /**
     * Write the pending set element file for geo sets.
     * Note: We populate geo_ips and geo_ips6 in the canonical external_blocklist.nft.pending
     * (or load it together with external blocklist) so both share the atomic transaction.
     */
    public function compileSetElementFile(SecurityGeoRule $rule, array $v4, array $v6): void
    {
        if (! is_dir($this->firewallDir)) {
            @mkdir($this->firewallDir, 0750, true);
        }

        $targetFile = $this->firewallDir.'/geo_blocking.nft.pending';

        $setNameV4 = ($rule->mode === 'whitelist') ? 'geo_allowed_ips' : 'geo_blocked_ips';
        $setNameV6 = ($rule->mode === 'whitelist') ? 'geo_allowed_ips6' : 'geo_blocked_ips6';

        $lines = [
            '# TallPBX Geo-Blocking kernel elements — generated '.now()->toIso8601String().'; do not edit.',
            'flush set inet tallpbx_filter geo_blocked_ips',
            'flush set inet tallpbx_filter geo_blocked_ips6',
            'flush set inet tallpbx_filter geo_allowed_ips',
            'flush set inet tallpbx_filter geo_allowed_ips6',
        ];

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
        // half-written geo block.
        $tmpFile = $targetFile.'.tmp';
        file_put_contents($tmpFile, implode("\n", $lines)."\n");
        rename($tmpFile, $targetFile);

        // Also merge into external_blocklist.nft.pending if it exists or create alongside
        $this->mergeWithExternalBlocklistPending($targetFile);
    }

    /**
     * Merges geo lines into external_blocklist.nft.pending so updateExternalBlocklist applies both atomically.
     *
     * The merge runs under an exclusive flock: the hourly (root, scheduler)
     * threat-feed sync rewrites this same file through
     * ThreatFeedIngestionService, and neither writer may lose the other's
     * elements through an interleaved read-modify-write.
     */
    protected function mergeWithExternalBlocklistPending(string $geoPendingFile): void
    {
        $externalPending = $this->firewallDir.'/external_blocklist.nft.pending';
        $externalActive = $this->firewallDir.'/external_blocklist.nft';

        $existingContent = '';
        if (file_exists($externalPending)) {
            $existingContent = file_get_contents($externalPending) ?: '';
        } elseif (file_exists($externalActive)) {
            $existingContent = file_get_contents($externalActive) ?: '';
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

        if (trim(implode("", $filteredLines)) === '') {
            $filteredLines = [
                'flush set inet tallpbx_filter external_blocklist_ips',
                'flush set inet tallpbx_filter external_blocklist_ips6',
            ];
        }

        $geoContent = file_get_contents($geoPendingFile) ?: '';

        $combined = implode("\n", array_filter($filteredLines))."\n".$geoContent;

        // Write the merged result back under the exclusive lock protocol
        // shared with ThreatFeedIngestionService (fopen 'c+' + LOCK_EX +
        // ftruncate/rewrite), falling back to a plain write only when the
        // file cannot be opened at all.
        $handle = fopen($externalPending, 'c+');
        if ($handle === false) {
            file_put_contents($externalPending, $combined);

            return;
        }

        try {
            flock($handle, LOCK_EX);
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, $combined);
            fflush($handle);
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
