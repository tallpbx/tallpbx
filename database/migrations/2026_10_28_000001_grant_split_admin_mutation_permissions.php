<?php

declare(strict_types=1);

use App\Models\Group;
use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The four split mutation permissions introduced to replace broad view-only grants.
     *
     * @var array<string, array{module: string, description: string}>
     */
    private const PERMISSIONS = [
        'admin.settings.update' => [
            'module' => 'admin',
            'description' => 'Create and edit application settings',
        ],
        'admin.settings.delete' => [
            'module' => 'admin',
            'description' => 'Delete application settings',
        ],
        'admin.git-update.execute' => [
            'module' => 'admin',
            'description' => 'Fetch remote refs and execute git updates',
        ],
        'admin.queue.retry' => [
            'module' => 'admin',
            'description' => 'Retry failed queue jobs',
        ],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('groups') || ! Schema::hasTable('group_permission')) {
            return;
        }

        $created = [];

        foreach (self::PERMISSIONS as $name => $attributes) {
            $created[$name] = Permission::firstOrCreate(
                ['name' => $name],
                $attributes,
            );
        }

        $superAdminGroup = Group::where('name', 'Super Administrators')
            ->whereNull('tenant_id')
            ->first();

        if ($superAdminGroup !== null) {
            foreach ($created as $permission) {
                if (! $superAdminGroup->permissions()->where('permissions.id', $permission->id)->exists()) {
                    $superAdminGroup->permissions()->attach($permission->id);
                }
            }
        }

        $adminGroup = Group::where('name', 'Administrators')
            ->whereNull('tenant_id')
            ->first();

        if ($adminGroup !== null && isset($created['admin.queue.retry'])) {
            $retryPermission = $created['admin.queue.retry'];

            if (! $adminGroup->permissions()->where('permissions.id', $retryPermission->id)->exists()) {
                $adminGroup->permissions()->attach($retryPermission->id);
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

        $permissions = Permission::whereIn('name', array_keys(self::PERMISSIONS))->get();

        foreach ($permissions as $permission) {
            $permission->groups()->detach();
            $permission->delete();
        }
    }
};
