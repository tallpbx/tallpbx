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
     * Execute a helper command and return true if successful.
     *
     * @param  array<int, string>  $arguments  Command arguments to pass to the helper
     */
    protected function runCommand(array $arguments): bool
    {
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
        // If current process is already root, invoke directly; otherwise invoke with sudo -n
        $prefix = (function_exists('posix_geteuid') && posix_geteuid() === 0)
            ? [$this->helperPath]
            : ['sudo', '-n', $this->helperPath];

        return new Process(array_merge($prefix, $arguments));
    }
}
