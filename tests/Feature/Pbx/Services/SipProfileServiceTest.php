<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\SipProfiles\Models\SipProfile;
use Modules\SipProfiles\Services\SipProfileServiceInterface;

beforeEach(function () {
    $this->service = app(SipProfileServiceInterface::class);
});

it('creates a sip profile', function () {
    $tenant = Tenant::factory()->create();

    $profile = $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Internal',
        'description' => 'Internal SIP profile for local extensions',
        'settings' => ['sip-port' => 5060, 'sip-ip' => '$${local_ip_v4}'],
    ]);

    expect($profile)
        ->toBeInstanceOf(SipProfile::class)
        ->name->toBe('Internal')
        ->description->toBe('Internal SIP profile for local extensions')
        ->settings->toBe(['sip-port' => 5060, 'sip-ip' => '$${local_ip_v4}'])
        ->enabled->toBeTrue();
});

it('updates a sip profile', function () {
    $profile = SipProfile::factory()->create([
        'name' => 'Old Name',
        'settings' => ['old' => 'value'],
    ]);

    $updated = $this->service->update($profile, [
        'name' => 'New Name',
        'description' => 'Updated description',
        'settings' => ['sip-port' => 5080],
    ]);

    expect($updated->name)->toBe('New Name')
        ->and($updated->description)->toBe('Updated description')
        ->and($updated->settings)->toBe(['sip-port' => 5080]);
});

it('deletes a sip profile', function () {
    $profile = SipProfile::factory()->create();

    $this->service->delete($profile);

    $this->assertModelMissing($profile);
});

it('returns profiles scoped by tenant', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    SipProfile::factory()->forTenant($tenant1->id)->count(2)->create();
    SipProfile::factory()->forTenant($tenant2->id)->count(3)->create();

    $profiles = $this->service->getByTenant($tenant1->id);

    expect($profiles)->toHaveCount(2);
});

it('can enable and disable a profile', function () {
    $profile = SipProfile::factory()->create(['enabled' => true]);

    $this->service->disable($profile);
    expect($profile->fresh()->enabled)->toBeFalse();

    $this->service->enable($profile);
    expect($profile->fresh()->enabled)->toBeTrue();
});

it('generates xml config for a profile', function () {
    $profile = SipProfile::factory()->create([
        'name' => 'internal',
        'settings' => [
            'sip-port' => 5060,
            'sip-ip' => '10.0.0.1',
            'rtp-ip' => '10.0.0.1',
            'codecs' => ['G722', 'PCMU', 'PCMA'],
        ],
    ]);

    $xml = $this->service->generateConfig($profile);

    expect($xml)->toContain('internal')
        ->toContain('<param name="sip-port" value="5060"/>')
        ->toContain('<param name="sip-ip" value="10.0.0.1"/>')
        ->toContain('<param name="rtp-ip" value="10.0.0.1"/>')
        ->toContain('<param name="codecs" value="G722,PCMU,PCMA"/>');
});
