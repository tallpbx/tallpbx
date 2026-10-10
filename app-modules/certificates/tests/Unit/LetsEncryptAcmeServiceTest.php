<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Unit;

use App\Models\Admin;
use Mockery;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Contracts\CertificateParserServiceInterface;
use Modules\Certificates\Exceptions\AcmeChallengeException;
use Modules\Certificates\Exceptions\CertificateException;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;
use Modules\Certificates\Models\CertificateDnsCredential;
use Modules\Certificates\Services\CertificateValidatorService;
use Modules\Certificates\Services\LetsEncryptAcmeService;

beforeEach(function (): void {
    $this->executor = Mockery::mock(CertificateExecutorInterface::class);
    $this->parser = Mockery::mock(CertificateParserServiceInterface::class);
    $this->validator = new CertificateValidatorService;
    $this->deploymentService = Mockery::mock(CertificateDeploymentServiceInterface::class);

    $this->admin = Admin::factory()->create(['enabled' => true]);

    $this->service = new LetsEncryptAcmeService(
        $this->executor,
        $this->parser,
        $this->validator,
        $this->deploymentService,
    );
});

afterEach(function (): void {
    Mockery::close();
});

it('throws CertificateException for invalid domain in issueHttp', function (): void {
    expect(fn () => $this->service->issueHttp('Test', 'invalid domain name', 'admin@example.com'))
        ->toThrow(CertificateException::class, "Invalid domain name: 'invalid domain name'");
});

it('throws CertificateException for invalid email in issueHttp', function (): void {
    expect(fn () => $this->service->issueHttp('Test', 'pbx.example.com', 'invalid-email'))
        ->toThrow(CertificateException::class, "Invalid administrator email address: 'invalid-email'");
});

