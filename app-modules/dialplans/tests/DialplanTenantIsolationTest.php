<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Dialplans\Livewire\DialplansEdit;
use Modules\Dialplans\Livewire\DialplansList;
use Modules\Dialplans\Models\Dialplan;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
    $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('denies tenant user access to another tenant dialplan on edit form', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.view', 'dialplans.edit']);
    $foreignDialplan = Dialplan::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Tenant B Dialplan',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(DialplansEdit::class, ['dialplanId' => $foreignDialplan->id])
        ->assertForbidden();
});

it('allows tenant user access to their own tenant dialplan on edit form', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.view', 'dialplans.edit']);
    $ownDialplan = Dialplan::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Tenant A Dialplan',
        'context' => 'default',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(DialplansEdit::class, ['dialplanId' => $ownDialplan->id])
        ->assertOk()
        ->assertSet('name', 'Tenant A Dialplan')
        ->assertSet('dialplanId', $ownDialplan->id);
});

it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.create']);

    $this->actingAs($userA, 'web');

    expect(function () {
        Dialplan::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Spoofed Dialplan',
            'context' => 'default',
        ]);
    })->toThrow(HttpException::class, 'Cross-tenant access denied.');

    expect(Dialplan::withoutGlobalScope('tenant')->where('name', 'Spoofed Dialplan')->exists())->toBeFalse();
});

it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.edit']);
    $foreignDialplan = Dialplan::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Original Dialplan',
        'context' => 'default',
    ]);

    $this->actingAs($userA, 'web');

    expect(function () use ($foreignDialplan) {
        $foreignDialplan->update([
            'name' => 'Hacked Dialplan',
        ]);
    })->toThrow(HttpException::class, 'Cross-tenant access denied.');

    expect($foreignDialplan->fresh()->name)->toBe('Original Dialplan');
});

it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.view', 'dialplans.delete']);
    $foreignDialplan = Dialplan::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Foreign Dialplan',
        'context' => 'default',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(DialplansList::class)
        ->call('deleteDialplan', $foreignDialplan->id)
        ->assertForbidden();

    expect(Dialplan::withoutGlobalScope('tenant')->where('id', $foreignDialplan->id)->exists())->toBeTrue();
});

it('allows two tenants to have dialplans with the same name without collision', function () {
    $dpA = Dialplan::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Local Extension Routing',
        'context' => 'default',
    ]);

    $dpB = Dialplan::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Local Extension Routing',
        'context' => 'default',
    ]);

    expect($dpA->id)->not->toBe($dpB->id)
        ->and($dpA->name)->toBe($dpB->name)
        ->and($dpA->tenant_id)->toBe($this->tenantA->id)
        ->and($dpB->tenant_id)->toBe($this->tenantB->id);
});
