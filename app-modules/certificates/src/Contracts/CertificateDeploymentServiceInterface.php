<?php

declare(strict_types=1);

namespace Modules\Certificates\Contracts;

use Modules\Certificates\Exceptions\CertificateDeploymentException;
use Modules\Certificates\Models\Certificate;

/**
 * Contract for deploying certificates to Nginx Web and FreeSWITCH Telephony.
 */
interface CertificateDeploymentServiceInterface
{
    /**
     * Deploy a certificate to Nginx Web (HTTPS :443 and Reverb WebSockets).
     *
     * @throws CertificateDeploymentException
     */
    public function deployWeb(Certificate $certificate, ?int $adminId = null): bool;

    /**
     * Deploy a certificate to FreeSWITCH Telephony (SIP TLS :5061 and WebRTC WSS :7443).
     *
     * @throws CertificateDeploymentException
     */
    public function deployTelephony(Certificate $certificate, ?int $adminId = null): bool;

    /**
     * Deploy a certificate to both Web and Telephony services.
     *
     * @throws CertificateDeploymentException
     */
    public function deployAll(Certificate $certificate, ?int $adminId = null): bool;

    /**
     * Query runtime deployment status across Web and Telephony.
     *
     * @return array{
     *     active_web: string,
     *     telephony_active: bool,
     *     active_web_certificate: Certificate|null,
     *     active_telephony_certificate: Certificate|null
     * }
     */
    public function getDeploymentStatus(): array;
}
