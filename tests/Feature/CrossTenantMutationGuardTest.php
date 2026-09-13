<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Devices\Livewire\DevicesEdit;
use Modules\Devices\Models\Device;
use Modules\Extensions\Livewire\ExtensionsList;
use Modules\Extensions\Models\Extension;
use Modules\NumberTranslations\Livewire\NumberTranslationsEdit;
use Modules\NumberTranslations\Models\NumberTranslation;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Cross-tenant mutation guard tests.
 *
 * Tenant users authenticate through the web guard and are scoped to one
 * tenant at a time. These tests prove that such users cannot read, create,
 * update, or delete records that belong to a different tenant — even when
 * they call Livewire actions directly with a foreign record id. Cross-tenant
 * attempts must fail closed with HTTP 403, which Livewire surfaces as the
 * component response status.
 */
beforeEach(function () {
    $this->tenantA = Tenant::factory()->create();
    $this->tenantB = Tenant::factory()->create();

    // The acting user belongs to tenant A only; tenantUser() also sets the
    // active tenant context to tenant A.
    $this->user = tenantUser($this->tenantA);
});

afterEach(function () {
    app(TenantManager::class)->clear();
});

// ─── Delete actions ─────────────────────────────────────────────────────

it('blocks a tenant user from deleting another tenant\'s extension', function () {
    $foreignExtension = Extension::factory()->create(['tenant_id' => $this->tenantB->id]);

    Livewire::actingAs($this->user, 'web')
        ->test(ExtensionsList::class)
        ->call('confirmExtensionDeletion', $foreignExtension->id)
        ->call('deleteExtension')
        ->assertForbidden();

    $this->assertDatabaseHas('extensions', ['id' => $foreignExtension->id]);
});

it('allows a tenant user to delete their own extension', function () {
    $ownExtension = Extension::factory()->create(['tenant_id' => $this->tenantA->id]);

    Livewire::actingAs($this->user, 'web')
        ->test(ExtensionsList::class)
        ->call('confirmExtensionDeletion', $ownExtension->id)
        ->call('deleteExtension');

    $this->assertDatabaseMissing('extensions', ['id' => $ownExtension->id]);
});

it('still allows an admin to delete extensions across tenants', function () {
    $admin = grantAdminPermissions(permissions: ['extensions.view']);
    $extension = Extension::factory()->create(['tenant_id' => $this->tenantB->id]);

    Livewire::actingAs($admin, 'admin')
        ->test(ExtensionsList::class)
        ->call('confirmExtensionDeletion', $extension->id)
        ->call('deleteExtension');

    $this->assertDatabaseMissing('extensions', ['id' => $extension->id]);
});

// ─── Update actions ─────────────────────────────────────────────────────

it('blocks a tenant user from opening another tenant\'s device to update it', function () {
    // The edit form refuses to mount for foreign records, so the update
    // path can never even start for another tenant's device.
    $foreignDevice = Device::factory()->create(['tenant_id' => $this->tenantB->id]);

    Livewire::actingAs($this->user, 'web')
        ->test(DevicesEdit::class, ['deviceId' => $foreignDevice->id])
        ->assertForbidden();

    expect($foreignDevice->fresh()->vendor)->toBe($foreignDevice->vendor);
});

it('blocks cross-tenant updates at the model level for web-guard users', function () {
    $foreignDevice = Device::factory()->create(['tenant_id' => $this->tenantB->id]);

    $this->actingAs($this->user, 'web');

    expect(fn () => $foreignDevice->update(['vendor' => 'Compromised']))
        ->toThrow(HttpException::class);

    expect($foreignDevice->fresh()->vendor)->not->toBe('Compromised');
});

it('allows a tenant user to update their own device', function () {
    $ownDevice = Device::factory()->create(['tenant_id' => $this->tenantA->id]);

    Livewire::actingAs($this->user, 'web')
        ->test(DevicesEdit::class, ['deviceId' => $ownDevice->id])
        ->set('vendor', 'Yealink')
        ->call('save');

    expect($ownDevice->fresh()->vendor)->toBe('Yealink');
});

// ─── Create actions ─────────────────────────────────────────────────────

it('pins a tenant user\'s device create to their own tenant despite tampering', function () {
    // The tenantId property is user-controllable Livewire state, so a forged
    // value must never place the record into another tenant.
    Livewire::actingAs($this->user, 'web')
        ->test(DevicesEdit::class)
        ->set('tenantId', $this->tenantB->id)
        ->set('vendor', 'Yealink')
        ->set('macAddress', 'AA:BB:CC:DD:EE:FF')
        ->call('save');

    $this->assertDatabaseHas('devices', ['tenant_id' => $this->tenantA->id, 'vendor' => 'Yealink']);
    $this->assertDatabaseMissing('devices', ['tenant_id' => $this->tenantB->id]);
});

it('blocks cross-tenant creates at the model level for web-guard users', function () {
    $this->actingAs($this->user, 'web');

    expect(fn () => Device::withoutGlobalScope('tenant')->create([
        'tenant_id' => $this->tenantB->id,
        'vendor' => 'Yealink',
        'mac_address' => 'AA:BB:CC:DD:EE:01',
        'enabled' => true,
    ]))->toThrow(HttpException::class);

    $this->assertDatabaseMissing('devices', ['tenant_id' => $this->tenantB->id, 'vendor' => 'Yealink']);
});

// ─── Read access through edit forms ─────────────────────────────────────

it('blocks a tenant user from opening another tenant\'s device edit form', function () {
    $foreignDevice = Device::factory()->create(['tenant_id' => $this->tenantB->id]);

    Livewire::actingAs($this->user, 'web')
        ->test(DevicesEdit::class, ['deviceId' => $foreignDevice->id])
        ->assertForbidden();
});

it('blocks a tenant user from opening another tenant\'s number translation edit form', function () {
    $foreignTranslation = NumberTranslation::factory()->create(['tenant_id' => $this->tenantB->id]);

    Livewire::actingAs($this->user, 'web')
        ->test(NumberTranslationsEdit::class, ['translationId' => $foreignTranslation->id])
        ->assertForbidden();
});

it('allows a tenant user to open their own device edit form', function () {
    $ownDevice = Device::factory()->create(['tenant_id' => $this->tenantA->id]);

    Livewire::actingAs($this->user, 'web')
        ->test(DevicesEdit::class, ['deviceId' => $ownDevice->id])
        ->assertOk();
});

// ─── Non-web flows stay unaffected ──────────────────────────────────────

it('does not interfere with unauthenticated console-style flows', function () {
    // No authenticated user: background listeners and console commands set
    // the tenant context themselves and must keep working.
    app(TenantManager::class)->setTenantId((string) $this->tenantB->id);

    $extension = Extension::withoutGlobalScope('tenant')->create([
        'tenant_id' => $this->tenantB->id,
        'extension_number' => '999',
        'display_name' => 'Console Flow',
    ]);

    $extension->update(['display_name' => 'Console Flow Updated']);
    $extension->delete();

    $this->assertDatabaseMissing('extensions', ['id' => $extension->id]);
});
