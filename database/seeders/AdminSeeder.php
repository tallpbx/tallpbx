<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\Permission;
use App\Services\InitialAdminProvisioner;
use App\Services\PermissionService;
use Illuminate\Database\Seeder;

/**
 * Seeds admin accounts and default permission groups.
 *
 * Creates two system groups:
 *   - Super Administrators: every permission (manages the system itself)
 *   - Administrators: PBX management permissions, but cannot manage other
 *     admins, tenants, system settings, or impersonate.
 *
 * The initial administrator is intentionally created later by
 * InitialAdminProvisioner. This lets the installer and browser setup page use
 * one credential-creation path without a built-in default password.
 */
class AdminSeeder extends Seeder
{
    /**
     * Run the seeder — sync permissions, create groups, and initialize setup state.
     */
    public function run(InitialAdminProvisioner $initialAdminProvisioner): void
    {
        // Sync all module-registered permissions to the database
        $permService = app(PermissionService::class);
        $permService->syncToDatabase();

        // ── Super Administrators: full system access ──────────────────────────
        $superAdminGroup = Group::firstOrCreate(
            ['name' => 'Super Administrators', 'tenant_id' => null],
            ['description' => 'Full system access — manage admins, tenants, settings, impersonation, and all PBX.'],
        );

        $allPermissionIds = Permission::pluck('id');
        $superAdminGroup->permissions()->sync($allPermissionIds);

        // ── Administrators: PBX management, no system-level access ────────────
        $adminGroup = Group::firstOrCreate(
            ['name' => 'Administrators', 'tenant_id' => null],
            ['description' => 'Full PBX management without system-level admin or tenant management.'],
        );

        // Exclude only admin-user management from the Administrator group
        $adminPermissionIds = Permission::whereNotIn('name', [
            'admin.users.view', 'admin.users.create', 'admin.users.update', 'admin.users.delete',
            'backups.restore',
        ])->pluck('id');

        $adminGroup->permissions()->sync($adminPermissionIds);

        // Persist the initial setup state after its required permissions and
        // group exist. Re-runs preserve this state and never create an admin.
        $initialAdminProvisioner->ensureSetupState();
    }
}
