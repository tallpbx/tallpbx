<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Extensions\Livewire\ExtensionsEdit;
use Modules\Extensions\Models\Extension;

/*
 * A tenant user reaches the same edit forms as an administrator. They cannot
 * choose a tenant, so the form must derive it from the authorized context —
 * and a crafted Livewire payload must never redirect the write.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();

    $this->tenantUser = grantTenantUserPermissions($this->tenant, [
        'extensions.view',
        'extensions.create',
        'extensions.edit',
    ]);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
});

it('lets a tenant user create an extension without touching tenant state', function (): void {
    Livewire::actingAs($this->tenantUser, 'web')
        ->test(ExtensionsEdit::class)
        ->assertDontSee(__('admin.tenant'))
        ->set('extensionNumber', '206')
        ->set('password', 'secret123')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('extensions', ['extension_number' => '206', 'tenant_id' => $this->tenant->id]);
});

it('refuses a duplicate number inside the same tenant', function (): void {
    Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '206',
        'enabled' => true,
    ]);

    Livewire::actingAs($this->tenantUser, 'web')
        ->test(ExtensionsEdit::class)
        ->set('extensionNumber', '206')
        ->call('save')
        ->assertHasErrors(['extensionNumber' => 'unique']);
});

it('ignores a forged tenant id from a tenant user', function (): void {
    Livewire::actingAs($this->tenantUser, 'web')
        ->test(ExtensionsEdit::class)
        ->set('tenantId', $this->otherTenant->id)
        ->set('extensionNumber', '207')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('extensions', ['extension_number' => '207', 'tenant_id' => $this->tenant->id]);
    $this->assertDatabaseMissing('extensions', ['extension_number' => '207', 'tenant_id' => $this->otherTenant->id]);
});

it('neutralises a tampered tenant id on a Livewire update', function (): void {
    // hydrate() runs before the payload is applied, so the guard that matters
    // here is BaseEditComponent::updated(). The value must be replaced.
    Livewire::actingAs($this->tenantUser, 'web')
        ->test(ExtensionsEdit::class)
        ->set('tenantId', $this->otherTenant->id)
        ->assertSet('tenantId', $this->tenant->id);
});

it('fails closed when a tenant user has no authorized tenant context', function (): void {
    app(TenantManager::class)->clear();

    Livewire::actingAs($this->tenantUser, 'web')
        ->test(ExtensionsEdit::class)
        ->set('extensionNumber', '208')
        ->call('save')
        ->assertForbidden();

    expect(Extension::withoutGlobalScope('tenant')->where('extension_number', '208')->exists())->toBeFalse();
});
