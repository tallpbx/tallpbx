<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Extensions\Livewire\ExtensionsBulkCreate;
use Modules\Extensions\Livewire\ExtensionsList;
use Modules\Extensions\Models\Extension;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the create multiple extensions page through the panel route', function () {
    $admin = grantAdminPermissions($this->admin, ['extensions.create']);

    $this->actingAs($admin, 'admin')
        ->get(route('panel.extensions.create-multiple'))
        ->assertOk()
        ->assertSee('Create Multiple Extensions')
        ->assertSee('A logical dialable endpoint and identity on the PBX')
        ->assertSee('Start Extension')
        ->assertSee('End Extension');
});

it('shows the create multiple extensions button on the list', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsList::class)
        ->assertOk()
        ->assertSee('Create Multiple Extensions');
});

it('creates a range of extensions', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsBulkCreate::class)
        ->set('tenantId', $this->tenant->id)
        ->set('startExtension', '1200')
        ->set('endExtension', '1202')
        ->set('increment', 1)
        ->set('displayNameTemplate', 'Desk {number}')
        ->set('voicemailEnabled', true)
        ->call('save')
        ->assertRedirect(route('panel.extensions.index'));

    expect(Extension::withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->count())->toBe(3);

    $this->assertDatabaseHas('extensions', [
        'tenant_id' => $this->tenant->id,
        'extension_number' => '1200',
        'display_name' => 'Desk 1200',
        'voicemail_enabled' => true,
    ]);

    $this->assertDatabaseHas('extensions', [
        'tenant_id' => $this->tenant->id,
        'extension_number' => '1202',
        'display_name' => 'Desk 1202',
        'voicemail_enabled' => true,
    ]);
});

it('blocks a range that contains an existing extension number', function () {
    Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '1301',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsBulkCreate::class)
        ->set('tenantId', $this->tenant->id)
        ->set('startExtension', '1300')
        ->set('endExtension', '1302')
        ->call('save')
        ->assertHasErrors('startExtension');

    expect(Extension::withoutGlobalScope('tenant')
        ->where('tenant_id', $this->tenant->id)
        ->whereIn('extension_number', ['1300', '1302'])
        ->exists())->toBeFalse();
});

it('limits a single batch to one thousand extensions', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(ExtensionsBulkCreate::class)
        ->set('tenantId', $this->tenant->id)
        ->set('startExtension', '2000')
        ->set('endExtension', '3000')
        ->call('save')
        ->assertHasErrors('endExtension');
});
