<?php

declare(strict_types=1);

namespace Modules\Certificates\Services;

use Modules\Certificates\Contracts\CertificateValidatorServiceInterface;
use Modules\Certificates\Exceptions\InvalidCertificateException;
use Modules\Certificates\Exceptions\MismatchedKeypairException;

/**
 * Service for verifying cryptographic keypairs, certificates, and domain syntax.
 */
class CertificateValidatorService implements CertificateValidatorServiceInterface
{
    /**
     * Regex matching valid FQDNs and wildcard domain names.
     */
    private const DOMAIN_REGEX = '/^(\*\.)?([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/';

    /**
     * Validate that a private key matches the public key modulus of an X.509 certificate.
     */
    public function validateKeypair(string $certificatePem, string $privateKeyPem, ?string $passphrase = null): bool
    {
        $cleanCert = trim($certificatePem);
        $cleanKey = trim($privateKeyPem);

        if ($cleanCert === '') {
            throw new InvalidCertificateException('Certificate PEM content cannot be empty.');
        }

        if ($cleanKey === '') {
            throw new InvalidCertificateException('Private key PEM content cannot be empty.');
        }

        // 1. Verify certificate syntax
        $certResource = @openssl_x509_read($cleanCert);
        if ($certResource === false) {
            throw new InvalidCertificateException('Invalid X.509 certificate PEM format.');
        }

        // 2. Extract public key from certificate
        $publicKeyResource = @openssl_pkey_get_public($certResource);
        if ($publicKeyResource === false) {
            throw new InvalidCertificateException('Unable to extract public key from certificate.');
        }

        $certKeyDetails = openssl_pkey_get_details($publicKeyResource);
        if ($certKeyDetails === false) {
            throw new InvalidCertificateException('Failed to read certificate public key details.');
        }

        // 3. Load private key
        $privateKeyResource = @openssl_pkey_get_private($cleanKey, $passphrase ?? '');
        if ($privateKeyResource === false) {
            throw new InvalidCertificateException('Invalid private key PEM format or incorrect passphrase.');
        }

        $privateKeyDetails = openssl_pkey_get_details($privateKeyResource);
        if ($privateKeyDetails === false) {
            throw new InvalidCertificateException('Failed to read private key details.');
        }

        // 4. Compare public keys
        if ($certKeyDetails['key'] !== $privateKeyDetails['key']) {
            throw new MismatchedKeypairException('Private key does not match the certificate public key modulus.');
        }

        // 5. If RSA, also assert modulus (n) equality
        if (isset($certKeyDetails['rsa']['n'], $privateKeyDetails['rsa']['n'])) {
            if ($certKeyDetails['rsa']['n'] !== $privateKeyDetails['rsa']['n']) {
                throw new MismatchedKeypairException('RSA modulus mismatch between certificate and private key.');
            }
        }

        return true;
    }

    /**
     * Validate that intermediate/full chain PEM content contains valid X.509 certificates.
     */
    public function validateCertificateChain(string $chainPem): bool
    {
        $cleanChain = trim($chainPem);

        if ($cleanChain === '') {
            return true; // Optional intermediate chain can be empty
        }

        // Split individual PEM blocks
        preg_match_all('/-----BEGIN CERTIFICATE-----[^-]+-----END CERTIFICATE-----/', $cleanChain, $matches);

        if (empty($matches[0])) {
            throw new InvalidCertificateException('CA chain does not contain any valid BEGIN/END CERTIFICATE blocks.');
        }

        foreach ($matches[0] as $certBlock) {
            $parsed = @openssl_x509_parse($certBlock);
            if ($parsed === false) {
                throw new InvalidCertificateException('Failed to parse one of the certificates in the CA chain.');
            }
        }

        return true;
    }

    /**
     * Validate a Fully Qualified Domain Name or wildcard domain string.
     */
    public function validateDomain(string $domain): bool
    {
        $cleanDomain = trim($domain);

        if ($cleanDomain === '' || strlen($cleanDomain) > 253) {
            return false;
        }

        return (bool) preg_match(self::DOMAIN_REGEX, $cleanDomain);
    }
}
