<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\OutboundRoutes\Livewire\OutboundRoutesEdit;
use Modules\OutboundRoutes\Livewire\OutboundRoutesList;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
