<?php

declare(strict_types=1);

namespace Modules\Certificates\Contracts;

use Modules\Certificates\Exceptions\InvalidCertificateException;
use Modules\Certificates\Exceptions\MismatchedKeypairException;

/**
 * Contract for validating SSL/TLS cryptographic keypairs and domain names.
 */
interface CertificateValidatorServiceInterface
{
    /**
     * Validate that a private key matches the public key modulus of an X.509 certificate.
     *
     * @param  string  $certificatePem  PEM-encoded certificate
     * @param  string  $privateKeyPem  PEM-encoded private key
     * @param  string|null  $passphrase  Optional private key decryption passphrase
     * @return bool True if keypair is cryptographically valid and matches
     *
     * @throws InvalidCertificateException If certificate or private key syntax is invalid
     * @throws MismatchedKeypairException If private key modulus does not match the certificate
     */
    public function validateKeypair(string $certificatePem, string $privateKeyPem, ?string $passphrase = null): bool;

    /**
     * Validate that intermediate/full chain PEM content contains valid X.509 certificates.
     *
     * @param  string  $chainPem
     * @return bool
     *
     * @throws InvalidCertificateException
     */
    public function validateCertificateChain(string $chainPem): bool;

    /**
     * Validate a Fully Qualified Domain Name or wildcard domain string.
     */
    public function validateDomain(string $domain): bool;
}
