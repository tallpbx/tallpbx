<?php

declare(strict_types=1);

namespace Modules\Certificates\Contracts;

use Modules\Certificates\Models\Certificate;

/**
 * Contract for generating self-signed SSL/TLS certificates.
 */
interface SelfSignedGeneratorServiceInterface
{
    /**
     * Generate a self-signed certificate and register it in the inventory.
     *
     * @param  string  $name  Display name for the certificate
     * @param  string  $commonName  Primary FQDN or IP
     * @param  int  $days  Validity duration in days
     * @param  array<int, string>  $sanDomains  Optional SAN domains/IPs
     * @param  bool  $autoDeployWeb  Automatically assign to Nginx Web
     * @param  bool  $autoDeployTelephony  Automatically assign to FreeSWITCH Telephony
     * @param  int|null  $adminId  Admin executing the action
     */
    public function generate(
        string $name,
        string $commonName,
        int $days = 365,
        array $sanDomains = [],
        bool $autoDeployWeb = false,
        bool $autoDeployTelephony = false,
        ?int $adminId = null,
    ): Certificate;
}
