<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\IvrMenus\Livewire\IvrMenusList;
use Modules\IvrMenus\Models\IvrMenu;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the IVR menus list component', function () {
    IvrMenu::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusList::class)
        ->assertOk()
        ->assertSee('IVR Menus')
        ->assertViewHas('menus', function ($menus) {
            return $menus->count() === 3;
        });
});

it('displays IVR menu name and timeout', function () {
    IvrMenu::factory()->create([
        'name' => 'Main IVR',
        'timeout' => 15,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusList::class)
        ->assertSee('Main IVR')
        ->assertSee('15s');
});

it('deletes an IVR menu', function () {
    $menu = IvrMenu::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusList::class)
        ->call('deleteMenu', $menu->id)
        ->assertDispatched('menu-deleted');

    $this->assertModelMissing($menu);
});

it('opens the shared confirmation modal before deleting an IVR menu', function (): void {
    $menu = IvrMenu::factory()->create(['name' => 'Main menu']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusList::class)
        ->call('confirmMenuDeletion', $menu->id)
        ->assertSet('pendingDeletionId', $menu->id)
        ->assertSet('pendingDeletionName', 'Main menu')
        ->assertSee('Delete IVR Menu?');
});

it('shows empty state when no IVR menus exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusList::class)
        ->assertSee('No IVR menus configured yet');
});

it('shows enabled status badge', function () {
    IvrMenu::factory()->create([
        'name' => 'Enabled IVR',
        'enabled' => true,
    ]);
    IvrMenu::factory()->create([
        'name' => 'Disabled IVR',
        'enabled' => false,
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(IvrMenusList::class);

    $menus = $component->viewData('menus');
    $enabled = $menus->firstWhere('name', 'Enabled IVR');
    $disabled = $menus->firstWhere('name', 'Disabled IVR');

    expect($enabled->enabled)->toBeTrue()
        ->and($disabled->enabled)->toBeFalse();
});
