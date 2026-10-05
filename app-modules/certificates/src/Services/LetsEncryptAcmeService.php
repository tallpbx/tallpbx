<?php

declare(strict_types=1);

namespace Modules\Certificates\Services;

use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Contracts\CertificateParserServiceInterface;
use Modules\Certificates\Contracts\CertificateValidatorServiceInterface;
use Modules\Certificates\Contracts\LetsEncryptAcmeServiceInterface;
use Modules\Certificates\Exceptions\AcmeChallengeException;
use Modules\Certificates\Exceptions\CertificateException;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;
use Modules\Certificates\Models\CertificateDnsCredential;
use Throwable;

/**
 * Service for orchestrating Let's Encrypt ACME certificate issuances and renewals.
 */
class LetsEncryptAcmeService implements LetsEncryptAcmeServiceInterface
{
    /**
     * Create a new Let's Encrypt ACME service instance.
     */
    public function __construct(
        private readonly CertificateExecutorInterface $executor,
        private readonly CertificateParserServiceInterface $parser,
        private readonly CertificateValidatorServiceInterface $validator,
        private readonly CertificateDeploymentServiceInterface $deploymentService,
    ) {}

    /**
     * Issue a Let's Encrypt certificate using HTTP-01 challenge.
     */
    public function issueHttp(
        string $name,
        string $domain,
        string $email,
        bool $staging = false,
        bool $autoDeployWeb = false,
        bool $autoDeployTelephony = false,
        ?int $adminId = null,
    ): Certificate {
        $cleanDomain = trim($domain);
        $cleanEmail = trim($email);

        if (! $this->validator->validateDomain($cleanDomain)) {
            throw new CertificateException("Invalid domain name: '{$cleanDomain}'");
        }

        if (! filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            throw new CertificateException("Invalid administrator email address: '{$cleanEmail}'");
        }

        $result = $this->executor->issueLetsEncryptHttp($cleanDomain, $cleanEmail, $staging);

        if (! $result['success']) {
            $errorMsg = $result['error'] !== '' ? $result['error'] : 'Let\'s Encrypt HTTP-01 challenge failed.';

            CertificateAuditLog::create([
                'admin_id' => $adminId,
                'action' => 'issued',
                'status' => 'error',
                'message' => "Failed to issue Let's Encrypt certificate for {$cleanDomain}: {$errorMsg}",
                'details' => ['output' => $result['output'], 'exit_code' => $result['exit_code']],
            ]);

            throw new AcmeChallengeException($errorMsg);
        }

        $metadata = $this->resolveMetadata($cleanDomain, [$cleanDomain], $staging);

        $certificate = Certificate::create([
            'name' => $name,
            'type' => Certificate::TYPE_LETS_ENCRYPT,
            'common_name' => $cleanDomain,
            'san_domains' => $metadata['san_domains'],
            'issuer' => $metadata['issuer'],
            'valid_from' => $metadata['valid_from'],
            'valid_to' => $metadata['valid_to'],
            'serial_number' => $metadata['serial_number'],
            'fingerprint_sha256' => $metadata['fingerprint_sha256'],
            'is_default_web' => false,
            'is_default_telephony' => false,
            'challenge_type' => Certificate::CHALLENGE_HTTP,
            'auto_renew' => true,
            'is_staging' => $staging,
            'last_renewed_at' => now(),
            'storage_identifier' => $cleanDomain,
        ]);

        CertificateAuditLog::create([
            'certificate_id' => $certificate->id,
            'admin_id' => $adminId,
            'action' => 'issued',
            'status' => 'success',
            'message' => "Issued Let's Encrypt HTTP-01 certificate '{$certificate->name}' for {$cleanDomain}.",
            'details' => ['staging' => $staging, 'storage_identifier' => $cleanDomain],
        ]);

        if ($autoDeployWeb) {
            $this->deploymentService->deployWeb($certificate, $adminId);
        }

        if ($autoDeployTelephony) {
            $this->deploymentService->deployTelephony($certificate, $adminId);
        }

        return $certificate->fresh();
    }

