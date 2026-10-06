<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Modules\Gateways\Models\Gateway;
use Modules\OutboundRoutes\Livewire\OutboundRoutesEdit;
use Modules\OutboundRoutes\Livewire\OutboundRoutesList;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Modules\OutboundRoutes\Services\OutboundRouteServiceInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('Service Operations', function () {
    beforeEach(function () {
        $this->service = app(OutboundRouteServiceInterface::class);
    });

    it('creates an outbound route', function () {
        $tenant = Tenant::factory()->create();

        $route = $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Default Outbound',
            'dial_pattern' => '^(\\d{10})$',
            'gateway' => 'sip-provider',
            'priority' => 100,
            'enabled' => true,
        ]);

        expect($route)
            ->toBeInstanceOf(OutboundRoute::class)
            ->name->toBe('Default Outbound')
            ->dial_pattern->toBe('^(\\d{10})$')
            ->gateway->toBe('sip-provider')
            ->enabled->toBeTrue();
    });

    it('updates an outbound route', function () {
        $route = OutboundRoute::factory()->create(['name' => 'Old Name']);

        $updated = $this->service->update($route, [
            'name' => 'Updated Name',
            'priority' => 50,
        ]);

        expect($updated->name)->toBe('Updated Name')
            ->and($updated->priority)->toBe(50);
    });

    it('deletes an outbound route', function () {
        $route = OutboundRoute::factory()->create();

        $this->service->delete($route);

        $this->assertModelMissing($route);
    });

    it('retrieves outbound routes scoped by tenant', function () {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        OutboundRoute::factory()->count(3)->create([
            'tenant_id' => $tenantA->id,
        ]);
        OutboundRoute::factory()->count(2)->create([
            'tenant_id' => $tenantB->id,
        ]);

        $routesForA = $this->service->getByTenant($tenantA->id);
        $routesForB = $this->service->getByTenant($tenantB->id);

        expect($routesForA)->toHaveCount(3)
            ->and($routesForB)->toHaveCount(2);
    });

    it('returns routes ordered by priority', function () {
        $tenant = Tenant::factory()->create();

        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Second',
            'priority' => 50,
        ]);
        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'First',
            'priority' => 10,
        ]);

        $routes = $this->service->getByTenant($tenant->id);

        expect($routes->first()->name)->toBe('First')
            ->and($routes->last()->name)->toBe('Second');
    });

    it('generates dialplan XML using gateway UUID when gateway_id is set', function () {
        $tenant = Tenant::factory()->create();
        $gateway = Gateway::factory()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
        ]);

        $route = OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'dial_pattern' => '(\\d{10})',
            'gateway_id' => $gateway->id,
            'gateway' => null,
            'enabled' => true,
        ]);

        $xml = $this->service->generateDialplanXml($tenant->id, 'tenant_1_public', '');

        expect($xml)->toContain('sofia/gateway/'.$gateway->id);
    });

    it('generates dialplan XML using legacy gateway string when gateway_id is null', function () {
        Log::spy();

        $tenant = Tenant::factory()->create();

        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'dial_pattern' => '(\\d{10})',
            'gateway' => 'legacy-provider',
            'gateway_id' => null,
            'enabled' => true,
        ]);

        $xml = $this->service->generateDialplanXml($tenant->id, 'tenant_1_public', '');

        expect($xml)->toContain('sofia/gateway/legacy-provider');
    });

    it('falls back to sofia/external/$1 when no gateway is configured', function () {
        $tenant = Tenant::factory()->create();

        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'dial_pattern' => '(\\d{10})',
            'gateway' => null,
            'gateway_id' => null,
            'enabled' => true,
        ]);

        $xml = $this->service->generateDialplanXml($tenant->id, 'tenant_1_public', '');

        expect($xml)->toContain('sofia/external/\$1');
    });

    it('skips outbound routes whose bridge substitution has no capture group', function () {
        Log::spy();

        $tenant = Tenant::factory()->create();

        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'dial_pattern' => '^\\d{10}$',
            'gateway' => 'legacy-provider',
            'enabled' => true,
        ]);

        $xml = $this->service->generateDialplanXml($tenant->id, 'tenant_1_public', '');

        expect($xml)->toBe('');
    });

    it('skips outbound routes with invalid dial_pattern regex', function () {
        Log::spy();

        $tenant = Tenant::factory()->create();

        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'dial_pattern' => '(\\d+)',
            'gateway' => 'ok-gw',
            'enabled' => true,
        ]);

        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'dial_pattern' => '[unclosed',
            'gateway' => 'bad-gw',
            'enabled' => true,
            'name' => 'Bad Regex',
        ]);

        $xml = $this->service->generateDialplanXml($tenant->id, 'tenant_1_public', '');

        expect($xml)->toContain('ok-gw')
            ->not()->toContain('bad-gw');
    });

    it('returns null when no enabled outbound routes exist', function () {
        $tenant = Tenant::factory()->create();

        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'dial_pattern' => '(\\d{10})',
            'enabled' => false,
        ]);

        $xml = $this->service->generateDialplanXml($tenant->id, 'tenant_1_public', '');

        expect($xml)->toBeNull();
    });

    it('excludes disabled gateways from bridge data', function () {
        $tenant = Tenant::factory()->create();
        $gateway = Gateway::factory()->create([
            'tenant_id' => $tenant->id,
            'enabled' => false,
        ]);

        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'dial_pattern' => '(\\d{10})',
            'gateway_id' => $gateway->id,
            'gateway' => null,
            'enabled' => true,
        ]);

        $xml = $this->service->generateDialplanXml($tenant->id, 'tenant_1_public', '');

        expect($xml)->toBe('');
    });

    it('excludes gateways from other tenants from bridge data', function () {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $gateway = Gateway::factory()->create([
            'tenant_id' => $otherTenant->id,
            'enabled' => true,
        ]);

        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'dial_pattern' => '(\\d{10})',
            'gateway_id' => $gateway->id,
            'gateway' => null,
            'enabled' => true,
        ]);

        $xml = $this->service->generateDialplanXml($tenant->id, 'tenant_1_public', '');

        expect($xml)->toBe('');
    });
});

