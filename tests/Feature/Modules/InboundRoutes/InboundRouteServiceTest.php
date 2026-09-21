<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DestinationResolver;
use Modules\Extensions\Models\Extension;
use Modules\InboundRoutes\Models\InboundRoute;
use Modules\InboundRoutes\Services\InboundRouteServiceInterface;

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
