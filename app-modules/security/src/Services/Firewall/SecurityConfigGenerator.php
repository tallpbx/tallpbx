<?php

declare(strict_types=1);

namespace Modules\Security\Services\Firewall;

use Illuminate\Support\Facades\Log;
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\Firewall\Compilers\BlocklistCompiler;
use Modules\Security\Services\Firewall\Compilers\GeoPerimeterCompiler;
use Modules\Security\Services\Firewall\Compilers\PreFilterCompiler;
use Modules\Security\Services\Firewall\Compilers\ServiceRulesCompiler;
use Symfony\Component\Process\Process;

/**
 * Lean coordinator service that compiles Linux nftables packet filtering configurations.
 *
 * Coordinates stage-specific compilers (BlocklistCompiler, GeoPerimeterCompiler,
 * PreFilterCompiler, and ServiceRulesCompiler) to assemble deterministic,
 * high-performance native nftables rulesets from MariaDB security state.
 */
class SecurityConfigGenerator
{
    /**
     * The recommended pre-filter evaluation order — the default the Security
     * Center resets to.
     */
    public const DEFAULT_PRE_FILTER_ORDER = PreFilterCompiler::DEFAULT_PRE_FILTER_ORDER;

    /**
     * Directory path where TallPBX firewall configuration files are stored.
     */
    private string $firewallDir = '/etc/tallpbx';

    /**
     * Path to the pending firewall ruleset file.
     */
    private string $pendingFile = '/etc/tallpbx/firewall.nft.pending';

    /**
     * Path to the active firewall ruleset file.
     */
    private string $activeFile = '/etc/tallpbx/firewall.nft';

    protected ?BlocklistCompiler $blocklistCompiler = null;

    protected ?GeoPerimeterCompiler $geoPerimeterCompiler = null;

    protected ?PreFilterCompiler $preFilterCompiler = null;

    protected ?ServiceRulesCompiler $serviceRulesCompiler = null;

    public function getBlocklistCompiler(): BlocklistCompiler
    {
        return $this->blocklistCompiler ??= new BlocklistCompiler;
    }

    public function getGeoPerimeterCompiler(): GeoPerimeterCompiler
    {
        return $this->geoPerimeterCompiler ??= new GeoPerimeterCompiler($this->getBlocklistCompiler());
    }

    public function getPreFilterCompiler(): PreFilterCompiler
    {
        return $this->preFilterCompiler ??= new PreFilterCompiler($this->getBlocklistCompiler());
    }

    public function getServiceRulesCompiler(): ServiceRulesCompiler
    {
        return $this->serviceRulesCompiler ??= new ServiceRulesCompiler($this->getBlocklistCompiler());
    }

    /**
     * Create the configuration generator instance.
     *
     * @param  string|null  $firewallDir  Optional directory override (defaults to /etc/tallpbx)
     */
    public function __construct(
        ?string $firewallDir = null,
        ?BlocklistCompiler $blocklistCompiler = null,
        ?GeoPerimeterCompiler $geoPerimeterCompiler = null,
        ?PreFilterCompiler $preFilterCompiler = null,
        ?ServiceRulesCompiler $serviceRulesCompiler = null,
    ) {
        $this->firewallDir = rtrim($firewallDir ?? '/etc/tallpbx', '/');
        $this->pendingFile = "{$this->firewallDir}/firewall.nft.pending";
        $this->activeFile = "{$this->firewallDir}/firewall.nft";

        $this->blocklistCompiler = $blocklistCompiler;
        $this->geoPerimeterCompiler = $geoPerimeterCompiler;
        $this->preFilterCompiler = $preFilterCompiler;
        $this->serviceRulesCompiler = $serviceRulesCompiler;
    }

