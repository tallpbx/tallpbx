<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\IvrMenus\Livewire\IvrMenusEdit;
use Modules\IvrMenus\Models\IvrMenu;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusEdit::class)
        ->assertOk()
        ->assertSee('Create IVR Menu')
        ->assertSet('name', '')
        ->assertSet('timeout', 10)
        ->assertSet('maxFailures', 3)
        ->assertSet('enabled', true);
});

it('renders the edit form with existing IVR menu data', function () {
    $menu = IvrMenu::factory()->create([
        'name' => 'Support IVR',
        'greeting' => 'Welcome to support',
        'timeout' => 20,
        'max_failures' => 5,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusEdit::class, ['menuUuid' => $menu->id])
        ->assertOk()
        ->assertSee('Edit IVR Menu')
        ->assertSet('name', 'Support IVR')
        ->assertSet('greeting', 'Welcome to support')
        ->assertSet('timeout', 20)
        ->assertSet('maxFailures', 5);
});

it('creates a new IVR menu', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Sales IVR')
        ->set('timeout', 15)
        ->set('maxFailures', 3)
        ->set('digitLength', 0)
        ->call('save')
        ->assertRedirect(route('panel.ivr-menus.index'));

    $this->assertDatabaseHas('ivr_menus', [
        'name' => 'Sales IVR',
        'greeting' => null,
    ]);
});

it('updates an existing IVR menu', function () {
    $menu = IvrMenu::factory()->create(['name' => 'Old IVR', 'timeout' => 10]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusEdit::class, ['menuUuid' => $menu->id])
        ->set('name', 'Updated IVR')
        ->set('timeout', 25)
        ->call('save')
        ->assertRedirect(route('panel.ivr-menus.index'));

    $this->assertDatabaseHas('ivr_menus', [
        'id' => $menu->id,
        'name' => 'Updated IVR',
        'timeout' => 25,
    ]);
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates name is unique per tenant', function () {
    IvrMenu::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Existing IVR',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Existing IVR')
        ->call('save')
        ->assertHasErrors(['name']);
});

it('validates timeout is at least 1', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Test IVR')
        ->set('timeout', 0)
        ->call('save')
        ->assertHasErrors(['timeout' => 'min']);
});
