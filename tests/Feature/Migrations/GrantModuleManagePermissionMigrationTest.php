<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;

test('grant module manage permission migration idempotently attaches permission to admin groups', function (): void {
    $superAdminGroup = Group::firstOrCreate(
        ['name' => 'Super Administrators', 'tenant_id' => null],
        ['description' => 'Full system access.']
    );

    $adminGroup = Group::firstOrCreate(
        ['name' => 'Administrators', 'tenant_id' => null],
        ['description' => 'Admin access.']
    );

    $tenant = Tenant::factory()->create();
    $tenantGroup = Group::create([
        'name' => 'Administrators',
        'tenant_id' => $tenant->id,
        'description' => 'Tenant-scoped admin group.',
    ]);

    $migration = require database_path('migrations/2026_10_18_000002_grant_module_manage_permission_to_super_admins.php');

    // Run migration
    $migration->up();

    $permission = Permission::where('name', 'admin.modules.manage')->first();
    expect($permission)->not->toBeNull()
        ->and($permission->module)->toBe('admin');

    expect($superAdminGroup->fresh()->permissions->contains('id', $permission->id))->toBeTrue()
        ->and($adminGroup->fresh()->permissions->contains('id', $permission->id))->toBeTrue()
        ->and($tenantGroup->fresh()->permissions->contains('id', $permission->id))->toBeFalse();

    // Idempotency: second run does not duplicate or fail
    $migration->up();

    expect($superAdminGroup->fresh()->permissions()->where('permissions.id', $permission->id)->count())->toBe(1);

    // Rollback detaches permission
    $migration->down();
    expect($superAdminGroup->fresh()->permissions->contains('id', $permission->id))->toBeFalse()
        ->and($adminGroup->fresh()->permissions->contains('id', $permission->id))->toBeFalse();
});

test('grant module manage permission migration rollback deletes the permission row it created', function (): void {
    // Control row: must survive the rollback untouched.
    $control = Permission::firstOrCreate(['name' => 'admin.users.view', 'module' => 'admin']);

    $migration = require database_path('migrations/2026_10_18_000002_grant_module_manage_permission_to_super_admins.php');
    $migration->up();
    $migration->down();

    // Rollback symmetry: the row this migration created goes away with it.
    expect(Permission::where('name', 'admin.modules.manage')->exists())->toBeFalse()
        ->and($control->fresh()?->id)->toBe($control->id);
});
