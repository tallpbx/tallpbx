<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;
use Livewire\Livewire;
use Modules\Admin\Livewire\AdminsList;

beforeEach(function (): void {
    $this->seed(AdminSeeder::class);
    $this->admin = Admin::factory()->create([
        'name' => 'Root Admin',
        'email' => 'root@example.com',
        'enabled' => true,
    ]);

    $superGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin->groups()->attach($superGroup->id);
});

it('lists all administrators', function (): void {
    Admin::factory()->create([
        'name' => 'Second Admin',
        'email' => 'second@example.com',
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsList::class)
        ->assertSee('Root Admin')
        ->assertSee('root@example.com')
        ->assertSee('Second Admin')
        ->assertSee('second@example.com');
});

it('prevents self-deletion of the authenticated admin', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsList::class)
        ->call('confirmAdminDeletion', $this->admin->id)
        ->assertSet('pendingDeletionId', null)
        ->assertSee(__('admin.admin_delete_self_error'));
});

it('prevents deleting the last super administrator', function (): void {
    $secondAdmin = Admin::factory()->create([
        'name' => 'Regular Admin',
        'email' => 'regular@example.com',
        'enabled' => true,
    ]);
    // root is the only super admin

    Livewire::actingAs($secondAdmin, 'admin')
        ->test(AdminsList::class)
        ->call('confirmAdminDeletion', $this->admin->id)
        ->assertSet('pendingDeletionId', null)
        ->assertSee(__('admin.admin_delete_last_super_error'));
});

it('deletes an administrator when allowed', function (): void {
    $otherSuperAdmin = Admin::factory()->create([
        'name' => 'Other Super Admin',
        'email' => 'other_super@example.com',
        'enabled' => true,
    ]);
    $superGroup = Group::where('name', 'Super Administrators')->first();
    $otherSuperAdmin->groups()->attach($superGroup->id);

    $targetAdmin = Admin::factory()->create([
        'name' => 'To Delete',
        'email' => 'delete_me@example.com',
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AdminsList::class)
        ->call('confirmAdminDeletion', $targetAdmin->id)
        ->assertSet('pendingDeletionId', $targetAdmin->id)
        ->call('deleteAdmin')
        ->assertDispatched('admin-deleted');

    expect(Admin::find($targetAdmin->id))->toBeNull();
});
