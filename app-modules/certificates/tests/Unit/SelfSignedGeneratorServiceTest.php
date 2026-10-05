<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Unit;

use App\Models\Admin;
use Mockery;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Contracts\CertificateParserServiceInterface;
use Modules\Certificates\Contracts\CertificateValidatorServiceInterface;
use Modules\Certificates\Exceptions\CertificateException;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;
use Modules\Certificates\Services\SelfSignedGeneratorService;

beforeEach(function (): void {
    $this->executor = Mockery::mock(CertificateExecutorInterface::class);
    $this->parser = Mockery::mock(CertificateParserServiceInterface::class);
    $this->validator = Mockery::mock(CertificateValidatorServiceInterface::class);
    $this->deploymentService = Mockery::mock(CertificateDeploymentServiceInterface::class);

    $this->admin = Admin::factory()->create(['enabled' => true]);

    $this->service = new SelfSignedGeneratorService(
        $this->executor,
        $this->parser,
        $this->validator,
        $this->deploymentService,
    );
});

afterEach(function (): void {
    Mockery::close();
});

it('throws CertificateException when common name is empty', function (): void {
    expect(fn () => $this->service->generate('Test Cert', '   '))
        ->toThrow(CertificateException::class, 'Common Name cannot be empty.');
});

it('generates self-signed certificate successfully and records audit log', function (): void {
    $this->executor->shouldReceive('generateSelfSigned')
        ->once()
        ->with(
            Mockery::on(fn ($id) => str_starts_with($id, 'self_pbxexamplecom_')),
            'pbx.example.com',
            365,
            'sip.example.com,webrtc.example.com'
        )
        ->andReturn([
            'success' => true,
            'output' => 'Self-signed certificate generated successfully',
            'error' => '',
            'exit_code' => 0,
        ]);

    $cert = $this->service->generate(
        name: 'PBX Lab Cert',
        commonName: 'pbx.example.com',
        days: 365,
        sanDomains: ['sip.example.com', 'webrtc.example.com'],
        autoDeployWeb: false,
        autoDeployTelephony: false,
        adminId: $this->admin->id,
    );

    expect($cert)->toBeInstanceOf(Certificate::class)
        ->and($cert->name)->toBe('PBX Lab Cert')
        ->and($cert->type)->toBe(Certificate::TYPE_SELF_SIGNED)
        ->and($cert->common_name)->toBe('pbx.example.com')
        ->and($cert->san_domains)->toContain('pbx.example.com', 'sip.example.com', 'webrtc.example.com')
        ->and($cert->storage_identifier)->toStartWith('self_pbxexamplecom_')
        ->and($cert->is_default_web)->toBeFalse()
        ->and($cert->is_default_telephony)->toBeFalse();

    $log = CertificateAuditLog::where('certificate_id', $cert->id)->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('generated')
        ->and($log->status)->toBe('success')
        ->and($log->admin_id)->toBe($this->admin->id);
});

it('throws CertificateException when generator executor fails', function (): void {
    $this->executor->shouldReceive('generateSelfSigned')
        ->once()
        ->andReturn([
            'success' => false,
            'output' => '',
            'error' => 'OpenSSL key generation failed: memory exhaustion',
            'exit_code' => 1,
        ]);

    expect(fn () => $this->service->generate('Fail Cert', 'fail.example.com'))
        ->toThrow(CertificateException::class, 'OpenSSL key generation failed: memory exhaustion');

    expect(Certificate::where('common_name', 'fail.example.com')->exists())->toBeFalse();
});

it('triggers autoDeployWeb and autoDeployTelephony when toggles are enabled', function (): void {
    $this->executor->shouldReceive('generateSelfSigned')
        ->once()
        ->andReturn([
            'success' => true,
            'output' => '',
            'error' => '',
            'exit_code' => 0,
        ]);

    $this->deploymentService->shouldReceive('deployWeb')
        ->once()
        ->with(Mockery::type(Certificate::class), $this->admin->id)
        ->andReturn(true);

    $this->deploymentService->shouldReceive('deployTelephony')
        ->once()
        ->with(Mockery::type(Certificate::class), $this->admin->id)
        ->andReturn(true);

    $cert = $this->service->generate(
        name: 'Auto Deploy Cert',
        commonName: 'auto.example.com',
        days: 90,
        sanDomains: [],
        autoDeployWeb: true,
        autoDeployTelephony: true,
        adminId: $this->admin->id,
    );

    expect($cert->common_name)->toBe('auto.example.com');
});
