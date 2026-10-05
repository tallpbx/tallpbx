<?php

declare(strict_types=1);

namespace Modules\Certificates\Contracts;

use Modules\Certificates\Exceptions\AcmeChallengeException;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateDnsCredential;

/**
 * Contract for orchestrating Let's Encrypt ACME certificate issuances and renewals.
 */
interface LetsEncryptAcmeServiceInterface
{
    /**
     * Issue a Let's Encrypt certificate using HTTP-01 challenge.
     *
     * @throws AcmeChallengeException
     */
    public function issueHttp(
        string $name,
        string $domain,
        string $email,
        bool $staging = false,
        bool $autoDeployWeb = false,
        bool $autoDeployTelephony = false,
        ?int $adminId = null,
    ): Certificate;

    /**
     * Issue a Let's Encrypt certificate using Cloudflare DNS-01 challenge (supports wildcards).
     *
     * @throws AcmeChallengeException
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
    ): Certificate;

    /**
     * Renew an existing Let's Encrypt certificate.
     *
     * @throws AcmeChallengeException
     */
    public function renew(Certificate $certificate, ?int $adminId = null): bool;

    /**
     * Sweep and renew all Let's Encrypt certificates expiring within the threshold.
     *
     * @return array{renewed: int, failed: int, skipped: int}
     */
    public function renewAllExpiring(int $daysThreshold = 30): array;
}
