<?php

declare(strict_types=1);

namespace Modules\Certificates\Contracts;

use Illuminate\Support\Carbon;
use Modules\Certificates\Exceptions\InvalidCertificateException;

/**
 * Contract for parsing and extracting metadata from X.509 SSL/TLS certificates.
 */
interface CertificateParserServiceInterface
{
    /**
     * Parse X.509 certificate PEM string and return structured metadata.
     *
     * @param  string  $pemContent  Raw PEM certificate content
     * @return array{
     *     common_name: string,
     *     san_domains: array<int, string>,
     *     issuer: string,
     *     valid_from: Carbon,
     *     valid_to: Carbon,
     *     serial_number: string,
     *     fingerprint_sha256: string,
     *     signature_algorithm: string,
     *     is_ca: bool
     * }
     *
     * @throws InvalidCertificateException
     */
    public function parse(string $pemContent): array;

    /**
     * Parse X.509 certificate from file path and return structured metadata.
     *
     * @param  string  $filePath  Filesystem path to PEM certificate
     * @return array{
     *     common_name: string,
     *     san_domains: array<int, string>,
     *     issuer: string,
     *     valid_from: Carbon,
     *     valid_to: Carbon,
     *     serial_number: string,
     *     fingerprint_sha256: string,
     *     signature_algorithm: string,
     *     is_ca: bool
     * }
     *
     * @throws InvalidCertificateException
     */
    public function parseFile(string $filePath): array;
}
