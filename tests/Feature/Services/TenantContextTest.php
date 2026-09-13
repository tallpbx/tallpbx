<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantContext;
use App\Services\TenantManager;

beforeEach(function () {
    app(TenantManager::class)->clear();
});

// ─── TenantContext::current() — Session path ────────────────────

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

it('returns the session-stored tenant', function () {
    $tenant = Tenant::factory()->create();
    session()->put('selected_tenant_id', $tenant->id);

    $context = app(TenantContext::class);
    $result = $context->current();

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenant->id);
});

it('sets TenantManager from session-stored tenant', function () {
    $tenant = Tenant::factory()->create();
    session()->put('selected_tenant_id', $tenant->id);

    app(TenantContext::class)->current();

    expect(app(TenantManager::class)->getTenantId())->toBe((string) $tenant->id);
});

// ─── TenantContext::current() — Authenticated user path ──────────

it('returns authenticated users primary tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['primary' => true]);
    $this->actingAs($user, 'web');

    $context = app(TenantContext::class);
    $result = $context->current();

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenant->id);
});

it('returns the first tenant when authenticated user has no primary', function () {
    $tenantA = Tenant::factory()->create(['name' => 'Alpha']);
    $tenantB = Tenant::factory()->create(['name' => 'Beta']);
    $user = User::factory()->create();
    $user->tenants()->attach([$tenantA->id, $tenantB->id]);
    $this->actingAs($user, 'web');

    $context = app(TenantContext::class);
    $result = $context->current();

    // Should return first attached tenant (Alpha, since tenant_user was inserted first)
    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenantA->id);
});

it('sets TenantManager for authenticated users tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $user->tenants()->attach($tenant, ['primary' => true]);
    $this->actingAs($user, 'web');

    app(TenantContext::class)->current();

    expect(app(TenantManager::class)->getTenantId())->toBe((string) $tenant->id);
});

// ─── TenantContext::current() — Single-tenant fallback ───────────

it('falls back to single tenant when only one exists', function () {
    $tenant = Tenant::factory()->create();
    // No auth, no session — but only one tenant exists

    $context = app(TenantContext::class);
    $result = $context->current();

    expect($result)->not->toBeNull()
        ->and((string) $result->id)->toBe((string) $tenant->id);
});

it('sets TenantManager from single-tenant fallback', function () {
    Tenant::factory()->create();

    app(TenantContext::class)->current();

    expect(app(TenantManager::class)->getTenantId())->not->toBeNull();
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

    // Session should win over auth
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

    expect(session()->get('selected_tenant_id'))->toBe((string) $tenantB->id);
});

// ─── TenantContext::resolve() ────────────────────────────────────

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
