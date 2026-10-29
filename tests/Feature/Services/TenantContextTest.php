<?php

declare(strict_types=1);

use App\Exceptions\TenantContextMissingException;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantManager;

beforeEach(function () {
    app(TenantManager::class)->clear();
    session()->forget('selected_tenant_id');
});

// ─── TenantContext::current() — Read Without Mutation Side Effects ──

it('returns null when no session, no auth, and multiple tenants exist', function () {
    Tenant::factory()->count(2)->create();

    $context = app(TenantContext::class);
    $tenant = $context->current();

    expect($tenant)->toBeNull();
});

it('returns null when no tenants exist', function () {
    $context = app(TenantContext::class);
    $tenant = $context->current();

    expect($tenant)->toBeNull();
});

it('returns the session-stored tenant without mutating TenantManager', function () {
    $tenant = Tenant::factory()->create();
    session()->put('selected_tenant_id', $tenant->id);

    $context = app(TenantContext::class);
    $result = $context->current();

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenant->id)
        ->and(app(TenantManager::class)->getTenantId())->toBeNull();
});

it('returns authenticated users primary tenant without mutating TenantManager or session', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['primary' => true]);
    $this->actingAs($user, 'web');

    $context = app(TenantContext::class);
    $result = $context->current();

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenant->id)
        ->and(app(TenantManager::class)->getTenantId())->toBeNull()
        ->and(session()->has('selected_tenant_id'))->toBeFalse();
});

it('returns the first tenant when authenticated user has no primary', function () {
    $tenantA = Tenant::factory()->create(['name' => 'Alpha']);
    $tenantB = Tenant::factory()->create(['name' => 'Beta']);
    $user = User::factory()->create();
    $user->tenants()->attach([$tenantA->id, $tenantB->id]);
    $this->actingAs($user, 'web');

    $context = app(TenantContext::class);
    $result = $context->current();

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenantA->id)
        ->and(app(TenantManager::class)->getTenantId())->toBeNull();
});

it('falls back to single tenant without mutating TenantManager', function () {
    $tenant = Tenant::factory()->create();

    $context = app(TenantContext::class);
    $result = $context->current();

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenant->id)
        ->and(app(TenantManager::class)->getTenantId())->toBeNull();
});

it('returns applied tenant when TenantManager is already configured', function () {
    $tenantA = Tenant::factory()->create(['name' => 'Alpha']);
    $tenantB = Tenant::factory()->create(['name' => 'Beta']);

    app(TenantManager::class)->setTenantId((string) $tenantB->id);

    $context = app(TenantContext::class);
    $result = $context->current();

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenantB->id);
});

// ─── Resolution order ───────────────────────────────────────────

it('prefers session tenant over authenticated users tenant', function () {
    $tenantA = Tenant::factory()->create(['name' => 'Session Tenant']);
    $tenantB = Tenant::factory()->create(['name' => 'User Tenant']);
    $user = User::factory()->create();
    $user->tenants()->attach($tenantB, ['primary' => true]);

    session()->put('selected_tenant_id', $tenantA->id);

    $this->actingAs($user, 'web');

    $context = app(TenantContext::class);
    $result = $context->current();

    expect((string) $result->id)->toBe((string) $tenantA->id);
});

it('prefers authenticated users tenant over single-tenant fallback', function () {
    $onlyTenant = Tenant::factory()->create(['name' => 'Only Tenant']);
    $user = User::factory()->create();
    $user->tenants()->attach($onlyTenant, ['primary' => true]);

    $this->actingAs($user, 'web');

    $context = app(TenantContext::class);
    $result = $context->current();

    expect((string) $result->id)->toBe((string) $onlyTenant->id);
});

// ─── TenantContext::apply() ──────────────────────────────────────

it('apply sets TenantManager and persists to session', function () {
    $tenant = Tenant::factory()->create();

    $context = app(TenantContext::class);
    $context->apply($tenant);

    expect(app(TenantManager::class)->getTenantId())->toBe((string) $tenant->id)
        ->and(session()->get('selected_tenant_id'))->toBe((string) $tenant->id);
});

it('apply with null clears TenantManager and session', function () {
    $tenant = Tenant::factory()->create();

    $context = app(TenantContext::class);
    $context->apply($tenant);

    expect(app(TenantManager::class)->hasTenant())->toBeTrue()
        ->and(session()->has('selected_tenant_id'))->toBeTrue();

    $context->apply(null);

    expect(app(TenantManager::class)->hasTenant())->toBeFalse()
        ->and(session()->has('selected_tenant_id'))->toBeFalse();
});

// ─── TenantContext::switch() ─────────────────────────────────────

