<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\TenantIdentity;
use App\Services\TenantIdentityResolverInterface;
use App\Services\TenantManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Modules\SipAccounts\Models\SipAccount;

beforeEach(function () {
    // Ensure clean tenant context for each test
    config([
        'cache.default' => 'array',
        'freeswitch.xml_handler.directory_cache_store' => 'array',
        'freeswitch.xml_handler.directory_cache_ttl' => 0,
    ]);
    Cache::store('array')->flush();
    app(TenantManager::class)->clear();
});

// ─── TenantIdentity Value Object ─────────────────────────────────

it('creates a TenantIdentity value object', function () {
    $identity = new TenantIdentity(
        tenantId: '550e8400-e29b-41d4-a716-446655440000',
        sipAccountId: '660e8400-e29b-41d4-a716-446655440001',
        identityMode: 'global_username',
    );

    expect($identity->tenantId)->toBe('550e8400-e29b-41d4-a716-446655440000')
        ->and($identity->sipAccountId)->toBe('660e8400-e29b-41d4-a716-446655440001')
        ->and($identity->identityMode)->toBe('global_username')
        ->and($identity->extensionId)->toBeNull()
        ->and($identity->extensionNumber)->toBeNull()
        ->and($identity->userContext)->toBeNull()
        ->and($identity->tenantDomainId)->toBeNull();
});

it('creates a TenantIdentity with all optional fields', function () {
    $identity = new TenantIdentity(
        tenantId: 'a',
        sipAccountId: 'b',
        extensionId: 'c',
        extensionNumber: '1001',
        userContext: 'default',
        identityMode: 'domain_username',
        tenantDomainId: 'd',
    );

    expect($identity->extensionNumber)->toBe('1001')
        ->and($identity->userContext)->toBe('default')
        ->and($identity->extensionId)->toBe('c')
        ->and($identity->tenantDomainId)->toBe('d');
});

it('is a readonly object', function () {
    $identity = new TenantIdentity(tenantId: 'some-id');

    expect(fn () => $identity->tenantId = 'changed')
        ->toThrow(Error::class);
});

// ─── TenantIdentityResolver — resolveFromDomain ─────────────────

it('resolves tenant from an existing enabled domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'sip.example.com',
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromDomain('sip.example.com');

    expect($identity)->not->toBeNull()
        ->and($identity->tenantId)->toBe((string) $tenant->id)
        ->and($identity->tenantDomainId)->toBe($domain->id);
});

it('returns null for an unknown domain', function () {
    Tenant::factory()->create();

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromDomain('unknown.example.com');

    expect($identity)->toBeNull();
});

it('returns null for a disabled domain', function () {
    $tenant = Tenant::factory()->create();
    TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'disabled.example.com',
        'enabled' => false,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromDomain('disabled.example.com');

    expect($identity)->toBeNull();
});

it('returns null for an empty domain', function () {
    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromDomain('');

    expect($identity)->toBeNull();
});

// ─── TenantIdentityResolver — resolveFromUsername ────────────────

it('resolves a global_username account without a domain', function () {
    $tenant = Tenant::factory()->create();
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'identity_mode' => 'global_username',
        'auth_username' => 'user1001',
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromUsername('user1001');

    expect($identity)->not->toBeNull()
        ->and($identity->tenantId)->toBe((string) $tenant->id)
        ->and($identity->identityMode)->toBe('global_username');
});

it('resolves a domain_username account with a matching domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'tenant1.example.com',
        'enabled' => true,
    ]);
    SipAccount::factory()->domainUsername('user2001', $domain->id)->create([
        'tenant_id' => $tenant->id,
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromUsername('user2001', 'tenant1.example.com');

    expect($identity)->not->toBeNull()
        ->and($identity->tenantId)->toBe((string) $tenant->id)
        ->and($identity->tenantDomainId)->toBe($domain->id)
        ->and($identity->identityMode)->toBe('domain_username');
});

it('returns null when domain_username is looked up without a domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create(['tenant_id' => $tenant->id]);
    SipAccount::factory()->domainUsername('user3001', $domain->id)->create([
        'tenant_id' => $tenant->id,
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromUsername('user3001');

    // Should not find a domain_username account when no domain is provided
    expect($identity)->toBeNull();
});

it('returns null for an unknown username', function () {
    $tenant = Tenant::factory()->create();
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'auth_username' => 'existing_user',
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromUsername('nonexistent_user');

    expect($identity)->toBeNull();
});

it('returns null for a disabled account', function () {
    $tenant = Tenant::factory()->create();
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'auth_username' => 'disabled_user',
        'identity_mode' => 'global_username',
        'enabled' => false,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromUsername('disabled_user');

    expect($identity)->toBeNull();
});

it('returns null for an empty username', function () {
    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromUsername('');

    expect($identity)->toBeNull();
});

it('returns full TenantIdentity details from resolveFromUsername', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'full.example.com',
    ]);
    $account = SipAccount::factory()->domainUsername('full_user', $domain->id)->create([
        'tenant_id' => $tenant->id,
        'user_context' => 'custom_context',
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromUsername('full_user', 'full.example.com');

    expect($identity)->not->toBeNull()
        ->and($identity->tenantId)->toBe((string) $tenant->id)
        ->and($identity->sipAccountId)->toBe($account->id)
        ->and($identity->tenantDomainId)->toBe($domain->id)
        ->and($identity->identityMode)->toBe('domain_username')
        ->and($identity->userContext)->toBe('custom_context');
});

// ─── TenantIdentityResolver — resolveFromSipAuth ────────────────

it('resolves via sip auth for global_username', function () {
    $tenant = Tenant::factory()->create();
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'auth_username' => 'auth_user',
        'identity_mode' => 'global_username',
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromSipAuth('auth_user');

    expect($identity)->not->toBeNull()
        ->and($identity->tenantId)->toBe((string) $tenant->id);
});