    /**
     * Generate the complete nftables ruleset text based on current database state.
     */
    public function generate(): string
    {
        $firewallEnabled = SecuritySetting::getBoolean('firewall_enabled', true);
        $prefilterEnabled = SecuritySetting::getBoolean('prefilter_enabled', true);
        $observeMode = SecuritySetting::getBoolean('firewall_observe_mode', false);
        $tftpDefenseEnabled = SecuritySetting::getBoolean('tftp_defense_enabled', true);
        $tftpLimits = self::tftpLimits();

        $defaultPolicy = strtolower(trim((string) SecuritySetting::get('firewall_default_policy', 'drop')));
        if (! in_array($defaultPolicy, ['drop', 'accept'], true)) {
            $defaultPolicy = 'drop';
        }

        $whitelistEnabled = SecuritySetting::getBoolean('whitelist_enabled', true);
        $blacklistEnabled = SecuritySetting::getBoolean('blacklist_enabled', true);
        $attackProtectionEnabled = SecuritySetting::getBoolean('attack_protection_enabled', true)
            && SecuritySetting::getBoolean('intrusion_detection_enabled', true);

        $lines = [];
        $lines[] = '#!/usr/sbin/nft -f';
        $lines[] = '';
        $lines[] = '# Clear previous ruleset atomically';
        $lines[] = 'flush ruleset';
        $lines[] = '';
        $lines[] = 'table inet tallpbx_filter {';

        // 1. IP Sets: permanent lists, dynamic bans, threat feeds, and geo sets
        foreach ($this->getBlocklistCompiler()->compileSets($blacklistEnabled, $whitelistEnabled, $attackProtectionEnabled) as $line) {
            $lines[] = $line;
        }

        // 2. TFTP flood meters (sets 9/10)
        foreach ($this->getPreFilterCompiler()->compileTftpSets($tftpDefenseEnabled) as $line) {
            $lines[] = $line;
        }

        // 3. Dedicated Geo-Blocking Perimeter Chain
        $geoRule = SecurityGeoRule::instance();
        foreach ($this->getGeoPerimeterCompiler()->compileSubchain($geoRule, $firewallEnabled, $observeMode) as $line) {
            $lines[] = $line;
        }

        // 4. Chain Input
        $chainPolicy = ($firewallEnabled && ! $observeMode) ? $defaultPolicy : 'accept';
        $lines[] = '    chain input {';
        $lines[] = "        type filter hook input priority -10; policy {$chainPolicy};";
        $lines[] = '';

        if (! $firewallEnabled) {
            $lines[] = '        # Firewall is currently disabled - allowing all traffic';
            $lines[] = '        iif "lo" accept';
            $lines[] = '        ct state established,related accept';
            $lines[] = '        accept';
            $lines[] = '    }';
        } else {
            // Stages 1–7: Pre-filter pipeline
            if ($prefilterEnabled) {
                foreach ($this->getPreFilterCompiler()->compileStages($observeMode) as $line) {
                    $lines[] = $line;
                }
            }

            // STAGE 8: ICMP Ping Diagnostics
            foreach ($this->getPreFilterCompiler()->compileIcmpStage() as $line) {
                $lines[] = $line;
            }

            // STAGE 9: Hardened TFTP defense profile
            foreach ($this->getPreFilterCompiler()->compileTftpStage($tftpDefenseEnabled, $observeMode, $tftpLimits) as $line) {
                $lines[] = $line;
            }

            // STAGE 9.5: Geo-Blocking
            foreach ($this->getGeoPerimeterCompiler()->compileInputStage($geoRule) as $line) {
                $lines[] = $line;
            }

            // STAGES 10–12: Port catalog telephony ports, custom rules, default policy
            foreach ($this->getServiceRulesCompiler()->compileStages($observeMode, $defaultPolicy) as $line) {
                $lines[] = $line;
            }

            $lines[] = '    }';
        }

        $lines[] = '';
        $lines[] = '    chain forward {';
        $lines[] = '        type filter hook forward priority filter; policy drop;';
        $lines[] = '    }';
        $lines[] = '';
        $lines[] = '    chain output {';
        $lines[] = '        type filter hook output priority filter; policy accept;';
        $lines[] = '    }';
        $lines[] = '}';
        $lines[] = '';

        // Compute deterministic canonical ruleset digest (excluding dynamic bans)
        // and insert machine-readable provenance comment markers after the shebang.
        $canonical = $this->canonicalForm(implode("\n", $lines));
        $digest = 'sha256:'.hash('sha256', $canonical);

        array_splice($lines, 1, 0, [
            '# tallpbx-policy: '.$chainPolicy,
            '# tallpbx-digest: '.$digest,
        ]);

        return implode("\n", $lines);
    }

    /**
     * Compute the deterministic canonical SHA-256 digest of the desired ruleset.
     */
    public function canonicalDigest(): string
    {
        $ruleset = $this->generate();

        if (preg_match('/^# tallpbx-digest:\s*(sha256:[0-9a-f]{64})$/m', $ruleset, $matches) === 1) {
            return $matches[1];
        }

        return 'sha256:'.hash('sha256', $this->canonicalForm($ruleset));
    }