it('switch sets tenant context and persists to session', function () {
    $tenant = Tenant::factory()->create();

    $context = app(TenantContext::class);
    $context->switch($tenant);

    expect(app(TenantManager::class)->getTenantId())->toBe((string) $tenant->id)
        ->and(session()->get('selected_tenant_id'))->toBe((string) $tenant->id);
});

it('switch overwrites previous session tenant', function () {
    $tenantA = Tenant::factory()->create(['name' => 'Old']);
    $tenantB = Tenant::factory()->create(['name' => 'New']);

    session()->put('selected_tenant_id', $tenantA->id);

    $context = app(TenantContext::class);
    $context->switch($tenantB);

    expect(session()->get('selected_tenant_id'))->toBe((string) $tenantB->id)
        ->and(app(TenantManager::class)->getTenantId())->toBe((string) $tenantB->id);
});

// ─── TenantContext::resolve() ────────────────────────────────────

it('resolve with no arguments resolves candidate tenant without mutating state', function () {
    $tenant = Tenant::factory()->create();
    session()->put('selected_tenant_id', $tenant->id);

    $context = app(TenantContext::class);
    $result = $context->resolve();

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenant->id)
        ->and(app(TenantManager::class)->getTenantId())->toBeNull();
});

it('resolve returns users primary tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['primary' => true]);

    $context = app(TenantContext::class);
    $result = $context->resolve($user);

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenant->id);
});

it('resolve returns first tenant when user has no primary', function () {
    $tenantA = Tenant::factory()->create(['name' => 'First']);
    $tenantB = Tenant::factory()->create(['name' => 'Second']);
    $user = User::factory()->create();
    $user->tenants()->attach([$tenantA->id, $tenantB->id]);

    $context = app(TenantContext::class);
    $result = $context->resolve($user);

    expect((string) $result->id)->toBe((string) $tenantA->id);
});

it('resolve returns null when user has no tenants and multiple tenants exist', function () {
    Tenant::factory()->count(2)->create();
    $user = User::factory()->create();

    $context = app(TenantContext::class);
    $result = $context->resolve($user);

    expect($result)->toBeNull();
});

it('resolve returns single tenant when only one exists even if user is unattached', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    $context = app(TenantContext::class);
    $result = $context->resolve($user);

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenant->id);
});

// ─── TenantContext::resolve(allowSingleTenantFallback: false) & resolveStrict() ───

it('resolve with allowSingleTenantFallback false returns null when single tenant exists and no context', function () {
    Tenant::factory()->create();

    $context = app(TenantContext::class);
    $result = $context->resolve(allowSingleTenantFallback: false);

    expect($result)->toBeNull();
});

it('resolve with allowSingleTenantFallback false returns null when user is unattached and single tenant exists', function () {
    Tenant::factory()->create();
    $user = User::factory()->create();

    $context = app(TenantContext::class);
    $result = $context->resolve($user, allowSingleTenantFallback: false);

    expect($result)->toBeNull();
});

it('resolveStrict returns null when single tenant exists and no context', function () {
    Tenant::factory()->create();

    $context = app(TenantContext::class);
    $result = $context->resolveStrict();

    expect($result)->toBeNull();
});

it('resolveStrict with unattached user returns null when single tenant exists', function () {
    Tenant::factory()->create();
    $user = User::factory()->create();

    $context = app(TenantContext::class);
    $result = $context->resolveStrict($user);

    expect($result)->toBeNull();
});

it('resolveStrict returns user tenant when user belongs to a tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['primary' => true]);

    $context = app(TenantContext::class);
    $result = $context->resolveStrict($user);

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenant->id);
});

// ─── TenantContext::assertResolved() ─────────────────────────────

it('assertResolved throws TenantContextMissingException when TenantManager has no tenant ID', function () {
    expect(app(TenantManager::class)->getTenantId())->toBeNull();

    $context = app(TenantContext::class);

    expect(fn () => $context->assertResolved())
        ->toThrow(TenantContextMissingException::class, 'No active tenant context is configured.');
});

it('assertResolved throws TenantContextMissingException when TenantManager has an invalid or disabled tenant ID', function () {
    $disabledTenant = Tenant::factory()->create(['enabled' => false]);
    app(TenantManager::class)->setTenantId((string) $disabledTenant->id);

    $context = app(TenantContext::class);

    expect(fn () => $context->assertResolved())
        ->toThrow(TenantContextMissingException::class, "Active tenant [{$disabledTenant->id}] not found or disabled.");
});

it('assertResolved returns active Tenant when TenantManager holds valid tenant ID', function () {
    $tenant = Tenant::factory()->create(['enabled' => true]);
    app(TenantManager::class)->setTenantId((string) $tenant->id);

    $context = app(TenantContext::class);
    $resolved = $context->assertResolved();

    expect($resolved)->not->toBeNull()
        ->and((string) $resolved->id)->toBe((string) $tenant->id);
});
