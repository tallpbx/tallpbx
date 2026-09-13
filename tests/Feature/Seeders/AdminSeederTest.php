<?php

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use Database\Seeders\AdminSeeder;

it('seeds the super administrators group without a default administrator account', function () {
    $this->seed(AdminSeeder::class);

    $group = Group::query()->where('name', 'Super Administrators')->first();

    expect($group)->not->toBeNull()
        ->and(Admin::query()->exists())->toBeFalse();
});

it('preserves an existing administrator without adding it to a group', function (): void {
    $existingAdmin = Admin::factory()->create(['email' => 'custom-admin@example.test']);

    $this->seed(AdminSeeder::class);

    expect(Admin::query()->count())->toBe(1)
        ->and($existingAdmin->refresh()->groups()->exists())->toBeFalse();
});

it('assigns all seeded permissions to the super administrators group', function () {
    $this->seed(AdminSeeder::class);

    $group = Group::query()->where('name', 'Super Administrators')->firstOrFail();

    expect($group->permissions()->count())->toBe(Permission::count())
        ->and($group->permissions()->where('name', 'admin.dashboard.view')->exists())->toBeTrue()
        ->and($group->permissions()->where('name', 'extensions.view')->exists())->toBeTrue();
});
