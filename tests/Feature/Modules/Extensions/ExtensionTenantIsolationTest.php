<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Extensions\Livewire\ExtensionsBulkCreate;
use Modules\Extensions\Livewire\ExtensionsEdit;
use Modules\Extensions\Livewire\ExtensionsList;
use Modules\Extensions\Models\Extension;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
    $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('denies tenant user access to another tenant extension on edit form', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['extensions.view', 'extensions.edit']);
    $foreignExtension = Extension::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'extension_number' => '201',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(ExtensionsEdit::class, ['extensionId' => $foreignExtension->id])
        ->assertForbidden();
});

it('allows tenant user access to their own tenant extension on edit form', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['extensions.view', 'extensions.edit']);
    $ownExtension = Extension::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'extension_number' => '101',
        'display_name' => 'John Doe',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(ExtensionsEdit::class, ['extensionId' => $ownExtension->id])
        ->assertOk()
        ->assertSet('extensionNumber', '101')
        ->assertSet('displayName', 'John Doe');
});

it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['extensions.create']);

    $this->actingAs($userA, 'web');

    expect(function () {
        Extension::create([
            'tenant_id' => $this->tenantB->id,
            'extension_number' => '999',
            'display_name' => 'Spoofed Extension',
        ]);
    })->toThrow(HttpException::class, 'Cross-tenant access denied.');

    expect(Extension::withoutGlobalScope('tenant')->where('extension_number', '999')->exists())->toBeFalse();
});

it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['extensions.edit']);
    $foreignExtension = Extension::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'extension_number' => '202',
        'display_name' => 'Original Name',
    ]);

    $this->actingAs($userA, 'web');

    expect(function () use ($foreignExtension) {
        $foreignExtension->update([
            'display_name' => 'Hacked Name',
        ]);
    })->toThrow(HttpException::class, 'Cross-tenant access denied.');

    expect($foreignExtension->fresh()->display_name)->toBe('Original Name');
});

it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['extensions.view', 'extensions.delete']);
    $foreignExtension = Extension::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'extension_number' => '203',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(ExtensionsList::class)
        ->call('confirmExtensionDeletion', $foreignExtension->id)
        ->call('deleteExtension')
        ->assertForbidden();

    expect(Extension::withoutGlobalScope('tenant')->where('id', $foreignExtension->id)->exists())->toBeTrue();
});

it('allows two tenants to share the same extension number without collisions', function () {
    $extA = Extension::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'extension_number' => '100',
        'display_name' => 'Operator A',
    ]);

    $extB = Extension::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'extension_number' => '100',
        'display_name' => 'Operator B',
    ]);

    expect($extA->id)->not->toBe($extB->id)
        ->and($extA->extension_number)->toBe($extB->extension_number)
        ->and($extA->tenant_id)->toBe($this->tenantA->id)
        ->and($extB->tenant_id)->toBe($this->tenantB->id);
});

it('stamps bulk-created extensions with the active tenant for tenant users', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['extensions.create']);

    Livewire::actingAs($userA, 'web')
        ->test(ExtensionsBulkCreate::class)
        ->set('startExtension', '300')
        ->set('endExtension', '302')
        ->set('increment', 1)
        ->set('displayNameTemplate', 'Agent {number}')
        ->call('save')
        ->assertRedirect(route('panel.extensions.index'));

    $created = Extension::withoutGlobalScope('tenant')
        ->whereIn('extension_number', ['300', '301', '302'])
        ->get();

    expect($created)->toHaveCount(3);
    foreach ($created as $ext) {
        expect($ext->tenant_id)->toBe($this->tenantA->id);
    }
});
