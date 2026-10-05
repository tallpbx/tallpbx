<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Unit;

use Modules\Certificates\Exceptions\InvalidCertificateException;
use Modules\Certificates\Services\CertificateParserService;

beforeEach(function (): void {
    $this->parser = new CertificateParserService();

    // Generate in-memory X.509 certificate for testing
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $dn = [
        'commonName' => 'pbx.test.local',
        'organizationName' => 'Test TallPBX',
        'countryName' => 'US',
    ];
    $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
    $certResource = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
    openssl_x509_export($certResource, $this->testCertPem);
    openssl_pkey_export($key, $this->testKeyPem);
});

it('parses valid X.509 certificate PEM correctly', function (): void {
    $parsed = $this->parser->parse($this->testCertPem);

    expect($parsed['common_name'])->toBe('pbx.test.local')
        ->and($parsed['issuer'])->toContain('Test TallPBX')
        ->and($parsed['valid_from'])->not->toBeNull()
        ->and($parsed['valid_to'])->not->toBeNull()
        ->and($parsed['fingerprint_sha256'])->toHaveLength(64)
        ->and($parsed['san_domains'])->toContain('pbx.test.local')
        ->and($parsed['is_ca'])->toBeTrue();
});

it('throws InvalidCertificateException when PEM is empty', function (): void {
    $this->parser->parse('');
})->throws(InvalidCertificateException::class, 'Certificate content is empty.');

it('throws InvalidCertificateException when PEM is corrupted', function (): void {
    $this->parser->parse("-----BEGIN CERTIFICATE-----\nNOT_A_VALID_CERTIFICATE\n-----END CERTIFICATE-----");
})->throws(InvalidCertificateException::class);

it('parses certificate from file path correctly', function (): void {
    $tempFile = tempnam(sys_get_temp_dir(), 'cert_parse_');
    file_put_contents($tempFile, $this->testCertPem);

    try {
        $parsed = $this->parser->parseFile($tempFile);
        expect($parsed['common_name'])->toBe('pbx.test.local');
    } finally {
        @unlink($tempFile);
    }
});

it('throws InvalidCertificateException when certificate file does not exist', function (): void {
    $this->parser->parseFile('/path/to/nonexistent/cert.pem');
})->throws(InvalidCertificateException::class, 'Certificate file not found');
