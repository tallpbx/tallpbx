<?php

declare(strict_types=1);

use App\Models\Group;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('groups') || ! Schema::hasTable('group_permission')) {
            return;
        }

        $permission = Permission::firstOrCreate(
            ['name' => 'admin.modules.manage'],
            [
                'module' => 'admin',
                'description' => 'Manage and uninstall modules',
            ]
        );

        $groups = Group::whereIn('name', ['Super Administrators', 'Administrators'])
            ->whereNull('tenant_id')
            ->get();

        foreach ($groups as $group) {
            if (! $group->permissions()->where('permissions.id', $permission->id)->exists()) {
                $group->permissions()->attach($permission->id);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('group_permission')) {
            return;
        }

        $permission = Permission::where('name', 'admin.modules.manage')->first();

        if ($permission !== null) {
            // Rollback symmetry: detach the grant, then remove the permission row
            // this migration created. Unrelated permissions are never touched.
            $permission->groups()->detach();
            $permission->delete();
        }
    }
};
