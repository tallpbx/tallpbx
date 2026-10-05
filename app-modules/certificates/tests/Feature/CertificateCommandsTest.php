<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Feature;

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Contracts\LetsEncryptAcmeServiceInterface;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateAuditLog;
use Modules\Certificates\Notifications\CertificateExpiringNotification;
use Modules\Certificates\Notifications\CertificateRenewalFailedNotification;

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);
});

it('reports no renewals when certificate table is empty or has no expiring certs', function (): void {
    $this->artisan('certificates:renew')
        ->expectsOutputToContain('No Let\'s Encrypt certificates require renewal')
        ->assertSuccessful();
});

it('simulates renewals in dry-run mode without modifying certificates or calling ACME service', function (): void {
    $cert = Certificate::create([
        'name' => 'Dry Run Cert',
        'type' => 'lets_encrypt',
        'common_name' => 'pbx.example.com',
        'storage_identifier' => 'dry_run_cert',
        'valid_from' => now()->subDays(60),
        'valid_to' => now()->addDays(10),
        'auto_renew' => true,
    ]);

    $mockAcme = Mockery::mock(LetsEncryptAcmeServiceInterface::class);
    $mockAcme->shouldNotReceive('renewCertificate');
    $this->app->instance(LetsEncryptAcmeServiceInterface::class, $mockAcme);

    $this->artisan('certificates:renew', ['--dry-run' => true])
        ->expectsOutputToContain('[DRY RUN] Would renew: Dry Run Cert (pbx.example.com)')
        ->assertSuccessful();

    expect(CertificateAuditLog::count())->toBe(0);
});

it('renews eligible certificates and re-deploys to assigned services', function (): void {
    $cert = Certificate::create([
        'name' => 'Active Web Cert',
        'type' => 'lets_encrypt',
        'common_name' => 'pbx.example.com',
        'storage_identifier' => 'active_web_cert',
        'valid_from' => now()->subDays(60),
        'valid_to' => now()->addDays(15),
        'auto_renew' => true,
        'is_default_web' => true,
        'is_default_telephony' => false,
    ]);

    $mockAcme = Mockery::mock(LetsEncryptAcmeServiceInterface::class);
    $mockAcme->shouldReceive('renewCertificate')
        ->once()
        ->with(Mockery::on(fn (Certificate $c): bool => $c->id === $cert->id))
        ->andReturn($cert);
    $this->app->instance(LetsEncryptAcmeServiceInterface::class, $mockAcme);

    $mockDeploy = Mockery::mock(CertificateDeploymentServiceInterface::class);
    $mockDeploy->shouldReceive('deployToWeb')
        ->once()
        ->with(Mockery::on(fn (Certificate $c): bool => $c->id === $cert->id));
    $this->app->instance(CertificateDeploymentServiceInterface::class, $mockDeploy);

    $this->artisan('certificates:renew')
        ->expectsOutputToContain('Successfully renewed: Active Web Cert')
        ->assertSuccessful();

    expect(CertificateAuditLog::where('action', 'renew')->where('status', 'success')->exists())->toBeTrue();
});

it('handles renewal failures gracefully, logs audit entries, and notifies administrators', function (): void {
    Notification::fake();

    $cert = Certificate::create([
        'name' => 'Failing Cert',
        'type' => 'lets_encrypt',
        'common_name' => 'fail.example.com',
        'storage_identifier' => 'failing_cert',
        'valid_from' => now()->subDays(70),
        'valid_to' => now()->addDays(5),
        'auto_renew' => true,
    ]);

    $mockAcme = Mockery::mock(LetsEncryptAcmeServiceInterface::class);
    $mockAcme->shouldReceive('renewCertificate')
        ->once()
        ->andThrow(new \RuntimeException('Connection timed out to Let\'s Encrypt ACME server'));
    $this->app->instance(LetsEncryptAcmeServiceInterface::class, $mockAcme);

    $this->artisan('certificates:renew')
        ->expectsOutputToContain('Failed to renew Failing Cert: Connection timed out')
        ->assertFailed();

    expect($cert->fresh()->last_renew_error)->toBe('Connection timed out to Let\'s Encrypt ACME server')
        ->and(CertificateAuditLog::where('action', 'renew_failed')->where('status', 'failed')->exists())->toBeTrue();

    Notification::assertSentTo($this->admin, CertificateRenewalFailedNotification::class);
});

it('alerts administrators about expiring non-auto-renewing certificates', function (): void {
    Notification::fake();

    Certificate::create([
        'name' => 'Custom Legacy Cert',
        'type' => 'custom',
        'common_name' => 'legacy.example.com',
        'storage_identifier' => 'legacy_cert',
        'valid_from' => now()->subYears(1),
        'valid_to' => now()->addDays(7),
        'auto_renew' => false,
    ]);

    $this->artisan('certificates:renew')
        ->assertSuccessful();

    Notification::assertSentTo($this->admin, CertificateExpiringNotification::class);
});

