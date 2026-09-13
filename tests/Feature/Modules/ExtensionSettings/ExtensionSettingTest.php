<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Extensions\Models\Extension;
use Modules\ExtensionSettings\Livewire\ExtensionSettingsEdit;
use Modules\ExtensionSettings\Livewire\ExtensionSettingsList;
use Modules\ExtensionSettings\Models\ExtensionSetting;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('renders the list component', function () {
    ExtensionSetting::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionSettingsList::class)
        ->assertOk()
        ->assertSee('Extension Settings')
        ->assertViewHas('settings', fn ($items) => $items->count() === 2);
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionSettingsEdit::class)
        ->assertOk()
        ->assertSee('Create');
});

it('renders the edit form', function () {
    $setting = ExtensionSetting::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionSettingsEdit::class, ['settingId' => $setting->id])
        ->assertOk()
        ->assertSee('Edit');
});

it('creates an extension setting', function () {
    $extension = Extension::factory()->create(['tenant_id' => $this->tenant->id]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionSettingsEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('extensionId', $extension->id)
        ->set('key', 'call_waiting')
        ->set('value', 'enabled')
        ->call('save')
        ->assertRedirect(route('panel.extension-settings.index'));

    expect(ExtensionSetting::count())->toBe(1);
});

it('validates required fields', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionSettingsEdit::class)
        ->call('save')
        ->assertHasErrors(['tenantId', 'extensionId', 'key']);
});

it('deletes an extension setting', function () {
    $setting = ExtensionSetting::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionSettingsList::class)
        ->call('deleteSetting', $setting->id)
        ->assertOk();

    expect(ExtensionSetting::count())->toBe(0);
});

it('opens the shared confirmation modal before deleting an extension setting', function (): void {
    $setting = ExtensionSetting::factory()->create(['key' => 'call_timeout']);
    Livewire::actingAs($this->admin, 'admin')->test(ExtensionSettingsList::class)->call('confirmSettingDeletion', $setting->id)->assertSet('pendingDeletionId', $setting->id)->assertSet('pendingDeletionName', 'call_timeout')->assertSee('Delete Extension Setting?');
});

it('shows empty state when no settings exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionSettingsList::class)
        ->assertSee('No settings found');
});
