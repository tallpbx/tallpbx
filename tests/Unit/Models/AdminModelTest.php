<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

it('detects Super Administrators membership via isSuperAdmin', function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superGroup = Group::where('name', 'Super Administrators')->whereNull('tenant_id')->firstOrFail();

    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($superGroup->id);

    expect($admin->isSuperAdmin())->toBeTrue();
});

it('detects non-super administrator via isSuperAdmin', function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $adminGroup = Group::where('name', 'Administrators')->whereNull('tenant_id')->firstOrFail();

    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($adminGroup->id);

    expect($admin->isSuperAdmin())->toBeFalse();
});

it('grants all permissions to super administrators in hasPermission and getPermissionNames', function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superGroup = Group::where('name', 'Super Administrators')->whereNull('tenant_id')->firstOrFail();

    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($superGroup->id);

    expect($admin->hasPermission('admin.users.view'))->toBeTrue()
        ->and($admin->hasPermission('extensions.view'))->toBeTrue()
        ->and($admin->hasPermission('nonexistent.permission'))->toBeFalse()
        ->and($admin->getPermissionNames())->toContain('admin.users.view')
        ->and($admin->getPermissionNames())->toContain('extensions.view');
});

it('only grants assigned permissions to regular administrators', function () {
    $group = Group::factory()->system()->create(['name' => 'Support']);
    $perm = Permission::firstOrCreate(['name' => 'extensions.view'], ['module' => 'extensions']);
    $group->permissions()->attach($perm->id);

    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($group->id);

    expect($admin->isSuperAdmin())->toBeFalse()
        ->and($admin->hasPermission('extensions.view'))->toBeTrue()
        ->and($admin->hasPermission('admin.users.view'))->toBeFalse();
});
