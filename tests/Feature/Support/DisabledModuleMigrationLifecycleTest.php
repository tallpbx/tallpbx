<?php

declare(strict_types=1);

use App\Models\Module;
use App\Services\MenuService;
use App\Services\ModuleState;
use App\Services\PermissionService;
use App\Support\ModuleServiceProvider;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/pbx-module-lifecycle-'.bin2hex(random_bytes(8));
    $this->migrationsDir = $this->sandbox.'/app-modules/demo-lifecycle/database/migrations';

    File::makeDirectory($this->migrationsDir, 0755, true);
    File::put($this->migrationsDir.'/2026_01_01_000000_create_demo_lifecycle_table.php', '<?php // migration stub');

    ModuleState::setForceAllModulesMigrations(null);
});

afterEach(function () {
    ModuleState::setForceAllModulesMigrations(null);
    File::deleteDirectory($this->sandbox);
});

it('registers migrations when module is enabled', function (): void {
    $migrator = app('migrator');
    $initialPaths = $migrator->paths();

    $mockModuleState = Mockery::mock(ModuleState::class);
    $mockModuleState->shouldReceive('isEnabled')->with('demo-lifecycle')->andReturn(true);
    $mockModuleState->shouldReceive('shouldMigrateAll')->andReturn(false);

    $provider = createDemoLifecycleProvider($this->sandbox.'/app-modules/demo-lifecycle');
    $provider->boot(app(MenuService::class), app(PermissionService::class), $mockModuleState);

    expect($migrator->paths())->toContain($this->migrationsDir);
});

it('omits migrations when module is disabled', function (): void {
    $migrator = app('migrator');
    $initialCount = count($migrator->paths());

    $mockModuleState = Mockery::mock(ModuleState::class);
    $mockModuleState->shouldReceive('isEnabled')->with('demo-lifecycle')->andReturn(false);
    $mockModuleState->shouldReceive('shouldMigrateAll')->andReturn(false);

    $provider = createDemoLifecycleProvider($this->sandbox.'/app-modules/demo-lifecycle');
    $provider->boot(app(MenuService::class), app(PermissionService::class), $mockModuleState);

    expect($migrator->paths())->not->toContain($this->migrationsDir)
        ->and(count($migrator->paths()))->toBe($initialCount);
});

it('registers migrations for disabled modules when shouldMigrateAll is true', function (): void {
    $migrator = app('migrator');

    $mockModuleState = Mockery::mock(ModuleState::class);
    $mockModuleState->shouldReceive('isEnabled')->with('demo-lifecycle')->andReturn(false);
    $mockModuleState->shouldReceive('shouldMigrateAll')->andReturn(true);

    $provider = createDemoLifecycleProvider($this->sandbox.'/app-modules/demo-lifecycle');
    $provider->boot(app(MenuService::class), app(PermissionService::class), $mockModuleState);

    expect($migrator->paths())->toContain($this->migrationsDir);
});

it('honors ModuleState::setForceAllModulesMigrations programmatic override', function (): void {
    $moduleState = new ModuleState;

    ModuleState::setForceAllModulesMigrations(true);
    expect($moduleState->shouldMigrateAll())->toBeTrue();

    ModuleState::setForceAllModulesMigrations(false);
    expect($moduleState->shouldMigrateAll())->toBeFalse();

    ModuleState::setForceAllModulesMigrations(null);
    // In testing without CLI argument, defaults to false
    expect($moduleState->shouldMigrateAll())->toBeFalse();
});

it('detects --all-modules CLI parameter in shouldMigrateAll', function (): void {
    $moduleState = new ModuleState;

    $originalArgv = $_SERVER['argv'] ?? [];
    try {
        $_SERVER['argv'] = ['artisan', 'migrate', '--all-modules'];
        expect($moduleState->shouldMigrateAll())->toBeTrue();

        $_SERVER['argv'] = ['artisan', 'migrate'];
        expect($moduleState->shouldMigrateAll())->toBeFalse();
    } finally {
        $_SERVER['argv'] = $originalArgv;
    }
});

it('accepts --all-modules flag on migrate command and populates migrator paths', function (): void {
    $migrator = app('migrator');

    // Calling migrate with --all-modules and --pretend should succeed without unrecognized option error
    $exitCode = Artisan::call('migrate', [
        '--all-modules' => true,
        '--pretend' => true,
    ]);

    expect($exitCode)->toBe(0)
        ->and(count($migrator->paths()))->toBeGreaterThan(10);
});

it('provides allMigrationPaths returning existing migration directories', function (): void {
    $paths = ModuleServiceProvider::allMigrationPaths();

    expect($paths)->toBeArray()
        ->and($paths)->not->toBeEmpty();

    foreach ($paths as $path) {
        expect(is_dir($path))->toBeTrue()
            ->and(str_ends_with($path, 'database/migrations'))->toBeTrue();
    }
});

/**
 * Test-only module provider pointing at the sandbox directory.
 */
function createDemoLifecycleProvider(string $sandboxPath): ModuleServiceProvider
{
    return new class(app(), $sandboxPath) extends ModuleServiceProvider
    {
        /**
         * Initialize the provider with application and sandbox path.
         */
        public function __construct(Application $app, private readonly string $sandboxPath)
        {
            parent::__construct($app);
        }

        /**
         * Return the test module name.
         */
        protected function moduleName(): string
        {
            return 'demo-lifecycle';
        }

        /**
         * Return the test module namespace.
         */
        protected function moduleNamespace(): string
        {
            return 'Modules\\DemoLifecycle';
        }

        /**
         * Return the test sandbox path.
         */
        protected function modulePath(): string
        {
            return $this->sandboxPath;
        }
    };
}
