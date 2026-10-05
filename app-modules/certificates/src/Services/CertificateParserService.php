<?php

declare(strict_types=1);

namespace Modules\Certificates\Services;

use Illuminate\Support\Carbon;
use Modules\Certificates\Contracts\CertificateParserServiceInterface;
use Modules\Certificates\Exceptions\InvalidCertificateException;

/**
 * Service for parsing and inspecting X.509 SSL/TLS certificates using PHP OpenSSL.
 */
class CertificateParserService implements CertificateParserServiceInterface
{
    /**
     * Parse X.509 certificate PEM string and return structured metadata.
     */
    public function parse(string $pemContent): array
    {
        $cleanPem = trim($pemContent);

        if ($cleanPem === '') {
            throw new InvalidCertificateException('Certificate content is empty.');
        }

        $parsed = @openssl_x509_parse($cleanPem);

        if ($parsed === false) {
            throw new InvalidCertificateException('Failed to parse X.509 certificate. The provided content is not a valid PEM-formatted certificate.');
        }

        $commonName = (string) ($parsed['subject']['CN'] ?? '');

        // Extract and clean Subject Alternative Names (SANs)
        $sanDomains = $this->extractSanDomains($parsed, $commonName);

        // Resolve issuer
        $issuerO = trim((string) ($parsed['issuer']['O'] ?? ''));
        $issuerCn = trim((string) ($parsed['issuer']['CN'] ?? ''));

        if ($issuerO !== '' && $issuerCn !== '' && ! str_contains($issuerO, $issuerCn) && ! str_contains($issuerCn, $issuerO)) {
            $issuer = "{$issuerO} ({$issuerCn})";
        } else {
            $issuer = $issuerO !== '' ? $issuerO : ($issuerCn !== '' ? $issuerCn : 'Self-Signed / Unknown Authority');
        }

        $validFrom = Carbon::createFromTimestamp((int) ($parsed['validFrom_time_t'] ?? time()));
        $validTo = Carbon::createFromTimestamp((int) ($parsed['validTo_time_t'] ?? time()));

        $serialNumber = (string) ($parsed['serialNumberHex'] ?? ($parsed['serialNumber'] ?? ''));

        $fingerprint = openssl_x509_fingerprint($cleanPem, 'sha256');
        $fingerprintSha256 = $fingerprint !== false ? strtolower($fingerprint) : '';

        $signatureAlgorithm = (string) ($parsed['signatureTypeLN'] ?? ($parsed['signatureTypeSN'] ?? 'RSA/SHA256'));

        $basicConstraints = (string) ($parsed['extensions']['basicConstraints'] ?? '');
        $isCa = str_contains(strtoupper($basicConstraints), 'CA:TRUE');

        return [
            'common_name' => $commonName,
            'san_domains' => $sanDomains,
            'issuer' => $issuer,
            'valid_from' => $validFrom,
            'valid_to' => $validTo,
            'serial_number' => $serialNumber,
            'fingerprint_sha256' => $fingerprintSha256,
            'signature_algorithm' => $signatureAlgorithm,
            'is_ca' => $isCa,
        ];
    }

    /**
     * Parse X.509 certificate from file path and return structured metadata.
     */
    public function parseFile(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new InvalidCertificateException("Certificate file not found: {$filePath}");
        }

        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new InvalidCertificateException("Unable to read certificate file: {$filePath}");
        }

        return $this->parse($content);
    }

    /**
     * Extract SAN domains and IP addresses from parsed OpenSSL extensions.
     *
     * @param  array<string, mixed>  $parsed
     * @return array<int, string>
     */
    private function extractSanDomains(array $parsed, string $commonName): array
    {
        $sans = [];

        $altNames = $parsed['extensions']['subjectAltName'] ?? null;

        if (is_string($altNames) && trim($altNames) !== '') {
            $parts = explode(',', $altNames);

            foreach ($parts as $part) {
                $trimmed = trim($part);

                if (str_starts_with($trimmed, 'DNS:')) {
                    $sans[] = substr($trimmed, 4);
                } elseif (str_starts_with($trimmed, 'IP Address:')) {
                    $sans[] = substr($trimmed, 11);
                } elseif (str_starts_with($trimmed, 'IP:')) {
                    $sans[] = substr($trimmed, 3);
                } elseif ($trimmed !== '') {
                    $sans[] = $trimmed;
                }
            }
        }

        if ($sans === [] && $commonName !== '') {
            $sans[] = $commonName;
        }

        return array_values(array_unique($sans));
    }
}
