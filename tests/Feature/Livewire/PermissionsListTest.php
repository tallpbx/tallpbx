<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Module;
use App\Models\Permission;
use App\Services\PermissionService;
use Livewire\Livewire;
use Modules\Admin\Livewire\PermissionsList;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    app(PermissionService::class)->reset();
});

it('renders the permissions list as a read-only reference', function () {
    $permService = app(PermissionService::class);
    $permService->register('test', ['test.view', 'test.create']);
    $permService->syncToDatabase();

    Livewire::actingAs($this->admin, 'admin')
        ->test(PermissionsList::class)
        ->assertOk()
        ->assertSee('Permissions')
        ->assertSee('test.view')
        ->assertSee('test.create');
});

it('shows permissions grouped by module', function () {
    $permService = app(PermissionService::class);
    $permService->register('module-a', ['module-a.view']);
    $permService->register('module-b', ['module-b.view', 'module-b.create']);
    $permService->syncToDatabase();

    Livewire::actingAs($this->admin, 'admin')
        ->test(PermissionsList::class)
        ->assertOk()
        ->assertSee('module-a')
        ->assertSee('module-b');
});

it('auto-syncs permissions to database on mount', function () {
    $permService = app(PermissionService::class);
    $permService->register('test', ['test.view', 'test.create']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(PermissionsList::class);

    $this->assertDatabaseHas('permissions', ['name' => 'test.view']);
    $this->assertDatabaseHas('permissions', ['name' => 'test.create']);
});

it('shows link to groups page for permission assignment', function () {
    $permService = app(PermissionService::class);
    $permService->register('test', ['test.view']);
    $permService->syncToDatabase();

    Livewire::actingAs($this->admin, 'admin')
        ->test(PermissionsList::class)
        ->assertSee('Groups');
});

it('displays permission descriptions', function () {
    $permService = app(PermissionService::class);
    $permService->register('test', [
        'test.view' => 'View test entities',
    ]);
    $permService->syncToDatabase();

    Livewire::actingAs($this->admin, 'admin')
        ->test(PermissionsList::class)
        ->assertSee('View test entities');
});

it('hides disabled module permissions from the permission reference', function () {
    Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0.0',
        'enabled' => false,
    ]);

    Permission::factory()->create(['name' => 'extensions.view', 'module' => 'extensions']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(PermissionsList::class)
        ->assertOk()
        ->assertDontSee('extensions.view');
});
