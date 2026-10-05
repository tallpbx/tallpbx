<?php

declare(strict_types=1);

namespace Modules\Certificates\Contracts;

/**
 * Contract for executing bounded host certificate operations.
 *
 * Coordinates Let's Encrypt ACME challenges, OpenSSL certificate generation,
 * and atomic service deployments via the bounded helper script /usr/local/sbin/tallpbx-certificate.
 */
interface CertificateExecutorInterface
{
    /**
     * Get the helper capability version.
     */
    public function version(): string;

    /**
     * Issue a Let's Encrypt certificate via HTTP-01 challenge.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function issueLetsEncryptHttp(string $domain, string $email, bool $staging = false): array;

    /**
     * Issue a Let's Encrypt certificate via Cloudflare DNS-01 challenge.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function issueLetsEncryptDns(string $domain, string $email, string $credsPath, bool $wildcard = false, bool $staging = false): array;

    /**
     * Renew an existing Let's Encrypt certificate.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function renewLetsEncrypt(string $domain): array;

    /**
     * Import a custom PEM certificate, private key, and optional intermediate chain.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function importCustom(string $storageId, string $pendingCert, string $pendingKey, ?string $pendingChain = null): array;

    /**
     * Generate a self-signed certificate with optional Subject Alternative Names.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function generateSelfSigned(string $storageId, string $commonName, int $days, string $sanCsv = ''): array;

    /**
     * Atomically deploy a certificate to Nginx Web with rollback on syntax failure.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function deployWeb(string $storageId): array;

    /**
     * Deploy a certificate to FreeSWITCH SIP TLS and WebRTC WSS, reloading Sofia profiles.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function deployTelephony(string $storageId): array;

    /**
     * Deploy a certificate to both Web and Telephony services.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function deployAll(string $storageId): array;

    /**
     * Delete an inactive certificate from disk.
     *
     * @return array{success: bool, output: string, error: string, exit_code: int}
     */
    public function delete(string $storageId): array;

    /**
     * Query active Web and Telephony certificate status from disk.
     *
     * @return array{active_web: string, telephony_active: bool}
     */
    public function status(): array;
}
