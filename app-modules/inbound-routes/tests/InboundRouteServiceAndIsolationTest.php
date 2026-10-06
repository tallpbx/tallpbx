<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DestinationResolver;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Extensions\Models\Extension;
use Modules\InboundRoutes\Livewire\InboundRoutesEdit;
use Modules\InboundRoutes\Livewire\InboundRoutesList;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\InboundRoutes\Services\InboundRouteServiceInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('Service Operations', function () {
    beforeEach(function () {
        $this->service = app(InboundRouteServiceInterface::class);
    });

    it('creates an inbound route', function () {
        $tenant = Tenant::factory()->create();

        $route = $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Main DID',
            'destination_number' => '+14155551212',
            'action' => 'transfer',
            'action_data' => '1000',
            'priority' => 100,
            'enabled' => true,
        ]);

        expect($route)
            ->toBeInstanceOf(InboundRoute::class)
            ->name->toBe('Main DID')
            ->destination_number->toBe('+14155551212')
            ->action->toBe('transfer')
            ->enabled->toBeTrue();
    });

    it('updates an inbound route', function () {
        $route = InboundRoute::factory()->create(['name' => 'Old Name']);

        $updated = $this->service->update($route, [
            'name' => 'Updated Name',
            'priority' => 50,
        ]);

        expect($updated->name)->toBe('Updated Name')
            ->and($updated->priority)->toBe(50);
    });

    it('deletes an inbound route', function () {
        $route = InboundRoute::factory()->create();

        $this->service->delete($route);

        $this->assertModelMissing($route);
    });

    it('retrieves inbound routes scoped by tenant', function () {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        InboundRoute::factory()->count(3)->create([
            'tenant_id' => $tenantA->id,
        ]);
        InboundRoute::factory()->count(2)->create([
            'tenant_id' => $tenantB->id,
        ]);

        $routesForA = $this->service->getByTenant($tenantA->id);
        $routesForB = $this->service->getByTenant($tenantB->id);

        expect($routesForA)->toHaveCount(3)
            ->and($routesForB)->toHaveCount(2);
    });

    it('returns routes ordered by priority', function () {
        $tenant = Tenant::factory()->create();

        InboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Second',
            'priority' => 50,
        ]);
        InboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'First',
            'priority' => 10,
        ]);

        $routes = $this->service->getByTenant($tenant->id);

        expect($routes->first()->name)->toBe('First')
            ->and($routes->last()->name)->toBe('Second');
    });

    it('resolves typed extension destinations when generating dialplan XML', function () {
        $tenant = Tenant::factory()->create();
        Extension::factory()->create([
            'tenant_id' => $tenant->id,
            'extension_number' => '2000',
            'enabled' => true,
        ]);

        InboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Support DID',
            'destination_number' => '8005551212',
            'action' => DestinationResolver::KIND_EXTENSION,
            'action_data' => '2000',
            'enabled' => true,
        ]);

        $xml = $this->service->generateDialplanXml($tenant->id, "tenant_{$tenant->id}_public", '8005551212');

        expect($xml)
            ->toContain('application="bridge"')
            ->toContain('data="user/2000"');
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

    it('denies tenant user access to another tenant inbound route on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['inbound-routes.view', 'inbound-routes.edit']);
        $foreignRoute = InboundRoute::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Route',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(InboundRoutesEdit::class, ['routeId' => $foreignRoute->id])
            ->assertForbidden();
    });

    it('allows tenant user access to their own tenant inbound route on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['inbound-routes.view', 'inbound-routes.edit']);
        $ownRoute = InboundRoute::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Tenant A Route',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(InboundRoutesEdit::class, ['routeId' => $ownRoute->id])
            ->assertOk()
            ->assertSet('name', 'Tenant A Route')
            ->assertSet('routeId', $ownRoute->id);
    });

    it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['inbound-routes.create']);

        $this->actingAs($userA, 'web');

        expect(function () {
            InboundRoute::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Spoofed Route',
                'destination_number' => '+15551239999',
                'action' => 'transfer',
                'action_data' => '1000',
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect(InboundRoute::withoutGlobalScope('tenant')->where('name', 'Spoofed Route')->exists())->toBeFalse();
    });

    it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['inbound-routes.edit']);
        $foreignRoute = InboundRoute::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Original Route',
        ]);

        $this->actingAs($userA, 'web');

        expect(function () use ($foreignRoute) {
            $foreignRoute->update([
                'name' => 'Hacked Route',
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect($foreignRoute->fresh()->name)->toBe('Original Route');
    });

    it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['inbound-routes.view', 'inbound-routes.delete']);
        $foreignRoute = InboundRoute::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Foreign Route',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(InboundRoutesList::class)
            ->call('deleteRoute', $foreignRoute->id)
            ->assertForbidden();

        expect(InboundRoute::withoutGlobalScope('tenant')->where('id', $foreignRoute->id)->exists())->toBeTrue();
    });

    it('allows two tenants to have inbound routes with the same destination number without collision', function () {
        $routeA = InboundRoute::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'destination_number' => '+18005550199',
            'name' => 'Toll Free Line A',
        ]);

        $routeB = InboundRoute::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'destination_number' => '+18005550199',
            'name' => 'Toll Free Line B',
        ]);

        expect($routeA->id)->not->toBe($routeB->id)
            ->and($routeA->destination_number)->toBe($routeB->destination_number)
            ->and($routeA->tenant_id)->toBe($this->tenantA->id)
            ->and($routeB->tenant_id)->toBe($this->tenantB->id);
    });
});
