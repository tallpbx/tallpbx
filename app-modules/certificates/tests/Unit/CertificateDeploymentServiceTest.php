<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Unit;

use App\Models\Admin;
use Mockery;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Exceptions\CertificateDeploymentException;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;
use Modules\Certificates\Services\CertificateDeploymentService;

beforeEach(function (): void {
    $this->executor = Mockery::mock(CertificateExecutorInterface::class);
    $this->service = new CertificateDeploymentService($this->executor);

    $this->admin = Admin::factory()->create(['enabled' => true]);

    $this->cert1 = Certificate::create([
        'name' => 'Web Certificate',
        'type' => Certificate::TYPE_SELF_SIGNED,
        'common_name' => 'pbx1.example.com',
        'san_domains' => ['pbx1.example.com'],
        'issuer' => 'TallPBX',
        'valid_from' => now(),
        'valid_to' => now()->addDays(90),
        'storage_identifier' => 'self_cert_1',
        'is_default_web' => true,
        'is_default_telephony' => false,
    ]);

    $this->cert2 = Certificate::create([
        'name' => 'New Certificate',
        'type' => Certificate::TYPE_LETS_ENCRYPT,
        'common_name' => 'pbx2.example.com',
        'san_domains' => ['pbx2.example.com'],
        'issuer' => 'Let\'s Encrypt',
        'valid_from' => now(),
        'valid_to' => now()->addDays(90),
        'storage_identifier' => 'self_cert_2',
        'is_default_web' => false,
        'is_default_telephony' => false,
    ]);
});

afterEach(function (): void {
    Mockery::close();
});

it('successfully deploys certificate to Nginx web server and updates default state', function (): void {
    $this->executor->shouldReceive('deployWeb')
        ->once()
        ->with('self_cert_2')
        ->andReturn([
            'success' => true,
            'output' => 'Nginx reloaded successfully',
            'error' => '',
            'exit_code' => 0,
        ]);

    $result = $this->service->deployWeb($this->cert2, $this->admin->id);

    expect($result)->toBeTrue();
    expect($this->cert1->fresh()->is_default_web)->toBeFalse();
    expect($this->cert2->fresh()->is_default_web)->toBeTrue();

    $log = CertificateAuditLog::where('certificate_id', $this->cert2->id)->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('deployed_web')
        ->and($log->status)->toBe('success')
        ->and($log->admin_id)->toBe($this->admin->id);
});

it('throws CertificateDeploymentException and logs audit error when web deployment fails', function (): void {
    $this->executor->shouldReceive('deployWeb')
        ->once()
        ->with('self_cert_2')
        ->andReturn([
            'success' => false,
            'output' => '',
            'error' => 'nginx: configuration file /etc/nginx/nginx.conf test failed',
            'exit_code' => 1,
        ]);

    expect(fn () => $this->service->deployWeb($this->cert2, $this->admin->id))
        ->toThrow(CertificateDeploymentException::class, 'nginx: configuration file /etc/nginx/nginx.conf test failed');

    expect($this->cert1->fresh()->is_default_web)->toBeTrue();
    expect($this->cert2->fresh()->is_default_web)->toBeFalse();

    $log = CertificateAuditLog::where('certificate_id', $this->cert2->id)->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('deployed_web')
        ->and($log->status)->toBe('error');
});

it('successfully deploys certificate to FreeSWITCH telephony and updates default state', function (): void {
    $this->cert1->update(['is_default_telephony' => true]);

    $this->executor->shouldReceive('deployTelephony')
        ->once()
        ->with('self_cert_2')
        ->andReturn([
            'success' => true,
            'output' => 'FreeSWITCH Sofia profiles reloaded',
            'error' => '',
            'exit_code' => 0,
        ]);

    $result = $this->service->deployTelephony($this->cert2, $this->admin->id);

    expect($result)->toBeTrue();
    expect($this->cert1->fresh()->is_default_telephony)->toBeFalse();
    expect($this->cert2->fresh()->is_default_telephony)->toBeTrue();

    $log = CertificateAuditLog::where('certificate_id', $this->cert2->id)->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('deployed_telephony')
        ->and($log->status)->toBe('success')
        ->and($log->admin_id)->toBe($this->admin->id);
});

it('throws CertificateDeploymentException when telephony deployment fails', function (): void {
    $this->executor->shouldReceive('deployTelephony')
        ->once()
        ->with('self_cert_2')
        ->andReturn([
            'success' => false,
            'output' => '',
            'error' => 'fs_cli reload failed',
            'exit_code' => 1,
        ]);

    expect(fn () => $this->service->deployTelephony($this->cert2))
        ->toThrow(CertificateDeploymentException::class, 'fs_cli reload failed');

    expect($this->cert2->fresh()->is_default_telephony)->toBeFalse();

    $log = CertificateAuditLog::where('certificate_id', $this->cert2->id)->latest()->first();
    expect($log)->not->toBeNull()
        ->and($log->action)->toBe('deployed_telephony')
        ->and($log->status)->toBe('error');
});

it('deploys to both web and telephony via deployAll', function (): void {
    $this->executor->shouldReceive('deployWeb')
        ->once()
        ->with('self_cert_2')
        ->andReturn(['success' => true, 'output' => '', 'error' => '', 'exit_code' => 0]);

    $this->executor->shouldReceive('deployTelephony')
        ->once()
        ->with('self_cert_2')
        ->andReturn(['success' => true, 'output' => '', 'error' => '', 'exit_code' => 0]);

    $result = $this->service->deployAll($this->cert2, $this->admin->id);

    expect($result)->toBeTrue();
    expect($this->cert2->fresh()->is_default_web)->toBeTrue();
    expect($this->cert2->fresh()->is_default_telephony)->toBeTrue();
});

it('returns accurate deployment status mapping certificates to active runtime services', function (): void {
    $this->executor->shouldReceive('status')
        ->once()
        ->andReturn([
            'active_web' => 'self_cert_1',
            'telephony_active' => true,
        ]);

    $status = $this->service->getDeploymentStatus();

    expect($status['active_web'])->toBe('self_cert_1')
        ->and($status['telephony_active'])->toBeTrue()
        ->and($status['active_web_certificate']?->id)->toBe($this->cert1->id)
        ->and($status['active_telephony_certificate'])->toBeNull();
});
