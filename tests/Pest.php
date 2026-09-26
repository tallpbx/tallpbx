<?php

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\DuskTestCase;
use Tests\TestCase;

pest()->extend(DuskTestCase::class)
    ->use(Tests\Browser\Concerns\InteractsWithAuthentication::class)
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(LazilyRefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->in('Unit/Jobs');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Create an admin with common admin panel permissions.
 *
 * Grants the given list of permission names via a system group.
 * Defaults to panel.dashboard.view if no permissions specified.
 *
 * @param  Admin|null  $admin  Optional existing admin to grant permissions to
 * @param  array<int, string>  $permissions  Permission names to grant
 */
function grantAdminPermissions(?Admin $admin = null, array $permissions = ['panel.dashboard.view']): Admin
{
    $admin ??= Admin::factory()->create(['enabled' => true]);

    $group = Group::factory()->system()->create(['name' => 'Test Permissions Group']);

    foreach ($permissions as $name) {
        $module = explode('.', $name)[0] ?? 'test';
        $perm = Permission::firstOrCreate(
            ['name' => $name],
            [
                'module' => $module,
                'description' => fake()->sentence(),
            ]
        );
        $group->permissions()->attach($perm);
    }

    $admin->groups()->syncWithoutDetaching([$group->id]);

    return $admin;
}

/**
 * Create a tenant user, attach it to the tenant, and set the tenant context.
 */
function tenantUser(Tenant $tenant): User
{
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($tenant->id, ['role' => 'member', 'primary' => true]);
    app(TenantManager::class)->setTenantId((string) $tenant->id);

    return $user;
}

/**
 * Create a tenant user, attach it to the tenant, set tenant context, and grant permissions via a tenant group.
 *
 * @param  Tenant  $tenant  The tenant context
 * @param  array<int, string>  $permissions  Permissions to grant (excluding admin.*)
 * @param  User|null  $user  Optional existing user
 */
function grantTenantUserPermissions(Tenant $tenant, array $permissions = [], ?User $user = null): User
{
    $user ??= User::factory()->create(['enabled' => true]);

    if (! $user->tenants()->where('tenants.id', $tenant->id)->exists()) {
        $user->tenants()->attach($tenant->id, ['role' => 'member', 'primary' => true]);
    }

    app(TenantManager::class)->setTenantId((string) $tenant->id);

    if (! empty($permissions)) {
        $group = Group::factory()->forTenant($tenant->id)->create([
            'name' => 'Test Tenant Group ' . \Illuminate\Support\Str::random(6),
        ]);

        foreach ($permissions as $name) {
            $module = explode('.', $name)[0] ?? 'test';
            $perm = Permission::firstOrCreate(
                ['name' => $name],
                [
                    'module' => $module,
                    'description' => fake()->sentence(),
                ]
            );
            $group->permissions()->attach($perm);
        }

        $user->groups()->syncWithoutDetaching([$group->id]);
    }

    return $user;
}