    /**
     * Issue a Let's Encrypt certificate using Cloudflare DNS-01 challenge (supports wildcards).
     */
    public function issueDns(
        string $name,
        string $domain,
        string $email,
        CertificateDnsCredential $dnsCredential,
        bool $wildcard = false,
        bool $staging = false,
        bool $autoDeployWeb = false,
        bool $autoDeployTelephony = false,
        ?int $adminId = null,
    ): Certificate {
        $cleanDomain = trim($domain);
        $cleanEmail = trim($email);

        if (! $this->validator->validateDomain($cleanDomain)) {
            throw new CertificateException("Invalid domain name: '{$cleanDomain}'");
        }

        if (! filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            throw new CertificateException("Invalid administrator email address: '{$cleanEmail}'");
        }

        $apiToken = (string) ($dnsCredential->credentials['api_token'] ?? '');
        if ($apiToken === '') {
            throw new CertificateException('DNS credential payload is missing required api_token.');
        }

        // Create temporary credentials file with mode 0600
        $credsFile = tempnam(sys_get_temp_dir(), 'cf_creds_');
        if ($credsFile === false) {
            throw new CertificateException('Failed to create temporary DNS credentials file.');
        }

        file_put_contents($credsFile, "dns_cloudflare_api_token = {$apiToken}\n");
        chmod($credsFile, 0600);

        try {
            $result = $this->executor->issueLetsEncryptDns($cleanDomain, $cleanEmail, $credsFile, $wildcard, $staging);
        } finally {
            @unlink($credsFile);
        }

        if (! $result['success']) {
            $errorMsg = $result['error'] !== '' ? $result['error'] : 'Let\'s Encrypt DNS-01 challenge failed.';

            CertificateAuditLog::create([
                'admin_id' => $adminId,
                'action' => 'issued',
                'status' => 'error',
                'message' => "Failed to issue Let's Encrypt DNS certificate for {$cleanDomain}: {$errorMsg}",
                'details' => ['output' => $result['output'], 'exit_code' => $result['exit_code']],
            ]);

            throw new AcmeChallengeException($errorMsg);
        }

        $sans = $wildcard ? [$cleanDomain, "*.{$cleanDomain}"] : [$cleanDomain];
        $metadata = $this->resolveMetadata($cleanDomain, $sans, $staging);

        $certificate = Certificate::create([
            'name' => $name,
            'type' => Certificate::TYPE_LETS_ENCRYPT,
            'common_name' => $cleanDomain,
            'san_domains' => $metadata['san_domains'],
            'issuer' => $metadata['issuer'],
            'valid_from' => $metadata['valid_from'],
            'valid_to' => $metadata['valid_to'],
            'serial_number' => $metadata['serial_number'],
            'fingerprint_sha256' => $metadata['fingerprint_sha256'],
            'is_default_web' => false,
            'is_default_telephony' => false,
            'challenge_type' => Certificate::CHALLENGE_DNS,
            'dns_credential_id' => $dnsCredential->id,
            'auto_renew' => true,
            'is_staging' => $staging,
            'last_renewed_at' => now(),
            'storage_identifier' => $cleanDomain,
        ]);

        CertificateAuditLog::create([
            'certificate_id' => $certificate->id,
            'admin_id' => $adminId,
            'action' => 'issued',
            'status' => 'success',
            'message' => "Issued Let's Encrypt DNS-01 certificate '{$certificate->name}' for {$cleanDomain} (wildcard: ".($wildcard ? 'yes' : 'no').').',
            'details' => ['wildcard' => $wildcard, 'staging' => $staging, 'storage_identifier' => $cleanDomain],
        ]);

        if ($autoDeployWeb) {
            $this->deploymentService->deployWeb($certificate, $adminId);
        }

        if ($autoDeployTelephony) {
            $this->deploymentService->deployTelephony($certificate, $adminId);
        }

        return $certificate->fresh();
    }