it('issues Let\'s Encrypt certificate via HTTP-01 challenge successfully', function (): void {
    $this->executor->shouldReceive('issueLetsEncryptHttp')
        ->once()
        ->with('pbx.example.com', 'admin@example.com', false)
        ->andReturn([
            'success' => true,
            'output' => 'Certificate issued successfully',
            'error' => '',
            'exit_code' => 0,
        ]);

    $cert = $this->service->issueHttp(
        name: 'PBX Web Cert',
        domain: 'pbx.example.com',
        email: 'admin@example.com',
        staging: false,
        autoDeployWeb: false,
        autoDeployTelephony: false,
        adminId: $this->admin->id,
    );

    expect($cert)->toBeInstanceOf(Certificate::class)
        ->and($cert->type)->toBe(Certificate::TYPE_LETS_ENCRYPT)
        ->and($cert->challenge_type)->toBe(Certificate::CHALLENGE_HTTP)
        ->and($cert->common_name)->toBe('pbx.example.com')
        ->and($cert->auto_renew)->toBeTrue()
        ->and($cert->is_staging)->toBeFalse();

    $log = CertificateAuditLog::where('certificate_id', $cert->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('issued')
        ->and($log->status)->toBe('success')
        ->and($log->admin_id)->toBe($this->admin->id);
});

it('throws AcmeChallengeException and logs audit error when HTTP-01 challenge fails', function (): void {
    $this->executor->shouldReceive('issueLetsEncryptHttp')
        ->once()
        ->andReturn([
            'success' => false,
            'output' => '',
            'error' => 'Connection refused when validating .well-known/acme-challenge',
            'exit_code' => 1,
        ]);

    expect(fn () => $this->service->issueHttp('Fail Cert', 'pbx.example.com', 'admin@example.com', false, false, false, $this->admin->id))
        ->toThrow(AcmeChallengeException::class, 'Connection refused when validating .well-known/acme-challenge');

    expect(Certificate::where('common_name', 'pbx.example.com')->exists())->toBeFalse();

    $log = CertificateAuditLog::where('action', 'issued')->where('status', 'error')->first();
    expect($log)->not->toBeNull()
        ->and($log->message)->toContain('Connection refused');
});

it('issues Let\'s Encrypt certificate via Cloudflare DNS-01 challenge with wildcard support', function (): void {
    $dnsCred = CertificateDnsCredential::create([
        'name' => 'Primary Cloudflare',
        'provider' => 'cloudflare',
        'credentials' => ['api_token' => 'test_cf_token_12345'],
    ]);

    $this->executor->shouldReceive('issueLetsEncryptDns')
        ->once()
        ->with(
            'example.com',
            'admin@example.com',
            Mockery::on(function ($path) {
                return file_exists($path) && str_contains(file_get_contents($path), 'test_cf_token_12345');
            }),
            true, // wildcard
            false // staging
        )
        ->andReturn([
            'success' => true,
            'output' => 'DNS challenge completed',
            'error' => '',
            'exit_code' => 0,
        ]);

    $cert = $this->service->issueDns(
        name: 'Wildcard Cert',
        domain: 'example.com',
        email: 'admin@example.com',
        dnsCredential: $dnsCred,
        wildcard: true,
        staging: false,
        autoDeployWeb: false,
        autoDeployTelephony: false,
        adminId: $this->admin->id,
    );

    expect($cert->challenge_type)->toBe(Certificate::CHALLENGE_DNS)
        ->and($cert->dns_credential_id)->toBe($dnsCred->id)
        ->and($cert->san_domains)->toContain('example.com', '*.example.com');
});

it('throws CertificateException when DNS credentials payload lacks api_token', function (): void {
    $dnsCred = CertificateDnsCredential::create([
        'name' => 'Empty Creds',
        'provider' => 'cloudflare',
        'credentials' => [],
    ]);

    expect(fn () => $this->service->issueDns(
        name: 'Wildcard Cert',
        domain: 'example.com',
        email: 'admin@example.com',
        dnsCredential: $dnsCred,
    ))->toThrow(CertificateException::class, 'DNS credential payload is missing required api_token.');
});

it('successfully renews Let\'s Encrypt certificate and redeploys to active services', function (): void {
    $cert = Certificate::create([
        'name' => 'Active Cert',
        'type' => Certificate::TYPE_LETS_ENCRYPT,
        'common_name' => 'pbx.example.com',
        'san_domains' => ['pbx.example.com'],
        'issuer' => 'Let\'s Encrypt',
        'valid_from' => now()->subDays(60),
        'valid_to' => now()->addDays(15),
        'storage_identifier' => 'pbx.example.com',
        'is_default_web' => true,
        'is_default_telephony' => true,
        'auto_renew' => true,
    ]);

    $this->executor->shouldReceive('renewLetsEncrypt')
        ->once()
        ->with('pbx.example.com')
        ->andReturn([
            'success' => true,
            'output' => 'Certificate renewed',
            'error' => '',
            'exit_code' => 0,
        ]);

    $this->deploymentService->shouldReceive('deployWeb')
        ->once()
        ->with(Mockery::on(fn ($c) => $c->id === $cert->id), $this->admin->id)
        ->andReturn(true);

    $this->deploymentService->shouldReceive('deployTelephony')
        ->once()
        ->with(Mockery::on(fn ($c) => $c->id === $cert->id), $this->admin->id)
        ->andReturn(true);

    $result = $this->service->renew($cert, $this->admin->id);

    expect($result)->toBeTrue();
    expect($cert->fresh()->last_renewed_at)->not->toBeNull();
    expect($cert->fresh()->last_renew_error)->toBeNull();

    $log = CertificateAuditLog::where('certificate_id', $cert->id)->where('action', 'renewed')->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe('success');
});

it('records last_renew_error and audit log when renewal fails', function (): void {
    $cert = Certificate::create([
        'name' => 'Expiring Cert',
        'type' => Certificate::TYPE_LETS_ENCRYPT,
        'common_name' => 'pbx.example.com',
        'san_domains' => ['pbx.example.com'],
        'issuer' => 'Let\'s Encrypt',
        'valid_from' => now()->subDays(85),
        'valid_to' => now()->addDays(5),
        'storage_identifier' => 'pbx.example.com',
        'auto_renew' => true,
    ]);

    $this->executor->shouldReceive('renewLetsEncrypt')
        ->once()
        ->with('pbx.example.com')
        ->andReturn([
            'success' => false,
            'output' => '',
            'error' => 'Too many failed authorizations recently',
            'exit_code' => 1,
        ]);

    expect(fn () => $this->service->renew($cert, $this->admin->id))
        ->toThrow(AcmeChallengeException::class, 'Too many failed authorizations recently');

    expect($cert->fresh()->last_renew_error)->toBe('Too many failed authorizations recently');

    $log = CertificateAuditLog::where('certificate_id', $cert->id)->where('action', 'renewed')->first();
    expect($log)->not->toBeNull()
        ->and($log->status)->toBe('error');
});

it('sweeps and renews all expiring certificates in renewAllExpiring', function (): void {
    // Cert 1: Expiring in 10 days, auto_renew true -> should renew
    $cert1 = Certificate::create([
        'name' => 'Expiring Soon 1',
        'type' => Certificate::TYPE_LETS_ENCRYPT,
        'common_name' => 'pbx1.example.com',
        'san_domains' => ['pbx1.example.com'],
        'issuer' => 'Let\'s Encrypt',
        'valid_from' => now()->subDays(80),
        'valid_to' => now()->addDays(10),
        'storage_identifier' => 'pbx1.example.com',
        'auto_renew' => true,
    ]);

    // Cert 2: Expiring in 60 days, auto_renew true -> should NOT renew
    Certificate::create([
        'name' => 'Valid Long',
        'type' => Certificate::TYPE_LETS_ENCRYPT,
        'common_name' => 'pbx2.example.com',
        'san_domains' => ['pbx2.example.com'],
        'issuer' => 'Let\'s Encrypt',
        'valid_from' => now()->subDays(30),
        'valid_to' => now()->addDays(60),
        'storage_identifier' => 'pbx2.example.com',
        'auto_renew' => true,
    ]);

    // Cert 3: Expiring in 5 days, but auto_renew is FALSE -> should NOT renew
    Certificate::create([
        'name' => 'Manual Renew',
        'type' => Certificate::TYPE_LETS_ENCRYPT,
        'common_name' => 'pbx3.example.com',
        'san_domains' => ['pbx3.example.com'],
        'issuer' => 'Let\'s Encrypt',
        'valid_from' => now()->subDays(85),
        'valid_to' => now()->addDays(5),
        'storage_identifier' => 'pbx3.example.com',
        'auto_renew' => false,
    ]);

    $this->executor->shouldReceive('renewLetsEncrypt')
        ->once()
        ->with('pbx1.example.com')
        ->andReturn(['success' => true, 'output' => '', 'error' => '', 'exit_code' => 0]);

    $summary = $this->service->renewAllExpiring(30);

    expect($summary['renewed'])->toBe(1)
        ->and($summary['failed'])->toBe(0);
});
