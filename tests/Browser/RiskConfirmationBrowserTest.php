<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;

beforeEach(function () {
    User::where('email', 'doomed@example.com')->delete();
    Tenant::where('name', 'Typed Tenant')->delete();

    $this->admin = Admin::firstOrCreate(
        ['email' => 'admin@risk.test'],
        ['name' => 'Risk Test Admin', 'password' => bcrypt('risk-secret'), 'enabled' => true],
    );

    $group = Group::firstOrCreate(['name' => 'Risk Test Group'], ['system' => true]);

    foreach (['admin.tenants.view', 'admin.tenants.delete', 'admin.users.view', 'admin.users.delete'] as $permissionName) {
        $permission = Permission::firstOrCreate(
            ['name' => $permissionName],
            ['module' => 'admin', 'description' => 'Risk permission for '.$permissionName],
        );
        $group->permissions()->syncWithoutDetaching([$permission->id]);
    }

    $this->admin->groups()->syncWithoutDetaching([$group->id]);
});

afterEach(function () {
    $this->admin->groups()->detach();
    $this->admin->delete();
    Group::where('name', 'Risk Test Group')->delete();
});

it('requires typing the tenant name before deleting a tenant', function () {
    $tenant = Tenant::factory()->create(['name' => 'Typed Tenant']);

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/tenants');
    $page->assertSee('Typed Tenant')
        ->click('button[wire\\:click="confirmTenantDeletion('.$tenant->id.')"]')
        ->assertSee('Delete Tenant?')
        ->assertSee('Type the tenant name to confirm')
        ->assertButtonDisabled('button.btn-error')
        ->fill('input[type="text"]', 'Typed Tenant')
        ->click('button.btn-error')
        ->assertSee('Tenant deleted.');

    expect(Tenant::find($tenant->id))->toBeNull();
});

it('deletes a user through the shared confirmation modal', function () {
    $user = User::factory()->create(['email' => 'doomed@example.com']);

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/users');
    $page->assertSee('doomed@example.com')
        ->click('button[wire\\:click="confirmUserDeletion('.$user->id.')"]')
        ->assertSee('Delete User?')
        ->assertSee('The user loses panel access')
        ->click('button.btn-error')
        ->assertSee('User deleted.');

    expect(User::find($user->id))->toBeNull();
});
