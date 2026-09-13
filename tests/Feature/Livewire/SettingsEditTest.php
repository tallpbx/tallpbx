<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Setting;
use App\Services\SettingServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Modules\Admin\Livewire\SettingsEdit;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the settings list', function () {
    Setting::create(['key' => 'app.name', 'value' => 'MyPBX', 'type' => 'string']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SettingsEdit::class)
        ->assertOk()
        ->assertSee('Settings')
        ->assertSee('app.name')
        ->assertSee('MyPBX');
});

it('shows edit form for a setting', function () {
    $setting = Setting::create(['key' => 'app.url', 'value' => 'https://example.com', 'type' => 'string']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SettingsEdit::class)
        ->call('editSetting', $setting->id)
        ->assertSet('editingId', $setting->id)
        ->assertSet('editKey', 'app.url')
        ->assertSet('editValue', 'https://example.com');
});

it('updates an existing setting', function () {
    $setting = Setting::create(['key' => 'app.name', 'value' => 'Old Name', 'type' => 'string']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SettingsEdit::class)
        ->call('editSetting', $setting->id)
        ->set('editValue', 'New Name')
        ->call('saveSetting')
        ->assertDispatched('setting-saved');

    $this->assertDatabaseHas('settings', [
        'id' => $setting->id,
        'value' => 'New Name',
    ]);
});

it('creates a new setting', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SettingsEdit::class)
        ->set('newKey', 'mail.host')
        ->set('newValue', 'smtp.example.com')
        ->set('newType', 'string')
        ->call('createSetting')
        ->assertDispatched('setting-created');

    $this->assertDatabaseHas('settings', [
        'key' => 'mail.host',
        'value' => 'smtp.example.com',
    ]);
});

it('deletes a setting after modal confirmation', function () {
    $setting = Setting::create(['key' => 'temp.key', 'value' => 'temp', 'type' => 'string']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SettingsEdit::class)
        ->call('confirmSettingDeletion', $setting->id)
        ->assertSet('pendingDeletionId', $setting->id)
        ->call('deleteSetting')
        ->assertSet('operationalMessage', 'Setting deleted.')
        ->assertDispatched('setting-deleted');

    $this->assertModelMissing($setting);
});

it('opens the shared confirmation modal before deleting a setting', function (): void {
    $setting = Setting::create(['key' => 'temp.key', 'value' => 'temp', 'type' => 'string']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SettingsEdit::class)
        ->call('confirmSettingDeletion', $setting->id)
        ->assertSet('pendingDeletionId', $setting->id)
        ->assertSet('pendingDeletionName', 'temp.key')
        ->assertSee('Delete Setting?');
});

it('keeps the modal open with a safe error when setting deletion fails', function (): void {
    $setting = Setting::create(['key' => 'temp.key', 'value' => 'temp', 'type' => 'string']);

    $service = Mockery::mock(SettingServiceInterface::class);
    $service->shouldReceive('all')->andReturn(new Collection([$setting]));
    $service->shouldReceive('delete')->once()->andThrow(new RuntimeException('setting is protected'));
    app()->instance(SettingServiceInterface::class, $service);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SettingsEdit::class)
        ->call('confirmSettingDeletion', $setting->id)
        ->call('deleteSetting')
        ->assertSet('deleteError', 'Setting could not be deleted. setting is protected');

    $this->assertModelExists($setting);
});

it('validates key and value are required for new settings', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SettingsEdit::class)
        ->set('newKey', '')
        ->set('newValue', '')
        ->call('createSetting')
        ->assertHasErrors(['newKey', 'newValue']);
});

it('validates key is unique for new settings', function () {
    Setting::create(['key' => 'existing.key', 'value' => 'val', 'type' => 'string']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SettingsEdit::class)
        ->set('newKey', 'existing.key')
        ->set('newValue', 'val')
        ->set('newType', 'string')
        ->call('createSetting')
        ->assertHasErrors(['newKey']);
});
