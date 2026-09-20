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
        // (Pest feature tests or Dusk browser tests). On a host where the helper
        // is installed — or where tests run as root — a test run would otherwise
        // rewrite /etc/tallpbx and load test fixtures into the live kernel
        // firewall. Security tests bind a fake SecurityExecutorInterface
        // whenever they exercise these code paths. The DUSK_TESTING flag also
        // covers requests served while `php artisan dusk` has temporarily
        // swapped the Dusk environment in, so a concurrent admin session can
        // never trigger a privileged firewall change mid-test-run.
        if (app()->runningUnitTests() || app()->environment('dusk') || config('app.dusk_testing')) {
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