it('rejects deployment with invalid service option', function (): void {
    $this->artisan('certificates:deploy', ['id' => 1, '--service' => 'database'])
        ->expectsOutputToContain('Invalid service \'database\'')
        ->assertFailed();
});

it('rejects deployment when certificate is not found', function (): void {
    $this->artisan('certificates:deploy', ['id' => 99999, '--service' => 'web'])
        ->expectsOutputToContain('Certificate \'99999\' not found')
        ->assertFailed();
});

it('deploys certificate by ID to web service via CLI', function (): void {
    $cert = Certificate::create([
        'name' => 'Web Cert',
        'type' => 'self_signed',
        'common_name' => 'pbx.local',
        'storage_identifier' => 'web_deploy_test',
        'valid_from' => now(),
        'valid_to' => now()->addDays(365),
    ]);

    $mockDeploy = Mockery::mock(CertificateDeploymentServiceInterface::class);
    $mockDeploy->shouldReceive('deployToWeb')
        ->once()
        ->with(Mockery::on(fn (Certificate $c): bool => $c->id === $cert->id));
    $this->app->instance(CertificateDeploymentServiceInterface::class, $mockDeploy);

    $this->artisan('certificates:deploy', ['id' => (string) $cert->id, '--service' => 'web'])
        ->expectsOutputToContain('Successfully deployed \'Web Cert\' to web')
        ->assertSuccessful();

    expect(CertificateAuditLog::where('action', 'deployed')->where('status', 'success')->exists())->toBeTrue();
});

it('deploys certificate by common name to telephony service via CLI', function (): void {
    $cert = Certificate::create([
        'name' => 'Telephony Cert',
        'type' => 'self_signed',
        'common_name' => 'sip.example.com',
        'storage_identifier' => 'telephony_deploy_test',
        'valid_from' => now(),
        'valid_to' => now()->addDays(365),
    ]);

    $mockDeploy = Mockery::mock(CertificateDeploymentServiceInterface::class);
    $mockDeploy->shouldReceive('deployToTelephony')
        ->once()
        ->with(Mockery::on(fn (Certificate $c): bool => $c->id === $cert->id));
    $this->app->instance(CertificateDeploymentServiceInterface::class, $mockDeploy);

    $this->artisan('certificates:deploy', ['id' => 'sip.example.com', '--service' => 'telephony'])
        ->expectsOutputToContain('Successfully deployed \'Telephony Cert\' to telephony')
        ->assertSuccessful();
});

it('handles deployment service failures and logs audit records', function (): void {
    $cert = Certificate::create([
        'name' => 'Broken Cert',
        'type' => 'self_signed',
        'common_name' => 'broken.example.com',
        'storage_identifier' => 'broken_deploy_test',
        'valid_from' => now(),
        'valid_to' => now()->addDays(365),
    ]);

    $mockDeploy = Mockery::mock(CertificateDeploymentServiceInterface::class);
    $mockDeploy->shouldReceive('deployToAll')
        ->once()
        ->andThrow(new \RuntimeException('FreeSWITCH TLS directory missing'));
    $this->app->instance(CertificateDeploymentServiceInterface::class, $mockDeploy);

    $this->artisan('certificates:deploy', ['id' => (string) $cert->id, '--service' => 'all'])
        ->expectsOutputToContain('Deployment failed: FreeSWITCH TLS directory missing')
        ->assertFailed();

    expect(CertificateAuditLog::where('action', 'deploy_failed')->where('status', 'failed')->exists())->toBeTrue();
});

it('displays certificates inventory and service bindings in status command', function (): void {
    Certificate::create([
        'name' => 'Production Cert',
        'type' => 'lets_encrypt',
        'common_name' => 'pbx.tallpbx.com',
        'storage_identifier' => 'prod_cert_test',
        'valid_from' => now()->subDays(10),
        'valid_to' => now()->addDays(80),
        'is_default_web' => true,
        'is_default_telephony' => true,
        'auto_renew' => true,
    ]);

    $mockDeploy = Mockery::mock(CertificateDeploymentServiceInterface::class);
    $mockDeploy->shouldReceive('getDeploymentStatus')
        ->once()
        ->andReturn([
            'web_active' => true,
            'telephony_active' => true,
        ]);
    $this->app->instance(CertificateDeploymentServiceInterface::class, $mockDeploy);

    $mockExecutor = Mockery::mock(CertificateExecutorInterface::class);
    $mockExecutor->shouldReceive('version')
        ->once()
        ->andReturn('tallpbx-cert-helper-version: 1');
    $mockExecutor->shouldReceive('status')
        ->once()
        ->andReturn([
            'active_web' => 'prod_cert_test',
            'telephony_active' => true,
        ]);
    $this->app->instance(CertificateExecutorInterface::class, $mockExecutor);

    $this->artisan('certificates:status')
        ->expectsOutputToContain('=== TallPBX Certificate Manager Status ===')
        ->expectsOutputToContain('Production Cert')
        ->expectsOutputToContain('=== Active Service Deployments ===')
        ->expectsOutputToContain('tallpbx-cert-helper-version: 1')
        ->assertSuccessful();
});
