<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Feature;

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;
use Livewire\Livewire;
use Mockery;
use Modules\Certificates\Contracts\CertificateDeploymentServiceInterface;
use Modules\Certificates\Contracts\CertificateExecutorInterface;
use Modules\Certificates\Contracts\CertificateParserServiceInterface;
use Modules\Certificates\Contracts\CertificateValidatorServiceInterface;
use Modules\Certificates\Contracts\LetsEncryptAcmeServiceInterface;
use Modules\Certificates\Contracts\SelfSignedGeneratorServiceInterface;
use Modules\Certificates\Livewire\CertificateManager;
use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateDnsCredential;

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);

    $this->cert = Certificate::create([
        'name' => 'Active Web Cert',
        'type' => Certificate::TYPE_LETS_ENCRYPT,
        'common_name' => 'pbx.example.com',
        'san_domains' => ['pbx.example.com', 'sip.example.com'],
        'issuer' => 'Let\'s Encrypt Authority X3',
        'valid_from' => now()->subDays(10),
        'valid_to' => now()->addDays(80),
        'storage_identifier' => 'pbx.example.com',
        'is_default_web' => true,
        'is_default_telephony' => false,
        'auto_renew' => true,
    ]);
});

afterEach(function (): void {
    Mockery::close();
});

it('renders CertificateManager component with overview cards and inventory table', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->assertOk()
        ->assertSee('Active Web Cert')
        ->assertSee('pbx.example.com')
        ->assertSee('Web HTTPS')
        ->assertSee('80 days')
        ->assertDontSee('wire:poll');
});

it('filters certificates by search term', function (): void {
    Certificate::create([
        'name' => 'Special Secret Cert',
        'type' => Certificate::TYPE_SELF_SIGNED,
        'common_name' => 'secret.local',
        'valid_to' => now()->addDays(200),
        'storage_identifier' => 'secret.local',
    ]);

    Certificate::create([
        'name' => 'Unrelated Hidden Cert',
        'type' => Certificate::TYPE_SELF_SIGNED,
        'common_name' => 'hidden.local',
        'valid_to' => now()->addDays(150),
        'storage_identifier' => 'hidden.local',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->set('search', 'secret')
        ->assertSee('Special Secret Cert')
        ->assertDontSee('Unrelated Hidden Cert');
});

it('switches between interface tabs cleanly', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->call('switchTab', 'letsencrypt')
        ->assertSet('activeTab', 'letsencrypt')
        ->assertSee('Issue Let', false)
        ->call('switchTab', 'import')
        ->assertSet('activeTab', 'import')
        ->assertSee('Import Custom SSL/TLS Certificate')
        ->call('switchTab', 'selfsigned')
        ->assertSet('activeTab', 'selfsigned')
        ->assertSee('Generate Self-Signed Certificate')
        ->call('switchTab', 'dnsvault')
        ->assertSet('activeTab', 'dnsvault')
        ->assertSee('Stored DNS Provider Credentials')
        ->call('switchTab', 'logs')
        ->assertSet('activeTab', 'logs')
        ->assertSee('Timestamp');
});

it('issues Let\'s Encrypt certificate via Livewire form submission', function (): void {
    $mockAcme = Mockery::mock(LetsEncryptAcmeServiceInterface::class);
    $mockAcme->shouldReceive('issueHttp')
        ->once()
        ->with('New LE Cert', 'new.example.com', 'admin@example.com', false, false, false, $this->admin->id)
        ->andReturn(Certificate::create([
            'name' => 'New LE Cert',
            'type' => Certificate::TYPE_LETS_ENCRYPT,
            'common_name' => 'new.example.com',
            'valid_to' => now()->addDays(90),
            'storage_identifier' => 'new.example.com',
        ]));

    $this->app->instance(LetsEncryptAcmeServiceInterface::class, $mockAcme);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->call('switchTab', 'letsencrypt')
        ->set('le_name', 'New LE Cert')
        ->set('le_domain', 'new.example.com')
        ->set('le_email', 'admin@example.com')
        ->set('le_challenge_type', 'http')
        ->call('issueLetsEncrypt')
        ->assertHasNoErrors()
        ->assertSet('activeTab', 'inventory')
        ->assertSee('New LE Cert');
});

it('calculates live modulus match status during custom PEM input', function (): void {
    // Generate valid test keypair
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    $dn = ['commonName' => 'live.test.local'];
    $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
    $certRes = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
    openssl_x509_export($certRes, $certPem);
    openssl_pkey_export($key, $keyPem);

    // Mismatched alternative key
    $mismatchedKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($mismatchedKey, $mismatchedKeyPem);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->call('switchTab', 'import')
        ->assertSet('modulusMatches', null)
        ->set('custom_cert', $certPem)
        ->set('custom_key', $keyPem)
        ->assertSet('modulusMatches', true)
        ->assertSee('✓ Modulus Matches')
        ->set('custom_key', $mismatchedKeyPem)
        ->assertSet('modulusMatches', false)
        ->assertSee('✗ Key Modulus Mismatch');
});

it('imports custom certificate and persists it to inventory', function (): void {
    $mockValidator = Mockery::mock(CertificateValidatorServiceInterface::class);
    $mockValidator->shouldReceive('validateKeypair')->atLeast()->once()->andReturn(true);

    $mockParser = Mockery::mock(CertificateParserServiceInterface::class);
    $mockParser->shouldReceive('parse')->once()->andReturn([
        'common_name' => 'custom.example.com',
        'san_domains' => ['custom.example.com'],
        'issuer' => 'DigiCert Global Root CA',
        'valid_from' => now(),
        'valid_to' => now()->addDays(365),
        'serial_number' => '12345678',
        'fingerprint_sha256' => 'abcdef1234567890',
    ]);

    $mockExecutor = Mockery::mock(CertificateExecutorInterface::class);
    $mockExecutor->shouldReceive('status')->zeroOrMoreTimes()->andReturn(['active_web' => '', 'telephony_active' => false]);
    $mockExecutor->shouldReceive('importCustom')->once()->andReturn([
        'success' => true,
        'output' => 'Custom certificate imported',
        'error' => '',
        'exit_code' => 0,
    ]);

    $this->app->instance(CertificateValidatorServiceInterface::class, $mockValidator);
    $this->app->instance(CertificateParserServiceInterface::class, $mockParser);
    $this->app->instance(CertificateExecutorInterface::class, $mockExecutor);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->call('switchTab', 'import')
        ->set('custom_name', 'Imported Custom Cert')
        ->set('custom_cert', '-----BEGIN CERTIFICATE-----FAKE-----END CERTIFICATE-----')
        ->set('custom_key', '-----BEGIN PRIVATE KEY-----FAKE-----END PRIVATE KEY-----')
        ->call('importCustomCertificate')
        ->assertHasNoErrors()
        ->assertSet('activeTab', 'inventory');

    expect(Certificate::where('name', 'Imported Custom Cert')->exists())->toBeTrue();
});

it('generates self-signed certificate via form submission', function (): void {
    $mockGen = Mockery::mock(SelfSignedGeneratorServiceInterface::class);
    $mockGen->shouldReceive('generate')
        ->once()
        ->with('Lab PBX Cert', 'lab.local', 365, ['lab.local', '192.168.1.50'], false, false, $this->admin->id)
        ->andReturn(Certificate::create([
            'name' => 'Lab PBX Cert',
            'type' => Certificate::TYPE_SELF_SIGNED,
            'common_name' => 'lab.local',
            'valid_to' => now()->addDays(365),
            'storage_identifier' => 'self_lab_local',
        ]));

    $this->app->instance(SelfSignedGeneratorServiceInterface::class, $mockGen);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->call('switchTab', 'selfsigned')
        ->set('self_name', 'Lab PBX Cert')
        ->set('self_common_name', 'lab.local')
        ->set('self_san_domains', 'lab.local, 192.168.1.50')
        ->set('self_days', 365)
        ->call('generateSelfSigned')
        ->assertHasNoErrors()
        ->assertSet('activeTab', 'inventory');

    expect(Certificate::where('name', 'Lab PBX Cert')->exists())->toBeTrue();
});

it('creates and deletes DNS credentials in DNS Vault', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->call('switchTab', 'dnsvault')
        ->set('new_dns_name', 'Cloudflare Primary')
        ->set('new_dns_api_token', 'token_1234567890_abcdef')
        ->call('createDnsCredential')
        ->assertHasNoErrors()
        ->assertSee('Cloudflare Primary');

    $cred = CertificateDnsCredential::where('name', 'Cloudflare Primary')->first();
    expect($cred)->not->toBeNull();

    $component->call('deleteDnsCredential', $cred->id)
        ->assertDontSee('Cloudflare Primary');

    expect(CertificateDnsCredential::where('name', 'Cloudflare Primary')->exists())->toBeFalse();
});

