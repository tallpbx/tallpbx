<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx\Services;

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Modules\IvrMenus\Models\IvrMenu;
use Modules\IvrMenus\Services\IvrMenuServiceInterface;
use Modules\SipProfiles\Models\SipProfile;
use Modules\SipProfiles\Services\SipProfileServiceInterface;

/*
|--------------------------------------------------------------------------
| IVR Menu Service Tests
|--------------------------------------------------------------------------
*/

describe('ivr menu service', function (): void {
    beforeEach(function (): void {
        $this->service = app(IvrMenuServiceInterface::class);
    });

    it('creates an IVR menu', function (): void {
        $tenant = Tenant::factory()->create();

        $menu = $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main IVR',
            'greeting' => 'Welcome to our company',
            'timeout' => 15,
            'max_failures' => 5,
            'enabled' => true,
        ]);

        expect($menu)
            ->toBeInstanceOf(IvrMenu::class)
            ->name->toBe('Main IVR')
            ->greeting->toBe('Welcome to our company')
            ->timeout->toBe(15)
            ->max_failures->toBe(5)
            ->enabled->toBeTrue();
    });

    it('updates an IVR menu', function (): void {
        $menu = IvrMenu::factory()->create([
            'name' => 'Old Name',
            'timeout' => 10,
        ]);

        $updated = $this->service->update($menu, [
            'name' => 'Updated IVR',
            'timeout' => 20,
        ]);

        expect($updated->name)->toBe('Updated IVR')
            ->and($updated->timeout)->toBe(20);
    });

    it('deletes an IVR menu', function (): void {
        $menu = IvrMenu::factory()->create();

        $this->service->delete($menu);

        $this->assertModelMissing($menu);
    });

    it('fetches IVR menus by tenant', function (): void {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        IvrMenu::factory()->forTenant($tenant1->id)->count(3)->create();
        IvrMenu::factory()->forTenant($tenant2->id)->count(2)->create();

        $tenant1Menus = $this->service->getByTenant($tenant1->id);
        $tenant2Menus = $this->service->getByTenant($tenant2->id);

        expect($tenant1Menus)->toHaveCount(3)
            ->and($tenant2Menus)->toHaveCount(2);
    });

    it('enforces unique name per tenant', function (): void {
        $tenant = Tenant::factory()->create();

        $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Duplicate IVR',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Duplicate IVR',
        ]);
    });

    it('allows same name in different tenants', function (): void {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        $menu1 = $this->service->create([
            'tenant_id' => $tenant1->id,
            'name' => 'Sales IVR',
        ]);

        $menu2 = $this->service->create([
            'tenant_id' => $tenant2->id,
            'name' => 'Sales IVR',
        ]);

        expect($menu1->id)->not->toBe($menu2->id);
    });
});

/*
|--------------------------------------------------------------------------
| SIP Profile Service Tests
|--------------------------------------------------------------------------
*/

describe('sip profile service', function (): void {
    beforeEach(function (): void {
        $this->service = app(SipProfileServiceInterface::class);
    });

    it('creates a sip profile', function (): void {
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

    it('updates a sip profile', function (): void {
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

    it('deletes a sip profile', function (): void {
        $profile = SipProfile::factory()->create();

        $this->service->delete($profile);

        $this->assertModelMissing($profile);
    });

    it('returns profiles scoped by tenant', function (): void {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        SipProfile::factory()->forTenant($tenant1->id)->count(2)->create();
        SipProfile::factory()->forTenant($tenant2->id)->count(3)->create();

        $profiles = $this->service->getByTenant($tenant1->id);

        expect($profiles)->toHaveCount(2);
    });

    it('can enable and disable a profile', function (): void {
        $profile = SipProfile::factory()->create(['enabled' => true]);

        $this->service->disable($profile);
        expect($profile->fresh()->enabled)->toBeFalse();

        $this->service->enable($profile);
        expect($profile->fresh()->enabled)->toBeTrue();
    });

    it('generates xml config for a profile', function (): void {
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
});
