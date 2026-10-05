<?php

declare(strict_types=1);

namespace Modules\Certificates\Services;

use Illuminate\Support\Facades\Log;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Executes bounded host certificate operations via /usr/local/sbin/tallpbx-certificate.
 *
 * Uses Symfony Process to safely invoke the sudoers-bounded root helper script,
 * enforcing atomic deployments, cryptographic key validation, and service reloads.
 */
class CertificateExecutor implements CertificateExecutorInterface
{
    /**
     * Absolute filesystem path to the bounded root helper script.
     */
    private string $helperPath;

    /**
     * Create the certificate executor instance.
     *
     * @param  string|null  $helperPath  Optional custom path override for testing
     */
    public function __construct(?string $helperPath = null)
    {
        $this->helperPath = $helperPath ?? '/usr/local/sbin/tallpbx-certificate';
    }

    /**
     * Get the helper capability version.
     */
    public function version(): string
    {
        $result = $this->runCommand(['version']);

        return trim($result['output']);
    }

    /**
     * Issue a Let's Encrypt certificate via HTTP-01 challenge.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function issueLetsEncryptHttp(string $domain, string $email, bool $staging = false): array
    {
        return $this->runCommand([
            'issue-letsencrypt-http',
            $domain,
            $email,
            $staging ? 'true' : 'false',
        ], 180);
    }

    /**
     * Issue a Let's Encrypt certificate via Cloudflare DNS-01 challenge.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function issueLetsEncryptDns(string $domain, string $email, string $credsPath, bool $wildcard = false, bool $staging = false): array
    {
        return $this->runCommand([
            'issue-letsencrypt-dns',
            $domain,
            $email,
            $credsPath,
            $wildcard ? 'true' : 'false',
            $staging ? 'true' : 'false',
        ], 240);
    }

    /**
     * Renew an existing Let's Encrypt certificate.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function renewLetsEncrypt(string $domain): array
    {
        return $this->runCommand(['renew-letsencrypt', $domain], 180);
    }

    /**
     * Import a custom PEM certificate, private key, and optional intermediate chain.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function importCustom(string $storageId, string $pendingCert, string $pendingKey, ?string $pendingChain = null): array
    {
        $args = ['import-custom', $storageId, $pendingCert, $pendingKey];
        if ($pendingChain !== null && $pendingChain !== '') {
            $args[] = $pendingChain;
        }

        return $this->runCommand($args);
    }

    /**
     * Generate a self-signed certificate with optional Subject Alternative Names.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function generateSelfSigned(string $storageId, string $commonName, int $days, string $sanCsv = ''): array
    {
        return $this->runCommand([
            'generate-self-signed',
            $storageId,
            $commonName,
            (string) $days,
            $sanCsv,
        ]);
    }

    /**
     * Atomically deploy a certificate to Nginx Web with rollback on syntax failure.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function deployWeb(string $storageId): array
    {
        return $this->runCommand(['deploy-web', $storageId]);
    }

    /**
     * Deploy a certificate to FreeSWITCH SIP TLS and WebRTC WSS, reloading Sofia profiles.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function deployTelephony(string $storageId): array
    {
        return $this->runCommand(['deploy-telephony', $storageId]);
    }

    /**
     * Deploy a certificate to both Web and Telephony services.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function deployAll(string $storageId): array
    {
        return $this->runCommand(['deploy-all', $storageId]);
    }

    /**
     * Delete an inactive certificate from disk.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function delete(string $storageId): array
    {
        return $this->runCommand(['delete', $storageId]);
    }

    /**
     * Query active Web and Telephony certificate status from disk.
     *
     * @return array{active_web: string, telephony_active: bool}
     */
    public function status(): array
    {
        $default = ['active_web' => '', 'telephony_active' => false];

        if ($this->blockedByTestGuard() || ! file_exists($this->helperPath)) {
            return $default;
        }

        $result = $this->runCommand(['status']);
        if (! $result['success']) {
            return $default;
        }

        try {
            $parsed = json_decode($result['output'], true, 512, JSON_THROW_ON_ERROR);

            return [
                'active_web' => (string) ($parsed['active_web'] ?? ''),
                'telephony_active' => (bool) ($parsed['telephony_active'] ?? false),
            ];
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Whether the test-run guard must block execution of this helper.
     *
     * Only the installed system helper (/usr/local/sbin/...) is blocked during
     * automated tests. Custom test stub scripts remain executable so tests can
     * verify behavior safely.
     */
    private function blockedByTestGuard(): bool
    {
        return app()->runningUnitTests() && str_starts_with($this->helperPath, '/usr/local/sbin/');
    }

    /**
     * Execute a command via the bounded helper script.
     *
     * @param  array<int, string>  $arguments
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    protected function runCommand(array $arguments, int $timeout = 60): array
    {
        if ($this->blockedByTestGuard()) {
            Log::info('Blocked privileged certificate helper execution during test run', [
                'arguments' => $arguments,
            ]);

            return [
                'success' => true,
                'output' => 'MOCKED_TEST_OUTPUT',
                'error' => '',
                'exit_code' => 0,
            ];
        }

        if (! file_exists($this->helperPath)) {
            $msg = "Certificate helper script not found at {$this->helperPath}";
            Log::warning($msg);

            return [
                'success' => false,
                'output' => '',
                'error' => $msg,
                'exit_code' => 127,
            ];
        }

        try {
            $process = $this->createProcess($arguments);
            $process->setTimeout($timeout);
            $process->run();

            return [
                'success' => $process->isSuccessful(),
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput(),
                'exit_code' => (int) $process->getExitCode(),
            ];
        } catch (Throwable $e) {
            Log::error('Certificate helper process exception', [
                'arguments' => $arguments,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'output' => '',
                'error' => $e->getMessage(),
                'exit_code' => 1,
            ];
        }
    }

    /**
     * Create a Symfony Process instance for the helper command.
     *
     * @param  array<int, string>  $arguments
     */
    public function createProcess(array $arguments): Process
    {
        $isSystemHelper = str_starts_with($this->helperPath, '/usr/local/sbin/');
        $prefix = (! $isSystemHelper || (function_exists('posix_geteuid') && posix_geteuid() === 0))
            ? [$this->helperPath]
            : ['sudo', '-n', $this->helperPath];

        return new Process(array_merge($prefix, $arguments));
    }
}
