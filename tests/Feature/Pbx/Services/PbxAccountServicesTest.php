<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx\Services;

use App\Models\Tenant;
use App\Models\TenantDomain;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Acl\Models\AccessControl;
use Modules\Acl\Services\AccessControlServiceInterface;
use Modules\Devices\Models\Device;
use Modules\Devices\Services\DeviceServiceInterface;
use Modules\SipAccounts\Models\SipAccount;
use Modules\SipAccounts\Services\SipAccountServiceInterface;

/*
|--------------------------------------------------------------------------
| SIP Account Service Tests
|--------------------------------------------------------------------------
*/

describe('sip account service', function (): void {
    beforeEach(function (): void {
        $this->service = app(SipAccountServiceInterface::class);
    });

    it('creates a sip account in global username mode', function (): void {
        $tenant = Tenant::factory()->create();

        $account = $this->service->create([
            'tenant_id' => $tenant->id,
            'identity_mode' => 'global_username',
            'auth_username' => 'a_7f3k_999',
            'auth_password' => 'secret123',
            'enabled' => true,
        ]);

        expect($account)
            ->toBeInstanceOf(SipAccount::class)
            ->identity_mode->toBe('global_username')
            ->auth_username->toBe('a_7f3k_999')
            ->global_auth_key->toBe('global:a_7f3k_999')
            ->enabled->toBeTrue();
    });

    it('creates a sip account in domain username mode', function (): void {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        $account = $this->service->create([
            'tenant_id' => $tenant->id,
            'tenant_domain_id' => $domain->id,
            'identity_mode' => 'domain_username',
            'auth_username' => '101',
            'auth_password' => 'secret456',
            'enabled' => true,
        ]);

        expect($account->global_auth_key)->toBe("domain:{$domain->id}:101");
    });

    it('creates a sip account in hybrid mode', function (): void {
        $tenant = Tenant::factory()->create();
        $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

        $account = $this->service->create([
            'tenant_id' => $tenant->id,
            'tenant_domain_id' => $domain->id,
            'identity_mode' => 'hybrid',
            'auth_username' => 'h_abc_101',
            'auth_password' => 'secret789',
            'enabled' => true,
        ]);

        expect($account->global_auth_key)->toBe("hybrid:{$domain->id}:h_abc_101");
    });

    it('updates a sip account', function (): void {
        $account = SipAccount::factory()->create([
            'auth_username' => 'old_user',
        ]);

        $updated = $this->service->update($account, [
            'auth_username' => 'new_user',
            'auth_password' => 'new_pass',
        ]);

        expect($updated->auth_username)->toBe('new_user');
    });

    it('deletes a sip account', function (): void {
        $account = SipAccount::factory()->create();

        $this->service->delete($account);

        $this->assertModelMissing($account);
    });

    it('enforces global uniqueness for global username mode', function (): void {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        $this->service->create([
            'tenant_id' => $tenant1->id,
            'identity_mode' => 'global_username',
            'auth_username' => 'unique_user',
            'auth_password' => 'pass1',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'tenant_id' => $tenant2->id,
            'identity_mode' => 'global_username',
            'auth_username' => 'unique_user',
            'auth_password' => 'pass2',
        ]);
    });

    it('allows same username in different domains for domain username mode', function (): void {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();
        $domain1 = TenantDomain::factory()->create(['tenant_id' => $tenant1->id]);
        $domain2 = TenantDomain::factory()->create(['tenant_id' => $tenant2->id]);

        $account1 = $this->service->create([
            'tenant_id' => $tenant1->id,
            'tenant_domain_id' => $domain1->id,
            'identity_mode' => 'domain_username',
            'auth_username' => '101',
            'auth_password' => 'pass1',
        ]);

        $account2 = $this->service->create([
            'tenant_id' => $tenant2->id,
            'tenant_domain_id' => $domain2->id,
            'identity_mode' => 'domain_username',
            'auth_username' => '101',
            'auth_password' => 'pass2',
        ]);

        expect($account1->id)->not->toBe($account2->id)
            ->and($account1->auth_username)->toBe($account2->auth_username);
    });

    it('requires domain_id for domain username mode', function (): void {
        $tenant = Tenant::factory()->create();

        $this->expectException(InvalidArgumentException::class);

        $this->service->create([
            'tenant_id' => $tenant->id,
            'identity_mode' => 'domain_username',
            'auth_username' => '101',
            'auth_password' => 'pass1',
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Device Service Tests
|--------------------------------------------------------------------------
*/

describe('device service', function (): void {
    beforeEach(function (): void {
        $this->service = app(DeviceServiceInterface::class);
    });

    it('creates a device', function (): void {
        $tenant = Tenant::factory()->create();

        $device = $this->service->create([
            'tenant_id' => $tenant->id,
            'vendor' => 'Polycom',
            'model' => 'VVX 450',
            'mac_address' => '00:11:22:33:44:55',
            'template' => 'polycom_vvx',
            'enabled' => true,
        ]);

        expect($device)
            ->toBeInstanceOf(Device::class)
            ->vendor->toBe('Polycom')
            ->model->toBe('VVX 450')
            ->mac_address->toBe('00:11:22:33:44:55')
            ->template->toBe('polycom_vvx');
    });

    it('updates a device', function (): void {
        $device = Device::factory()->create(['vendor' => 'Yealink']);

        $updated = $this->service->update($device, [
            'vendor' => 'Polycom',
            'model' => 'VVX 450',
        ]);

        expect($updated->vendor)->toBe('Polycom')
            ->and($updated->model)->toBe('VVX 450');
    });

    it('deletes a device', function (): void {
        $device = Device::factory()->create();

        $this->service->delete($device);

        $this->assertModelMissing($device);
    });

    it('enforces unique mac address', function (): void {
        $tenant = Tenant::factory()->create();

        $this->service->create([
            'tenant_id' => $tenant->id,
            'vendor' => 'Polycom',
            'mac_address' => '00:11:22:33:44:55',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'tenant_id' => $tenant->id,
            'vendor' => 'Cisco',
            'mac_address' => '00:11:22:33:44:55',
        ]);
    });

    it('normalizes mac address on save', function (): void {
        $tenant = Tenant::factory()->create();

        $device = $this->service->create([
            'tenant_id' => $tenant->id,
            'vendor' => 'Polycom',
            'mac_address' => '00-11-22-33-44-55',
        ]);

        expect($device->mac_address)->toBe('00:11:22:33:44:55');
    });

    it('returns devices scoped by tenant', function (): void {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        Device::factory()->forTenant($tenant1->id)->count(2)->create();
        Device::factory()->forTenant($tenant2->id)->count(1)->create();

        expect($this->service->getByTenant($tenant1->id))->toHaveCount(2);
    });
});

/*
|--------------------------------------------------------------------------
| Access Control Service Tests
|--------------------------------------------------------------------------
*/

describe('access control service', function (): void {
    beforeEach(function (): void {
        $this->service = app(AccessControlServiceInterface::class);
    });

    it('creates an access control rule', function (): void {
        $tenant = Tenant::factory()->create();

        $rule = $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Allow LAN',
            'description' => 'Allow all LAN traffic',
            'action' => 'allow',
            'enabled' => true,
        ]);

        expect($rule)
            ->toBeInstanceOf(AccessControl::class)
            ->name->toBe('Allow LAN')
            ->action->toBe('allow')
            ->enabled->toBeTrue();
    });

    it('creates an access control with nodes', function (): void {
        $tenant = Tenant::factory()->create();

        $rule = $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Allow Office',
            'action' => 'allow',
            'nodes' => [
                ['type' => 'cidr', 'value' => '10.0.0.0/8'],
                ['type' => 'cidr', 'value' => '192.168.0.0/16'],
            ],
        ]);

        expect($rule->nodes)->toHaveCount(2)
            ->and($rule->nodes->first()->value)->toBe('10.0.0.0/8');
    });

    it('updates an access control', function (): void {
        $rule = AccessControl::factory()->create(['name' => 'Old']);

        $updated = $this->service->update($rule, [
            'name' => 'Updated Rule',
            'action' => 'deny',
        ]);

        expect($updated->name)->toBe('Updated Rule')
            ->and($updated->action)->toBe('deny');
    });

    it('deletes an access control with its nodes', function (): void {
        $rule = AccessControl::factory()->hasNodes(2)->create();

        $this->service->delete($rule);

        $this->assertModelMissing($rule);
        $this->assertDatabaseMissing('access_control_nodes', [
            'access_control_id' => $rule->id,
        ]);
    });

    it('enforces unique name per tenant', function (): void {
        $tenant = Tenant::factory()->create();

        $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Allow LAN',
            'action' => 'allow',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Allow LAN',
            'action' => 'deny',
        ]);
    });
});
