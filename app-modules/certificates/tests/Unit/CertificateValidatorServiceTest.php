<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Unit;

use Modules\Certificates\Exceptions\InvalidCertificateException;
use Modules\Certificates\Exceptions\MismatchedKeypairException;
use Modules\Certificates\Services\CertificateValidatorService;

beforeEach(function (): void {
    $this->validator = new CertificateValidatorService();

    // Keypair 1
    $key1 = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $dn1 = ['commonName' => 'cert1.test.local'];
    $csr1 = openssl_csr_new($dn1, $key1, ['digest_alg' => 'sha256']);
    $cert1 = openssl_csr_sign($csr1, null, $key1, 365, ['digest_alg' => 'sha256']);
    openssl_x509_export($cert1, $this->cert1Pem);
    openssl_pkey_export($key1, $this->key1Pem);

    // Keypair 2 (mismatched)
    $key2 = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $dn2 = ['commonName' => 'cert2.test.local'];
    $csr2 = openssl_csr_new($dn2, $key2, ['digest_alg' => 'sha256']);
    $cert2 = openssl_csr_sign($csr2, null, $key2, 365, ['digest_alg' => 'sha256']);
    openssl_x509_export($cert2, $this->cert2Pem);
    openssl_pkey_export($key2, $this->key2Pem);
});

it('validates matching certificate and private key successfully', function (): void {
    $isValid = $this->validator->validateKeypair($this->cert1Pem, $this->key1Pem);

    expect($isValid)->toBeTrue();
});

it('throws MismatchedKeypairException when key does not match certificate', function (): void {
    $this->validator->validateKeypair($this->cert1Pem, $this->key2Pem);
})->throws(MismatchedKeypairException::class);

it('throws InvalidCertificateException when private key is invalid', function (): void {
    $this->validator->validateKeypair($this->cert1Pem, 'INVALID_PRIVATE_KEY');
})->throws(InvalidCertificateException::class);

it('throws InvalidCertificateException when certificate is invalid', function (): void {
    $this->validator->validateKeypair('INVALID_CERT', $this->key1Pem);
})->throws(InvalidCertificateException::class);

it('validates domain names and wildcard domains correctly', function (): void {
    expect($this->validator->validateDomain('pbx.example.com'))->toBeTrue()
        ->and($this->validator->validateDomain('*.example.com'))->toBeTrue()
        ->and($this->validator->validateDomain('sub.domain.co.uk'))->toBeTrue()
        ->and($this->validator->validateDomain('invalid domain with spaces'))->toBeFalse()
        ->and($this->validator->validateDomain(''))->toBeFalse()
        ->and($this->validator->validateDomain('invalid_special$char.com'))->toBeFalse();
});

it('validates optional CA intermediate chain correctly', function (): void {
    // Empty chain is valid
    expect($this->validator->validateCertificateChain(''))->toBeTrue();

    // Valid chain block
    expect($this->validator->validateCertificateChain($this->cert1Pem))->toBeTrue();

    // Corrupt block throws
    expect(fn () => $this->validator->validateCertificateChain('CORRUPT_CHAIN_CONTENT'))
        ->toThrow(InvalidCertificateException::class);
});