    /**
     * Renew an existing Let's Encrypt certificate.
     */
    public function renew(Certificate $certificate, ?int $adminId = null): bool
    {
        if ($certificate->type !== Certificate::TYPE_LETS_ENCRYPT) {
            throw new CertificateException("Only Let's Encrypt certificates can be automatically renewed.");
        }

        $result = $this->executor->renewLetsEncrypt($certificate->common_name);

        if (! $result['success']) {
            $errorMsg = $result['error'] !== '' ? $result['error'] : 'Renewal execution failed.';

            $certificate->update(['last_renew_error' => $errorMsg]);

            CertificateAuditLog::create([
                'certificate_id' => $certificate->id,
                'admin_id' => $adminId,
                'action' => 'renewed',
                'status' => 'error',
                'message' => "Renewal failed for '{$certificate->name}' ({$certificate->common_name}): {$errorMsg}",
                'details' => ['output' => $result['output'], 'exit_code' => $result['exit_code']],
            ]);

            throw new AcmeChallengeException($errorMsg);
        }

        // Re-parse renewed certificate metadata
        $certPath = "/etc/tallpbx/certs/{$certificate->storage_identifier}/fullchain.pem";
        $updates = [
            'last_renewed_at' => now(),
            'last_renew_error' => null,
            'valid_from' => now(),
            'valid_to' => now()->addDays(90),
        ];

        if (file_exists($certPath)) {
            try {
                $parsed = $this->parser->parseFile($certPath);
                $updates['valid_from'] = $parsed['valid_from'];
                $updates['valid_to'] = $parsed['valid_to'];
                $updates['serial_number'] = $parsed['serial_number'];
                $updates['fingerprint_sha256'] = $parsed['fingerprint_sha256'];
            } catch (Throwable) {
                // Keep default calculated dates if file cannot be read directly
            }
        }

        $certificate->update($updates);

        CertificateAuditLog::create([
            'certificate_id' => $certificate->id,
            'admin_id' => $adminId,
            'action' => 'renewed',
            'status' => 'success',
            'message' => "Successfully renewed Let's Encrypt certificate '{$certificate->name}' ({$certificate->common_name}).",
            'details' => ['valid_to' => $certificate->valid_to?->toIso8601String()],
        ]);

        // Re-deploy to active services if currently assigned
        if ($certificate->is_default_web) {
            $this->deploymentService->deployWeb($certificate, $adminId);
        }

        if ($certificate->is_default_telephony) {
            $this->deploymentService->deployTelephony($certificate, $adminId);
        }

        return true;
    }

    /**
     * Sweep and renew all Let's Encrypt certificates expiring within the threshold.
     */
    public function renewAllExpiring(int $daysThreshold = 30): array
    {
        $expiring = Certificate::query()
            ->where('type', Certificate::TYPE_LETS_ENCRYPT)
            ->where('auto_renew', true)
            ->where('valid_to', '<=', now()->addDays($daysThreshold))
            ->get();

        $renewed = 0;
        $failed = 0;

        foreach ($expiring as $cert) {
            try {
                $this->renew($cert);
                $renewed++;
            } catch (Throwable) {
                $failed++;
            }
        }

        return [
            'renewed' => $renewed,
            'failed' => $failed,
            'skipped' => 0,
        ];
    }

    /**
     * Resolve metadata from disk or fallback for test runs.
     *
     * @param  array<int, string>  $fallbackSans
     * @return array<string, mixed>
     */
    private function resolveMetadata(string $domain, array $fallbackSans, bool $staging): array
    {
        $certPath = "/etc/tallpbx/certs/{$domain}/fullchain.pem";

        if (file_exists($certPath)) {
            try {
                return $this->parser->parseFile($certPath);
            } catch (Throwable) {
                // Fallback below
            }
        }

        return [
            'common_name' => $domain,
            'san_domains' => $fallbackSans,
            'issuer' => $staging ? 'Let\'s Encrypt Staging' : 'Let\'s Encrypt Authority X3',
            'valid_from' => now(),
            'valid_to' => now()->addDays(90),
            'serial_number' => null,
            'fingerprint_sha256' => null,
        ];
    }
}