it('manages service assignment modal and updates deployment', function (): void {
    $mockDeployer = Mockery::mock(CertificateDeploymentServiceInterface::class);
    $mockDeployer->shouldReceive('getDeploymentStatus')
        ->zeroOrMoreTimes()
        ->andReturn([
            'active_web' => '',
            'telephony_active' => false,
            'active_web_certificate' => null,
            'active_telephony_certificate' => null,
        ]);
    $mockDeployer->shouldReceive('deployTelephony')
        ->once()
        ->with(Mockery::on(fn ($c) => $c->id === $this->cert->id), $this->admin->id)
        ->andReturn(true);

    $this->app->instance(CertificateDeploymentServiceInterface::class, $mockDeployer);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->call('openAssignModal', $this->cert->id)
        ->assertSet('assigningCertificateId', $this->cert->id)
        ->assertSet('assignWeb', true)
        ->assertSet('assignTelephony', false)
        ->set('assignTelephony', true)
        ->call('saveServiceAssignment')
        ->assertSet('assigningCertificateId', null);
});

it('prevents deleting an active certificate', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->call('deleteCertificate', $this->cert->id)
        ->assertSee('Cannot delete an active certificate');

    expect($this->cert->fresh())->not->toBeNull();
});

it('deletes an inactive certificate successfully', function (): void {
    $inactive = Certificate::create([
        'name' => 'Old Inactive Cert',
        'type' => Certificate::TYPE_SELF_SIGNED,
        'common_name' => 'old.local',
        'valid_to' => now()->subDays(10),
        'storage_identifier' => 'old_inactive_cert',
        'is_default_web' => false,
        'is_default_telephony' => false,
    ]);

    $mockExecutor = Mockery::mock(CertificateExecutorInterface::class);
    $mockExecutor->shouldReceive('status')->zeroOrMoreTimes()->andReturn(['active_web' => '', 'telephony_active' => false]);
    $mockExecutor->shouldReceive('delete')->once()->with('old_inactive_cert')->andReturn([
        'success' => true,
        'output' => '',
        'error' => '',
        'exit_code' => 0,
    ]);

    $this->app->instance(CertificateExecutorInterface::class, $mockExecutor);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->assertSee('Old Inactive Cert')
        ->call('deleteCertificate', $inactive->id)
        ->assertSee('was deleted');

    expect(Certificate::find($inactive->id))->toBeNull();
});
