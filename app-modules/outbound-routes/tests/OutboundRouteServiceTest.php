<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Modules\Gateways\Models\Gateway;
use Modules\OutboundRoutes\Models\OutboundRoute;
use Modules\OutboundRoutes\Services\OutboundRouteServiceInterface;

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

// ─── Dialplan XML Generation ──────────────────────────────────

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

    // Eager-load the gateway relation for the service query
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

    // Create a valid route so we know there's at least one record
    OutboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'dial_pattern' => '(\\d+)',
        'gateway' => 'ok-gw',
        'enabled' => true,
    ]);

    // Create one with an invalid regex that will be skipped
    OutboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'dial_pattern' => '[unclosed',
        'gateway' => 'bad-gw',
        'enabled' => true,
        'name' => 'Bad Regex',
    ]);

    $xml = $this->service->generateDialplanXml($tenant->id, 'tenant_1_public', '');

    // The bad pattern should be skipped, the good one still appears
    expect($xml)->toContain('ok-gw')
        ->not()->toContain('bad-gw');
});

it('returns null when no enabled outbound routes exist', function () {
    $tenant = Tenant::factory()->create();

    // Create disabled routes — they should be excluded
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