    /**
     * Convert ruleset text into its normalized canonical representation.
     *
     * 1. Strips blank lines and comment lines (including tallpbx-* markers).
     * 2. Excludes dynamic attacker ban set elements (banned_ips, banned_ips6) to
     *    prevent false-drift flapping when intrusion bans are added or decay in RAM.
     * 3. Normalizes and sorts elements inside permanent sets alphabetically.
     * 4. Trims leading and trailing whitespace on each line.
     */
    public function canonicalForm(string $content): string
    {
        $lines = explode("\n", $content);
        $canonicalLines = [];
        $currentSet = null;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^\s*set\s+([a-zA-Z0-9_]+)\s*\{/', $line, $matches) === 1) {
                $currentSet = $matches[1];
            } elseif ($currentSet !== null && preg_match('/^\s*\}\s*$/', $line) === 1) {
                $currentSet = null;
            }

            if (($currentSet === 'banned_ips' || $currentSet === 'banned_ips6') &&
                str_contains($trimmed, 'elements =')
            ) {
                continue;
            }

            if (preg_match('/^elements\s*=\s*\{\s*(.*?)\s*\}$/', $trimmed, $matches) === 1) {
                $elements = array_filter(
                    array_map('trim', explode(',', $matches[1])),
                    fn (string $item): bool => $item !== ''
                );

                $elements = array_map(
                    fn (string $item): string => (string) preg_replace('/\s+timeout\s+[0-9]+s$/', '', $item),
                    $elements
                );

                sort($elements, SORT_STRING);
                $canonicalLines[] = 'elements = { '.implode(', ', $elements).' }';

                continue;
            }

            $canonicalLines[] = (string) preg_replace('/\s+/', ' ', $trimmed);
        }

        return implode("\n", $canonicalLines);
    }

    /**
     * Write the generated ruleset to the pending file for atomic validation.
     */
    public function writePending(?string $path = null): string
    {
        $target = $path ?? $this->pendingFile;
        $content = $this->generate();

        $dir = dirname($target);
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        file_put_contents($target, $content);
        @chmod($target, 0640);

        return $target;
    }

    /**
     * Write the generated ruleset directly to the active configuration file.
     */
    public function writeActive(?string $path = null): string
    {
        $target = $path ?? $this->activeFile;
        $content = $this->generate();

        $dir = dirname($target);
        if (! is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        file_put_contents($target, $content);
        @chmod($target, 0640);

        return $target;
    }

    /**
     * Validate ruleset syntax using the host nft utility ('nft -c -f').
     */
    public function validateSyntax(string $filePath): bool
    {
        if (! file_exists($filePath)) {
            return false;
        }

        $isPrivileged = ! function_exists('posix_geteuid') || posix_geteuid() === 0;
        if ($isPrivileged || $filePath !== $this->pendingFile) {
            $process = new Process(['/usr/sbin/nft', '-c', '-f', $filePath]);
        } else {
            $process = new Process(['sudo', '-n', '/usr/local/sbin/tallpbx-security', 'validate']);
        }

        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('nftables syntax validation error', [
                'file' => $filePath,
                'error' => $process->getErrorOutput(),
                'exit_code' => $process->getExitCode(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * The administrator-facing TFTP flood limits, clamped to sane bounds.
     *
     * @return array{rate_limit: int, burst: int}
     */
    public static function tftpLimits(): array
    {
        return PreFilterCompiler::tftpLimits();
    }

    /**
     * Resolve the stored pre-filter order, validating it before use.
     *
     * @return array<int, string>
     */
    public function preFilterOrder(): array
    {
        return $this->getPreFilterCompiler()->preFilterOrder();
    }

    /**
     * Assert that a proposed pre-filter order preserves every safety rule.
     *
     * @param  array<int, string>  $order
     */
    public function assertValidPreFilterOrder(array $order): void
    {
        $this->getPreFilterCompiler()->assertValidPreFilterOrder($order);
    }

    /**
     * Format port ranges and multiple ports for nftables syntax.
     */
    public function formatPortRange(string $rawPort): string
    {
        return $this->getServiceRulesCompiler()->formatPortRange($rawPort);
    }

    /**
     * Build the concise nftables match / selector expression for a service or rule.
     */
    public function formatServiceNftablesRule(?string $protocol, ?string $portRaw = null): string
    {
        return $this->getServiceRulesCompiler()->formatServiceNftablesRule($protocol, $portRaw);
    }

    /**
     * Generate nftables rules for a specific protocol and port specification.
     *
     * @return array<int, string> Generated rule lines
     */
    public function generateServiceRules(
        string $protocol,
        string $portFormatted,
        string $action = 'accept',
        string $prefix = '',
    ): array {
        return $this->getServiceRulesCompiler()->generateServiceRules($protocol, $portFormatted, $action, $prefix);
    }
}
