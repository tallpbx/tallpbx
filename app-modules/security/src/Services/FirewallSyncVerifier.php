<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Support\FirewallSyncStatus;

/**
 * Service that verifies synchronization between MariaDB firewall configuration,
 * the authoritative host apply record (sidecar), and the live Linux kernel.
 */
class FirewallSyncVerifier
{
    /**
     * Directory path where TallPBX firewall configuration and sidecar files are stored.
     */
    private string $firewallDir;

    /**
     * Path to the authoritative applied sidecar file.
     */
    private string $sidecarFile;

    /**
     * Create a new firewall sync verifier instance.
     *
     * @param  SecurityConfigGenerator  $generator  Generator used to compile desired canonical digest
     * @param  SecurityExecutorInterface|null  $executor  Executor used to inspect live kernel ruleset state
     * @param  string|null  $firewallDir  Optional directory override (defaults to /etc/tallpbx)
     */
    public function __construct(
        private readonly SecurityConfigGenerator $generator,
        private readonly ?SecurityExecutorInterface $executor = null,
        ?string $firewallDir = null,
    ) {
        $this->firewallDir = rtrim($firewallDir ?? '/etc/tallpbx', '/');
        $this->sidecarFile = "{$this->firewallDir}/firewall.nft.applied";
    }

    /**
     * Verify firewall sync status across sidecar provenance, desired state, and kernel.
     */
    public function verify(): FirewallSyncStatus
    {
        // 1. Read and parse the applied sidecar record
        if (! file_exists($this->sidecarFile) || ! is_readable($this->sidecarFile)) {
            return FirewallSyncStatus::unknown(['No verified apply record found (sidecar missing).']);
        }

        $sidecarContent = file_get_contents($this->sidecarFile);
        if ($sidecarContent === false) {
            return FirewallSyncStatus::unknown(['Could not read applied sidecar file.']);
        }

        $sidecarData = $this->parseSidecar($sidecarContent);
        if ($sidecarData === null) {
            return FirewallSyncStatus::unknown(['Malformed sidecar record.']);
        }

        $appliedDigest = $sidecarData['digest'];
        $appliedPolicy = $sidecarData['policy'];
        $appliedAt = $sidecarData['applied_at'];

        // 2. Compute canonical digest of desired database configuration
        try {
            $desiredDigest = $this->generator->canonicalDigest();
        } catch (\Throwable $e) {
            return FirewallSyncStatus::unknown(
                issues: ["Failed to compile desired ruleset: {$e->getMessage()}"],
                desiredDigest: null,
                appliedDigest: $appliedDigest,
                appliedPolicy: $appliedPolicy,
                appliedAt: $appliedAt,
            );
        }

        $issues = [];

        // 3. Inspect live kernel state (table presence & input chain policy)
        if ($this->executor !== null) {
            try {
                $statusOutput = $this->executor->status();
            } catch (\Throwable) {
                $statusOutput = '';
            }

            if (trim($statusOutput) !== '') {
                if (! str_contains($statusOutput, 'table inet tallpbx_filter')) {
                    $issues[] = 'Kernel firewall table inet tallpbx_filter is absent.';
                } else {
                    if (preg_match('/type\s+filter\s+hook\s+input[^;]*;\s*policy\s+(drop|accept)\s*;/', $statusOutput, $matches) === 1) {
                        $livePolicy = $matches[1];
                        if ($livePolicy !== $appliedPolicy) {
                            $issues[] = "Live kernel policy ({$livePolicy}) does not match applied policy ({$appliedPolicy}).";
                        }
                    }
                }
            }
        }

        // 4. Compare desired canonical digest against applied sidecar digest
        if ($desiredDigest !== $appliedDigest) {
            $issues[] = 'Desired ruleset configuration differs from applied ruleset.';
        }

        // 5. Evaluate final sync state
        if ($issues !== []) {
            return FirewallSyncStatus::drift(
                issues: $issues,
                desiredDigest: $desiredDigest,
                appliedDigest: $appliedDigest,
                appliedPolicy: $appliedPolicy,
                appliedAt: $appliedAt,
            );
        }

        return FirewallSyncStatus::inSync(
            desiredDigest: $desiredDigest,
            appliedDigest: $appliedDigest,
            appliedPolicy: $appliedPolicy,
            appliedAt: $appliedAt,
        );
    }

    /**
     * Parse key=value sidecar content and validate expected fields.
     *
     * @return array{digest: string, policy: string, applied_at: string}|null
     */
    private function parseSidecar(string $content): ?array
    {
        $data = [];
        $lines = explode("\n", $content);

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (str_contains($line, '=')) {
                [$key, $value] = explode('=', $line, 2);
                $data[trim($key)] = trim($value);
            }
        }

        $digest = $data['digest'] ?? null;
        $policy = $data['policy'] ?? null;
        $appliedAt = $data['applied_at'] ?? null;

        if (
            $digest === null ||
            $policy === null ||
            $appliedAt === null ||
            preg_match('/^sha256:[0-9a-f]{64}$/', $digest) !== 1 ||
            ! in_array($policy, ['drop', 'accept'], true)
        ) {
            return null;
        }

        return [
            'digest' => $digest,
            'policy' => $policy,
            'applied_at' => $appliedAt,
        ];
    }
}