it('resolves via sip auth with domain', function () {
    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'auth-domain.example.com',
    ]);
    SipAccount::factory()->domainUsername('auth_user2', $domain->id)->create([
        'tenant_id' => $tenant->id,
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromSipAuth('auth_user2', 'auth-domain.example.com');

    expect($identity)->not->toBeNull()
        ->and($identity->tenantId)->toBe((string) $tenant->id)
        ->and($identity->identityMode)->toBe('domain_username');
});

it('returns null from sip auth when account is disabled', function () {
    $tenant = Tenant::factory()->create();
    SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'auth_username' => 'disabled_auth',
        'identity_mode' => 'global_username',
        'enabled' => false,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromSipAuth('disabled_auth');

    expect($identity)->toBeNull();
});

// ─── Cross-tenant Isolation ─────────────────────────────────────

it('does not resolve a global_username from another tenant', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    SipAccount::factory()->create([
        'tenant_id' => $tenantA->id,
        'auth_username' => 'shared_user',
        'identity_mode' => 'global_username',
        'enabled' => true,
    ]);
    // Same username in tenant B
    SipAccount::factory()->create([
        'tenant_id' => $tenantB->id,
        'auth_username' => 'shared_user',
        'identity_mode' => 'global_username',
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    // Global username lookup without domain should fail when ambiguous
    // (more than one match across tenants)
    $identity = $resolver->resolveFromUsername('shared_user');

    expect($identity)->toBeNull();
});

it('isolates domain_username by tenant domain', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $domainA = TenantDomain::factory()->create([
        'tenant_id' => $tenantA->id,
        'domain' => 'tenant-a.example.com',
    ]);
    $domainB = TenantDomain::factory()->create([
        'tenant_id' => $tenantB->id,
        'domain' => 'tenant-b.example.com',
    ]);

    SipAccount::factory()->domainUsername('dup_user', $domainA->id)->create([
        'tenant_id' => $tenantA->id,
        'enabled' => true,
    ]);
    SipAccount::factory()->domainUsername('dup_user', $domainB->id)->create([
        'tenant_id' => $tenantB->id,
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);

    // Same username, different domains — should resolve to correct tenant
    $identityA = $resolver->resolveFromUsername('dup_user', 'tenant-a.example.com');
    expect($identityA)->not->toBeNull()
        ->and($identityA->tenantId)->toBe((string) $tenantA->id);

    $identityB = $resolver->resolveFromUsername('dup_user', 'tenant-b.example.com');
    expect($identityB)->not->toBeNull()
        ->and($identityB->tenantId)->toBe((string) $tenantB->id);
});

// ─── Shared-Domain Tenant Resolution ────────────────────────────

it('fails closed when resolveFromDomain finds a domain shared by multiple tenants', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    // Same domain, two different tenants
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

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromDomain('shared.example.com');

    // Must return null — domain-only resolution is ambiguous when
    // multiple tenants share the same domain.
    expect($identity)->toBeNull();
});

it('resolves domain+username when two tenants share the same domain', function () {
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

    SipAccount::factory()->domainUsername('alice', $domainA->id)->create([
        'tenant_id' => $tenantA->id,
        'enabled' => true,
    ]);
    SipAccount::factory()->domainUsername('bob', $domainB->id)->create([
        'tenant_id' => $tenantB->id,
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);

    // Domain+username should disambiguate even when domain is shared
    $identityA = $resolver->resolveFromUsername('alice', 'shared.example.com');
    expect($identityA)->not->toBeNull()
        ->and($identityA->tenantId)->toBe((string) $tenantA->id);

    $identityB = $resolver->resolveFromUsername('bob', 'shared.example.com');
    expect($identityB)->not->toBeNull()
        ->and($identityB->tenantId)->toBe((string) $tenantB->id);
});

it('fails closed when same username exists in two tenants sharing the same domain', function () {
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

    // Both tenants have 'alice' on the same shared domain — AMBIGUOUS
    SipAccount::factory()->domainUsername('alice', $domainA->id)->create([
        'tenant_id' => $tenantA->id,
        'enabled' => true,
    ]);
    SipAccount::factory()->domainUsername('alice', $domainB->id)->create([
        'tenant_id' => $tenantB->id,
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);
    $identity = $resolver->resolveFromUsername('alice', 'shared.example.com');

    // Must return null when the same username exists across
    // multiple tenants under the same shared domain.
    expect($identity)->toBeNull();
});

it('caches sip auth identity resolution for repeated FreeSWITCH directory lookups', function () {
    config(['freeswitch.xml_handler.directory_cache_ttl' => 60]);

    $tenant = Tenant::factory()->create();
    $domain = TenantDomain::factory()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'cache-auth.example.com',
        'enabled' => true,
    ]);
    SipAccount::factory()->domainUsername('cached_user', $domain->id)->create([
        'tenant_id' => $tenant->id,
        'enabled' => true,
    ]);

    $resolver = app(TenantIdentityResolverInterface::class);

    DB::enableQueryLog();
    $firstIdentity = $resolver->resolveFromSipAuth('cached_user', 'cache-auth.example.com');
    $firstQueryCount = count(DB::getQueryLog());

    DB::flushQueryLog();
    $secondIdentity = $resolver->resolveFromSipAuth('cached_user', 'cache-auth.example.com');
    $secondQueryCount = count(DB::getQueryLog());

    expect($firstIdentity)->not->toBeNull()
        ->and($secondIdentity)->not->toBeNull()
        ->and($secondIdentity->tenantId)->toBe((string) $tenant->id)
        ->and($firstQueryCount)->toBeGreaterThan(0)
        ->and($secondQueryCount)->toBe(0);
});
