<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\FreeSwitchServiceInterface;
use App\Services\TenantDomainServiceInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    // Ensure the migration has been applied for the test database
});

// ─── Shared-Domain Database Constraints ──────────────────────────

it('allows the same domain across different tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantDomain::factory()->create([
        'tenant_id' => $tenantA->id,
        'domain' => 'shared.example.com',
        'purpose' => 'sip_realm',
    ]);

    // Same domain, different tenant — should succeed
    $domainB = TenantDomain::factory()->create([
        'tenant_id' => $tenantB->id,
        'domain' => 'shared.example.com',
        'purpose' => 'sip_realm',
    ]);

    expect($domainB)->not->toBeNull()
        ->and($domainB->domain)->toBe('shared.example.com');
});

it('rejects the same domain with the same purpose in the same tenant', function () {
    $tenant = Tenant::factory()->create();

    TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'dup.example.com',
        'purpose' => 'sip_realm',
    ]);

    // Same domain, same tenant, same purpose — should fail
    expect(fn () => TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'dup.example.com',
        'purpose' => 'sip_realm',
    ]))->toThrow(QueryException::class);
});

it('allows the same domain with different purposes in the same tenant', function () {
    $tenant = Tenant::factory()->create();

    TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'multi.example.com',
        'purpose' => 'sip_realm',
    ]);

    // Same domain, same tenant, different purpose — should succeed
    $domain2 = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'multi.example.com',
        'purpose' => 'web',
    ]);

    expect($domain2)->not->toBeNull()
        ->and($domain2->purpose)->toBe('web');
});

// ─── TenantDomainService — Shared-Domain Lookups ─────────────────

it('findByDomain returns the domain when exactly one tenant matches', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'unique.example.com',
        'enabled' => true,
    ]);

    $service = app(TenantDomainServiceInterface::class);
    $result = $service->findByDomain('unique.example.com');

    expect($result)->not->toBeNull()
        ->and($result->id)->toBe($domain->id);
});

it('findByDomain returns null when the domain is shared by multiple tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    TenantDomain::factory()->create([
        'tenant_id' => $tenantA->id,
        'domain' => 'shared.example.com',
        'enabled' => true,
    ]);
    TenantDomain::factory()->create([
        'tenant_id' => $tenantB->id,
        'domain' => 'shared.example.com',
        'enabled' => true,
    ]);

    $service = app(TenantDomainServiceInterface::class);
    $result = $service->findByDomain('shared.example.com');

    // Fail-closed: ambiguous when multiple tenants share the domain
    expect($result)->toBeNull();
});

it('findByDomain returns null for a disabled domain', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'disabled.example.com',
        'enabled' => false,
    ]);

    $service = app(TenantDomainServiceInterface::class);
    $result = $service->findByDomain('disabled.example.com');

    expect($result)->toBeNull();
});

it('findByDomain returns null for an unknown domain', function () {
    $service = app(TenantDomainServiceInterface::class);
    $result = $service->findByDomain('nonexistent.example.com');

    expect($result)->toBeNull();
});

it('findAllByDomain returns all enabled tenant domains matching a shared domain', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = TenantDomain::factory()->create([
        'tenant_id' => $tenantA->id,
        'domain' => 'shared.example.com',
        'enabled' => true,
    ]);
    $domainB = TenantDomain::factory()->create([
        'tenant_id' => $tenantB->id,
        'domain' => 'shared.example.com',
        'enabled' => true,
    ]);
    // Disabled domain with same name — should not appear
    $tenantC = Tenant::factory()->create();
    TenantDomain::factory()->create([
        'tenant_id' => $tenantC->id,
        'domain' => 'shared.example.com',
        'enabled' => false,
    ]);

    $service = app(TenantDomainServiceInterface::class);
    $results = $service->findAllByDomain('shared.example.com');

    expect($results)->toHaveCount(2)
        ->and($results->pluck('id')->toArray())
        ->toContain($domainA->id, $domainB->id);
});

it('findAllByDomain returns empty collection for unknown domain', function () {
    $service = app(TenantDomainServiceInterface::class);
    $results = $service->findAllByDomain('nonexistent.example.com');

    expect($results)->toBeEmpty();
});

it('invalidates the acl cache and reloads FreeSWITCH on domain writes', function (): void {
    $freeSwitch = Mockery::mock(FreeSwitchServiceInterface::class);
    $freeSwitch->shouldReceive('connect')->andReturn(true);
    $freeSwitch->shouldReceive('api')->with('reloadacl')->andReturn('+OK');
    $freeSwitch->shouldReceive('disconnect');
    app()->instance(FreeSwitchServiceInterface::class, $freeSwitch);

    Cache::put('freeswitch:acl', '<stale/>', 60);
    $domain = app(TenantDomainServiceInterface::class)->create([
        'tenant_id' => Tenant::factory()->create()->id,
        'domain' => 'pbx.example.com',
        'purpose' => 'sip_realm',
        'enabled' => true,
    ]);

    expect(Cache::has('freeswitch:acl'))->toBeFalse();

    Cache::put('freeswitch:acl', '<stale/>', 60);
    app(TenantDomainServiceInterface::class)->update($domain, ['enabled' => false]);

    expect(Cache::has('freeswitch:acl'))->toBeFalse();

    Cache::put('freeswitch:acl', '<stale/>', 60);
    app(TenantDomainServiceInterface::class)->delete($domain);

    expect(Cache::has('freeswitch:acl'))->toBeFalse();
});
