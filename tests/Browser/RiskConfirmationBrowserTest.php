<?php

declare(strict_types=1);

/**
 * Dusk browser checks for the risk-specific confirmation flows.
 *
 * One typed flow (tenant deletion requires typing the tenant name)
 * and one moderate flow (user deletion) prove the shared modal and
 * its typed mode behave correctly in a real browser.
 */

namespace Tests\Browser;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Dusk\Browser;

beforeEach(function () {
    // The dusk database persists across runs; remove this suite's fixtures so
    // each run starts clean and row-based selectors target the fresh records.
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
            ['module' => 'admin', 'description' => 'Dusk risk permission for '.$permissionName],
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

    $this->browse(function (Browser $browser) use ($tenant) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/tenants')
            ->waitForText('Typed Tenant', 5)
            ->press('button[wire\\:click="confirmTenantDeletion('.$tenant->id.')"]')
            ->waitForText('Delete Tenant?', 5)
            ->assertSee('Type the tenant name to confirm')
            ->assertButtonDisabled('button.btn-error')
            ->type('input[type="text"]', 'Typed Tenant')
            ->pause(500)
            ->press('button.btn-error')
            ->waitForText('Tenant deleted.', 5);
    });

    expect(Tenant::find($tenant->id))->toBeNull();
});

it('deletes a user through the shared confirmation modal', function () {
    $user = User::factory()->create(['email' => 'doomed@example.com']);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/users')
            ->waitForText('doomed@example.com', 5)
            ->press('button[wire\\:click="confirmUserDeletion('.$user->id.')"]')
            ->waitForText('Delete User?', 5)
            ->assertSee('The user loses panel access')
            ->press('button.btn-error')
            ->waitForText('User deleted.', 5);
    });

    expect(User::find($user->id))->toBeNull();
});