describe('Tenant Isolation & Boundaries', function () {
    beforeEach(function () {
        $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
    });

    afterEach(function () {
        app(TenantManager::class)->setTenantId(null);
    });

    it('denies tenant user access to another tenant outbound route on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['outbound-routes.view', 'outbound-routes.edit']);
        $foreignRoute = OutboundRoute::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Outbound Route',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(OutboundRoutesEdit::class, ['routeId' => $foreignRoute->id])
            ->assertForbidden();
    });

    it('allows tenant user access to their own tenant outbound route on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['outbound-routes.view', 'outbound-routes.edit']);
        $ownRoute = OutboundRoute::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Tenant A Outbound Route',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(OutboundRoutesEdit::class, ['routeId' => $ownRoute->id])
            ->assertOk()
            ->assertSet('name', 'Tenant A Outbound Route')
            ->assertSet('routeId', $ownRoute->id);
    });

    it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['outbound-routes.create']);

        $this->actingAs($userA, 'web');

        expect(function () {
            OutboundRoute::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Spoofed Outbound Route',
                'dial_pattern' => '^(\d{10})$',
                'gateway' => 'external',
                'priority' => 100,
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect(OutboundRoute::withoutGlobalScope('tenant')->where('name', 'Spoofed Outbound Route')->exists())->toBeFalse();
    });

    it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['outbound-routes.edit']);
        $foreignRoute = OutboundRoute::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Original Outbound Route',
        ]);

        $this->actingAs($userA, 'web');

        expect(function () use ($foreignRoute) {
            $foreignRoute->update([
                'name' => 'Hacked Route',
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect($foreignRoute->fresh()->name)->toBe('Original Outbound Route');
    });

    it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['outbound-routes.view', 'outbound-routes.delete']);
        $foreignRoute = OutboundRoute::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Foreign Outbound Route',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(OutboundRoutesList::class)
            ->call('deleteRoute', $foreignRoute->id)
            ->assertForbidden();

        expect(OutboundRoute::withoutGlobalScope('tenant')->where('id', $foreignRoute->id)->exists())->toBeTrue();
    });

    it('allows two tenants to have outbound routes with the same dial pattern without collision', function () {
        $routeA = OutboundRoute::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'dial_pattern' => '^(\d{11})$',
            'name' => 'US Long Distance A',
        ]);

        $routeB = OutboundRoute::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'dial_pattern' => '^(\d{11})$',
            'name' => 'US Long Distance B',
        ]);

        expect($routeA->id)->not->toBe($routeB->id)
            ->and($routeA->dial_pattern)->toBe($routeB->dial_pattern)
            ->and($routeA->tenant_id)->toBe($this->tenantA->id)
            ->and($routeB->tenant_id)->toBe($this->tenantB->id);
    });
});
