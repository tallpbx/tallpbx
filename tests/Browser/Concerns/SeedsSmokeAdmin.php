<?php

declare(strict_types=1);

namespace Tests\Browser\Concerns;

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;

/**
 * Trait SeedsSmokeAdmin
 *
 * Provides standard module permission synchronization, AdminSeeder execution,
 * and Super Administrator group attachment for browser smoke tests.
 */
trait SeedsSmokeAdmin
{
    /**
     * The Super Administrators group model instance.
     */
    protected ?Group $superAdminGroup = null;

    /**
     * The dedicated smoke test administrator account.
     */
    protected ?Admin $admin = null;

    /**
     * Setup the smoke test administrator with full super-admin panel permissions.
     *
     * Mirrors the production permission setup so the smoke admin can access every panel
     * route without authorization exceptions: syncs module states, seeds AdminSeeder
     * to grant all permissions to the Super Administrators group, and attaches the admin.
     */
    protected function setUpSmokeAdmin(): void
    {
        $this->artisan('module:sync --only-local');
        $this->seed(AdminSeeder::class);

        $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();

        $this->admin = Admin::firstOrCreate(
            ['email' => 'admin@smoke.test'],
            ['name' => 'Smoke Test Admin', 'password' => bcrypt('smoke-secret'), 'enabled' => true],
        );

        if ($this->superAdminGroup !== null) {
            $this->admin->groups()->syncWithoutDetaching([$this->superAdminGroup->id]);
        }
    }
}
