<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Conferences\Livewire\ConferencesEdit;
use Modules\Conferences\Livewire\ConferencesList;
use Modules\Conferences\Models\Conference;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
    $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('denies tenant user access to another tenant conference on edit form', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['conferences.view', 'conferences.edit']);
    $foreignConf = Conference::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Tenant B Conf',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(ConferencesEdit::class, ['conferenceId' => $foreignConf->id])
        ->assertForbidden();
});

it('allows tenant user access to their own tenant conference on edit form', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['conferences.view', 'conferences.edit']);
    $ownConf = Conference::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Tenant A Conf',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(ConferencesEdit::class, ['conferenceId' => $ownConf->id])
        ->assertOk()
        ->assertSet('name', 'Tenant A Conf')
        ->assertSet('conferenceId', $ownConf->id);
});

it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['conferences.create']);

    $this->actingAs($userA, 'web');

    expect(function () {
        Conference::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Spoofed Conference',
            'profile' => 'default',
            'max_members' => 10,
        ]);
    })->toThrow(HttpException::class, 'Cross-tenant access denied.');

    expect(Conference::withoutGlobalScope('tenant')->where('name', 'Spoofed Conference')->exists())->toBeFalse();
});

it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['conferences.edit']);
    $foreignConf = Conference::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Original Conf',
        'profile' => 'default',
    ]);

    $this->actingAs($userA, 'web');

    expect(function () use ($foreignConf) {
        $foreignConf->update([
            'name' => 'Hacked Conf',
        ]);
    })->toThrow(HttpException::class, 'Cross-tenant access denied.');

    expect($foreignConf->fresh()->name)->toBe('Original Conf');
});

it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['conferences.view', 'conferences.delete']);
    $foreignConf = Conference::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Foreign Conf',
        'profile' => 'default',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(ConferencesList::class)
        ->call('deleteConference', $foreignConf->id)
        ->assertForbidden();

    expect(Conference::withoutGlobalScope('tenant')->where('id', $foreignConf->id)->exists())->toBeTrue();
});

it('allows tenant user to create conference for their own tenant', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['conferences.view', 'conferences.create']);

    Livewire::actingAs($userA, 'web')
        ->test(ConferencesEdit::class)
        ->set('name', 'Team Sync')
        ->set('profile' , 'sample')
        ->set('pin', '4321')
        ->set('maxMembers', 50)
        ->call('save')
        ->assertRedirect(route('panel.conferences.index'));

    $conf = Conference::withoutGlobalScope('tenant')->where('name', 'Team Sync')->first();
    expect($conf)->not->toBeNull()
        ->and($conf->tenant_id)->toBe($this->tenantA->id);
});

it('scopes conference list to active tenant for tenant users', function () {
    Conference::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Tenant A Visible Conf',
    ]);

    Conference::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Tenant B Hidden Conf',
    ]);

    $userA = grantTenantUserPermissions($this->tenantA, ['conferences.view']);

    Livewire::actingAs($userA, 'web')
        ->test(ConferencesList::class)
        ->assertSee('Tenant A Visible Conf')
        ->assertDontSee('Tenant B Hidden Conf');
});

it('allows two tenants to have conferences with the same name without collision', function () {
    $confA = Conference::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Executive Meeting',
        'profile' => 'default',
    ]);

    $confB = Conference::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Executive Meeting',
        'profile' => 'default',
    ]);

    expect($confA->id)->not->toBe($confB->id)
        ->and($confA->name)->toBe($confB->name)
        ->and($confA->tenant_id)->toBe($this->tenantA->id)
        ->and($confB->tenant_id)->toBe($this->tenantB->id);
});
