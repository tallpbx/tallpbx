<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\ApplicationFilePermissions;
use App\Support\PermissionRepairScope;
use Illuminate\Console\Command;
use ValueError;

/**
 * Repairs TallPBX application file permissions at a selected scope.
 */
class RepairApplicationPermissionsCommand extends Command
{
    protected $signature = 'permissions:repair {--scope=full : generated, runtime, or full}';

    protected $description = 'Repair TallPBX application file ownership and permissions';

    /**
     * Execute the selected permission repair scope.
     */
    public function handle(ApplicationFilePermissions $permissions): int
    {
        try {
            $scope = PermissionRepairScope::from((string) $this->option('scope'));
        } catch (ValueError) {
            $this->components->error('The --scope option must be generated, runtime, or full.');

            return self::FAILURE;
        }

        $permissions->repair($scope);
        $this->components->info("{$scope->value} permission repair completed.");

        return self::SUCCESS;
    }
}
