<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\PermissionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Synchronize registered module permissions to the database.
 */
class SyncPermissionsCommand extends Command
{
    protected $signature = 'permissions:sync';

    protected $description = 'Synchronize registered module permissions to the database';

    /**
     * Execute the console command.
     */
    public function handle(PermissionService $permissionService): int
    {
        $this->components->info('Synchronizing registered permissions to the database...');

        $permissionService->syncToDatabase();
        Cache::forever('permissions.sync.hash', $permissionService->registryHash());

        $this->components->info('Permissions synchronized successfully.');

        return self::SUCCESS;
    }
}
