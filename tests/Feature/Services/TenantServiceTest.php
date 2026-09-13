<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Models\User;
use App\Services\DialplanContext;
use App\Services\TenantServiceInterface;
use Modules\Dialplans\Models\Dialplan;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\MusicOnHold\Models\MusicOnHold;
use Modules\SipProfiles\Models\SipProfile;

beforeEach(function () {
    $this->service = app(TenantServiceInterface::class);
});

it('creates a tenant', function () {
    $tenant = $this->service->create([
        'name' => 'Acme Corp',
        'slug' => 'acme-corp',
    ]);

    expect($tenant)
        ->toBeInstanceOf(Tenant::class)
        ->name->toBe('Acme Corp')
        ->slug->toBe('acme-corp')
        ->purpose->toBe(Tenant::PURPOSE_CUSTOMER)
        ->enabled->toBeTrue();
});

it('ensures the default tenant for shared admin-owned resources', function () {
    $tenant = $this->service->defaultTenant();

    expect($tenant)
        ->toBeInstanceOf(Tenant::class)
        ->name->toBe('Default')
        ->slug->toBe('default')
        ->purpose->toBe(Tenant::PURPOSE_DEFAULT)
        ->enabled->toBeTrue()
        ->and($tenant->primary_user_id)->toBeNull();
});

it('idempotently provisions the default tenant PBX defaults', function () {
    $tenant = $this->service->defaultTenant();
    $secondRun = $this->service->defaultTenant();

    expect($secondRun->id)->toBe($tenant->id)
        ->and(Tenant::where('purpose', Tenant::PURPOSE_DEFAULT)->count())->toBe(1)
        ->and(SipProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(FeatureCode::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', '*97')->count())->toBe(1)
        ->and(MusicOnHold::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'default')->count())->toBe(1);
});

it('registers the configured default SIP realm as the default tenant domain', function () {
    config(['freeswitch.default_sip_realm' => 'sip.example.test']);

    $tenant = $this->service->defaultTenant();
    $this->service->defaultTenant();

    expect(TenantDomain::query()->where('tenant_id', $tenant->id)->where('domain', 'sip.example.test')->count())->toBe(1)
        ->and(TenantDomain::query()->where('tenant_id', $tenant->id)->where('domain', 'sip.example.test')->value('purpose'))->toBe('sip_realm')
        ->and(TenantDomain::query()->where('tenant_id', $tenant->id)->where('domain', 'sip.example.test')->value('enabled'))->toBeTruthy();
});

it('never assigns the server realm to a customer tenant', function () {
    config(['freeswitch.default_sip_realm' => 'sip.example.test']);

    $tenant = $this->service->create([
        'name' => 'Customer Co',
        'slug' => 'customer-co',
    ]);

    expect(TenantDomain::query()->where('tenant_id', $tenant->id)->count())->toBe(0);
});

it('normalizes an existing default-slug tenant for shared resources', function () {
    $user = User::factory()->create();
    $existing = Tenant::factory()->create([
        'name' => 'Old Default',
        'slug' => 'default',
        'purpose' => Tenant::PURPOSE_CUSTOMER,
        'primary_user_id' => $user->id,
        'enabled' => false,
    ]);

    $tenant = $this->service->defaultTenant();

    expect($tenant->id)->toBe($existing->id)
        ->and($tenant->name)->toBe('Default')
        ->and($tenant->purpose)->toBe(Tenant::PURPOSE_DEFAULT)
        ->and($tenant->primary_user_id)->toBeNull()
        ->and($tenant->enabled)->toBeTrue();
});

it('provisions PBX defaults when creating a tenant', function () {
    $tenant = $this->service->create([
        'name' => 'Provisioned Tenant',
        'slug' => 'provisioned-tenant',
    ]);

    $internalContext = app(DialplanContext::class)->internal((string) $tenant->id);

    expect(SipProfile::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(Dialplan::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('context', $internalContext)->exists())->toBeTrue()
        ->and(FeatureCode::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('code', '*97')->exists())->toBeTrue()
        ->and(MusicOnHold::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'default')->exists())->toBeTrue();
});

it('creates a tenant with a primary user', function () {
    $user = User::factory()->create();

    $tenant = $this->service->create([
        'name' => 'Primary Test',
        'slug' => 'primary-test',
        'primary_user_id' => $user->id,
    ]);

    expect($tenant->primaryUser)->not->toBeNull()
        ->and($tenant->primaryUser->id)->toBe($user->id);
});

it('updates a tenant', function () {
    $tenant = Tenant::factory()->create(['name' => 'Old Name']);

    $updated = $this->service->update($tenant, [
        'name' => 'New Name',
    ]);

    expect($updated->name)->toBe('New Name');
});

it('deletes a tenant and its relationships', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => 'admin']);

    $this->service->delete($tenant);

    $this->assertModelMissing($tenant);
    expect($user->tenants()->count())->toBe(0);
});

it('adds a user to a tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();

    $this->service->addUser($tenant, $user, 'admin');

    expect($tenant->users()->count())->toBe(1)
        ->and($tenant->users()->first()->pivot->role)->toBe('admin');
});

it('removes a user from a tenant', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->create();
    $tenant->users()->attach($user, ['role' => 'member']);

    $this->service->removeUser($tenant, $user);

    expect($tenant->users()->count())->toBe(0);
});

it('lists all tenants', function () {
    Tenant::factory()->count(3)->create();

    $tenants = $this->service->all();

    expect($tenants)->toHaveCount(3);
});

it('finds a tenant by slug', function () {
    $tenant = Tenant::factory()->create(['slug' => 'find-me']);

    $found = $this->service->findBySlug('find-me');

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($tenant->id);
});

it('toggles tenant enabled status', function () {
    $tenant = Tenant::factory()->create(['enabled' => true]);

    $this->service->setEnabled($tenant, false);

    expect($tenant->fresh()->enabled)->toBeFalse();
});
