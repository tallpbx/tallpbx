<?php

declare(strict_types=1);

namespace Modules\Security\Services;

use Illuminate\Support\Facades\Log;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Symfony\Component\Process\Process;

/**
 * Executes bounded host security operations via /usr/local/sbin/tallpbx-security.
 *
 * Uses Symfony Process to safely invoke the sudoers-bounded root helper script,
 * applying atomic nftables rulesets and synchronizing kernel dynamic banned sets.
 */
class SecurityExecutor implements SecurityExecutorInterface
{
    /**
     * Absolute filesystem path to the bounded root helper script.
     */
    private string $helperPath;

    /**
     * Create the security executor instance.
     *
     * @param  string|null  $helperPath  Optional custom path override for testing
     */
    public function __construct(?string $helperPath = null)
    {
        $this->helperPath = $helperPath ?? '/usr/local/sbin/tallpbx-security';
    }

    /**
     * Add an IP address to the dynamic kernel banned_ips set with a timeout.
     *
     * @param  string  $ip  IPv4 or IPv6 address to ban
     * @param  int  $durationSeconds  Timeout in seconds (defaults to 3600 if <= 0)
     */
    public function ban(string $ip, int $durationSeconds = 3600): bool
    {
        $seconds = $durationSeconds > 0 ? $durationSeconds : 3600;

        return $this->runCommand(['ban', $ip, (string) $seconds]);
    }

    /**
     * Remove an IP address from the dynamic kernel banned_ips set.
     *
     * @param  string  $ip  IPv4 or IPv6 address to unban
     */
    public function unban(string $ip): bool
    {
        return $this->runCommand(['unban', $ip]);
    }

    /**
     * Sever an address's live kernel sessions by flushing its conntrack entries.
     *
     * The blocklists evaluate only new flows (they sit behind the stateful
     * fast path), so an address that just entered a blocklist keeps its
     * established calls and connections until these entries are deleted.
     *
     * @param  string  $ip  IPv4 or IPv6 address whose sessions must be severed
     */
    public function flushConntrack(string $ip): bool
    {
        return $this->runCommand(['flush-conntrack', $ip]);
    }

    /**
     * Capability version of the helper that introduced threat feed updates.
     */
    private const REQUIRED_HELPER_VERSION = 2;

    /**
     * Promote and load the pending threat feed set-element file.
     *
     * Existing installs may still carry the previous helper build, which
     * cannot touch the feed sets — the refusal is a plain-language log line
     * telling the operator to re-run the installer's security step, instead
     * of the helper's opaque usage error.
     */
    public function updateThreatFeed(): bool
    {
        $version = $this->helperVersion();

        if ($version === null || $version < self::REQUIRED_HELPER_VERSION) {
            Log::warning(
                'The security helper on this server is out of date (version '.($version ?? 'unknown')
                .'); re-run the installer\'s security step to enable threat feed updates.'
            );

            return false;
        }

        return $this->runCommand(['update-threat-feed']);
    }

    /**
     * Read the helper's self-reported capability version.
     *
     * Returns null when the helper is missing, cannot execute (automated
     * test runs never touch the privileged helper), or does not understand
     * the 'version' action — all of which mean "too old" for every
     * capability-gated action.
     */
    private function helperVersion(): ?int
    {
        // Same safety contract as runCommand: never execute the privileged
        // helper during automated test runs.
        if (app()->runningUnitTests() || ! file_exists($this->helperPath)) {
            return null;
        }

        $process = $this->createProcess(['version']);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        if (preg_match('/tallpbx-helper-version:\s*(\d+)/', $process->getOutput(), $matches) === 1) {
            return (int) $matches[1];
        }

        return null;
    }

    /**
     * Atomically validate and apply the pending nftables ruleset.
     */
    public function apply(): bool
    {
        return $this->runCommand(['apply']);
    }

    /**
     * Query the active nftables ruleset status.
     */
    public function status(): string
    {
        if (! file_exists($this->helperPath)) {
            return '';
        }

        $process = $this->createProcess(['status']);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : '';
    }

    /**
     * Query active dynamic kernel ban sets in structured format.
     *
     * @return array<string, array{ip: string, timeout: int, expires: int, family: string}> Keyed by IP address
     */
    public function bans(): array
    {
        if (! file_exists($this->helperPath)) {
            return [];
        }

        $process = $this->createProcess(['bans']);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $output = trim($process->getOutput());
        if ($output === '') {
            return [];
        }

        try {
            $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        $result = [];
        $objects = $data['nftables'] ?? [];

        foreach ($objects as $obj) {
            if (! isset($obj['set'])) {
                continue;
            }

            $set = $obj['set'];
            $setName = $set['name'] ?? '';
            if (! in_array($setName, ['banned_ips', 'banned_ips6'], true)) {
                continue;
            }

            $family = ($setName === 'banned_ips6' || ($set['type'] ?? '') === 'ipv6_addr') ? 'ipv6' : 'ipv4';
            $elements = $set['elem'] ?? [];

            foreach ($elements as $elemObj) {
                if (isset($elemObj['elem']) && is_array($elemObj['elem'])) {
                    $val = (string) ($elemObj['elem']['val'] ?? '');
                    $timeout = (int) ($elemObj['elem']['timeout'] ?? 0);
                    $expires = (int) ($elemObj['elem']['expires'] ?? 0);
                } elseif (is_string($elemObj)) {
                    $val = $elemObj;
                    $timeout = 0;
                    $expires = 0;
                } else {
                    continue;
                }

                if ($val !== '') {
                    $result[$val] = [
                        'ip' => $val,
                        'timeout' => $timeout,
                        'expires' => $expires,
                        'family' => $family,
                    ];
                }
            }
        }

        return $result;
    }

    /**
     * Execute a helper command and return true if successful.
     *
     * @param  array<int, string>  $arguments  Command arguments to pass to the helper
     */
    protected function runCommand(array $arguments): bool
    {
        // Safety: never execute the privileged helper from an automated test run
        // (unit, feature, or Pest browser tests). On a host where the helper is
        // installed — or where tests run as root — a test run would otherwise
        // rewrite /etc/tallpbx and load test fixtures into the live kernel
        // firewall. Security tests bind a fake SecurityExecutorInterface
        // whenever they exercise these code paths, and browser-test HTTP
        // requests are served in-process by the same test binary, so this
        // guard covers them too.
        if (app()->runningUnitTests()) {
            Log::warning('Blocked privileged security helper execution during a test run', [
                'arguments' => $arguments,
            ]);

            return false;
        }

        if (! file_exists($this->helperPath)) {
            Log::warning("Security helper script not found at {$this->helperPath}");

            return false;
        }

        $process = $this->createProcess($arguments);
        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('Security helper execution failed', [
                'arguments' => $arguments,
                'exit_code' => $process->getExitCode(),
                'error' => $process->getErrorOutput(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Create a Symfony Process instance for the helper command.
     *
     * @param  array<int, string>  $arguments  Command arguments
     */
    public function createProcess(array $arguments): Process
    {
        // In production, the root-owned helper requires sudo -n for non-root users.
        // Test stub helpers in custom paths are invoked directly by the running user.
        $isSystemHelper = str_starts_with($this->helperPath, '/usr/local/sbin/');
        $prefix = (! $isSystemHelper || (function_exists('posix_geteuid') && posix_geteuid() === 0))
            ? [$this->helperPath]
            : ['sudo', '-n', $this->helperPath];

        return new Process(array_merge($prefix, $arguments));
    }
}
