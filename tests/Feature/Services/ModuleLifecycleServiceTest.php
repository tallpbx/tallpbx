<?php

declare(strict_types=1);

use App\Contracts\ModuleUninstaller;
use App\Models\Module;
use App\Models\Permission;
use App\Services\ModuleLifecycleService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Build a hermetic sandbox that mirrors the project layout, so tests
    // never touch the real app-modules/ tree or composer.json.
    $this->sandbox = sys_get_temp_dir().'/pbx-lifecycle-'.bin2hex(random_bytes(8));

    File::makeDirectory($this->sandbox.'/app-modules/demo-module', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
        'name' => 'demo-module',
        'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule',
        'display_name' => 'Demo Module',
        'required' => false,
        'protected' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    File::put($this->sandbox.'/composer.json', json_encode([
        'repositories' => [['type' => 'path', 'url' => 'app-modules/demo-module']],
        'require' => ['tallpbx/module-demo-module' => '*'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $this->composerCalls = [];
    $this->gitCalls = [];

    $this->service = sandboxService($this->sandbox, $this->composerCalls, $this->gitCalls, gitTracked: true);
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('exposes the uninstall confirmation phrase', function (): void {
    expect($this->service->confirmationPhrase('demo-module'))->toBe('UNINSTALL demo-module');
});

it('refuses modules that are neither local nor vendor', function (): void {
    expect(fn () => $this->service->uninstall('ghost-module', 'UNINSTALL ghost-module'))
        ->toThrow(ValidationException::class);
});

it('refuses invalid module names', function (): void {
    expect(fn () => $this->service->uninstall('../evil', 'UNINSTALL ../evil'))
        ->toThrow(ValidationException::class);
});

it('refuses required or protected modules', function (): void {
    File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
        'required' => false, 'protected' => true,
    ]));

    expect(fn () => $this->service->uninstall('demo-module', 'UNINSTALL demo-module'))
        ->toThrow(ValidationException::class);
});

it('refuses a mismatched confirmation phrase', function (): void {
    expect(fn () => $this->service->uninstall('demo-module', 'not the phrase'))
        ->toThrow(ValidationException::class);

    expect(File::exists($this->sandbox.'/app-modules/demo-module'))->toBeTrue();
});

it('uninstalls a local module completely but keeps the registry marker', function (): void {
    Module::create(['name' => 'demo-module', 'display_name' => 'Demo Module', 'version' => '1.0.0']);
    Permission::create(['name' => 'demo-module.view', 'module' => 'demo-module']);

    $report = $this->service->uninstall('demo-module', 'UNINSTALL demo-module');

    expect($report['module_kind'])->toBe('local')
        ->and($report['module_dir_deleted'])->toBeTrue()
        ->and($report['composer_package_removed'])->toBeTrue()
        ->and($report['repository_entry_removed'])->toBeTrue()
        ->and($report['require_entry_removed'])->toBeTrue()
        ->and($report['permissions_deleted'])->toBe(1)
        ->and($report['uninstall_ran'])->toBeFalse()
        ->and($this->composerCalls)->toBe(['remove tallpbx/module-demo-module --no-interaction'])
        ->and(File::exists($this->sandbox.'/app-modules/demo-module'))->toBeFalse()
        ->and(Permission::where('module', 'demo-module')->exists())->toBeFalse()
        ->and($report['warnings'])->not->toBeEmpty()
        ->and($report['restore_hint'])->toContain('module:restore demo-module');

    $composer = json_decode((string) file_get_contents($this->sandbox.'/composer.json'), true);

    expect($composer['repositories'])->toBe([])
        ->and($composer['require'])->not->toHaveKey('tallpbx/module-demo-module');

    // The registry marker row is what makes restore possible.
    $this->assertDatabaseHas('modules', [
        'name' => 'demo-module',
        'enabled' => false,
        'status' => Module::StatusUninstalled,
        'composer_package' => 'tallpbx/module-demo-module',
    ]);
});

it('removes the composer package before deleting the local module directory', function (): void {
    $moduleExistedDuringComposerRemove = null;

    // Composer needs the path-repository source to resolve the package;
    // deleting the directory first would break the removal and leave the
    // autoloader pointing at a missing directory.
    $service = new ModuleLifecycleService(
        app(),
        app(Filesystem::class),
        $this->sandbox,
        function (array $args) use (&$moduleExistedDuringComposerRemove): bool {
            if ($args[0] === 'remove') {
                $moduleExistedDuringComposerRemove = is_dir($this->sandbox.'/app-modules/demo-module');
            }

            return true;
        },
        fn (array $args): bool => true,
    );

    $service->uninstall('demo-module', 'UNINSTALL demo-module');

    expect($moduleExistedDuringComposerRemove)->toBeTrue();
});

it('runs the module uninstall handler when one is registered', function (): void {
    $uninstaller = testDemoUninstaller();
    registerDemoUninstaller($uninstaller);
    Module::create(['name' => 'demo-module', 'display_name' => 'Demo Module', 'version' => '1.0.0']);

    $report = $this->service->uninstall('demo-module', 'UNINSTALL demo-module');

    expect($report['uninstall_ran'])->toBeTrue()
        ->and($uninstaller->uninstalled)->toBeTrue();
});

it('uninstalls a vendor module through Composer without touching host files', function (): void {
    File::deleteDirectory($this->sandbox.'/app-modules/demo-module');
    File::makeDirectory($this->sandbox.'/vendor/acme/demo-package', 0755, true);
    File::put($this->sandbox.'/vendor/acme/demo-package/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
        'required' => false, 'protected' => false,
    ]));

    Module::create(['name' => 'demo-module', 'display_name' => 'Demo Module', 'version' => '1.0.0']);
    Permission::create(['name' => 'demo-module.view', 'module' => 'demo-module']);

    $this->service = sandboxService($this->sandbox, $this->composerCalls, $this->gitCalls, gitTracked: false);

    $report = $this->service->uninstall('demo-module', 'UNINSTALL demo-module');

    expect($report['module_kind'])->toBe('vendor')
        ->and($report['module_dir_deleted'])->toBeFalse()
        ->and($report['repository_entry_removed'])->toBeFalse()
        ->and($this->composerCalls)->toBe(['remove acme/demo-package --no-interaction'])
        // Vendor files belong to Composer: the service leaves them for composer remove.
        ->and(File::exists($this->sandbox.'/vendor/acme/demo-package/module.json'))->toBeTrue();

    $composer = json_decode((string) file_get_contents($this->sandbox.'/composer.json'), true);

    expect($composer['repositories'])->toHaveCount(1)
        ->and($composer['require'])->toHaveKey('tallpbx/module-demo-module');

    $this->assertDatabaseHas('modules', [
        'name' => 'demo-module',
        'status' => Module::StatusUninstalled,
        'composer_package' => 'acme/demo-package',
    ]);
});

it('prefers the local directory when a module exists both locally and in vendor', function (): void {
    File::makeDirectory($this->sandbox.'/vendor/acme/demo-package', 0755, true);
    File::put($this->sandbox.'/vendor/acme/demo-package/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
    ]));

    $preview = $this->service->previewUninstall('demo-module');

    expect($preview['module_kind'])->toBe('local')
        ->and($preview['composer_package'])->toBe('tallpbx/module-demo-module');
});

it('refuses to uninstall while installed modules require it', function (): void {
    File::makeDirectory($this->sandbox.'/app-modules/dependent-module', 0755, true);
    File::put($this->sandbox.'/app-modules/dependent-module/module.json', json_encode([
        'name' => 'dependent-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DependentModule', 'display_name' => 'Dependent Module',
        'required' => false, 'protected' => false,
        'requirements' => ['modules' => ['demo-module']],
    ]));

    try {
        $this->service->uninstall('demo-module', 'UNINSTALL demo-module');
        $this->fail('Uninstall should have been refused.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->first())->toContain('dependent-module');
    }

    expect(File::exists($this->sandbox.'/app-modules/demo-module'))->toBeTrue();
});

it('restores a local module from git with empty tables and its central tests', function (): void {
    $this->service->uninstall('demo-module', 'UNINSTALL demo-module');

    $report = $this->service->restore('demo-module');

    expect($report['source'])->toBe('git')
        ->and($report['files_restored'])->toBeTrue()
        ->and($this->gitCalls)->toContain('restore --source=HEAD -- app-modules/demo-module')
        // Re-linking the path package restores Composer's autoload mapping.
        ->and($this->composerCalls)->toContain('require tallpbx/module-demo-module --no-interaction')
        ->and(File::exists($this->sandbox.'/app-modules/demo-module/module.json'))->toBeTrue();

    $this->assertDatabaseHas('modules', [
        'name' => 'demo-module',
        'enabled' => true,
        'status' => Module::StatusEnabled,
    ]);

    $composer = json_decode((string) file_get_contents($this->sandbox.'/composer.json'), true);

    expect(collect($composer['repositories'])->pluck('url'))->toContain('app-modules/demo-module')
        ->and($composer['require'])->toHaveKey('tallpbx/module-demo-module')
        // Migrations re-ran into a fresh (empty) table.
        ->and(Schema::hasTable('demo_restore'))->toBeTrue();
});

it('refuses to restore when the origin is unknown', function (): void {
    $this->service = sandboxService($this->sandbox, $this->composerCalls, $this->gitCalls, gitTracked: false);

    expect(fn () => $this->service->restore('demo-module'))
        ->toThrow(ValidationException::class);
});

it('restores a vendor module through Composer', function (): void {
    File::deleteDirectory($this->sandbox.'/app-modules/demo-module');
    File::makeDirectory($this->sandbox.'/vendor/acme/demo-package', 0755, true);
    File::put($this->sandbox.'/vendor/acme/demo-package/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
    ]));

    $this->service = sandboxService($this->sandbox, $this->composerCalls, $this->gitCalls, gitTracked: false);

    $this->service->uninstall('demo-module', 'UNINSTALL demo-module');

    $report = $this->service->restore('demo-module');

    expect($report['source'])->toBe('composer')
        ->and($this->composerCalls)->toContain('require acme/demo-package --no-interaction');

    $this->assertDatabaseHas('modules', [
        'name' => 'demo-module',
        'enabled' => true,
        'status' => Module::StatusEnabled,
    ]);
});

/**
 * Register a module uninstall handler under the tagged binding the
 * lifecycle service resolves at runtime.
 */
function registerDemoUninstaller(ModuleUninstaller $uninstaller): void
{
    $binding = 'tests.module-lifecycle.uninstaller';

    app()->instance($binding, $uninstaller);
    app()->tag([$binding], 'module.uninstallers');
}

/**
 * Build an anonymous uninstall handler that records when it ran.
 */
function testDemoUninstaller(): ModuleUninstaller
{
    return new class implements ModuleUninstaller
    {
        public bool $uninstalled = false;

        /**
         * Return the module this handler owns.
         */
        public function moduleName(): string
        {
            return 'demo-module';
        }

        /**
         * Always allow the uninstall in this test double.
         */
        public function canUninstall(Module $module): bool
        {
            return true;
        }

        /**
         * Describe what the uninstall would remove.
         */
        public function previewUninstall(Module $module): array
        {
            return ['Drop demo-module tables'];
        }

        /**
         * Flag that the real uninstall handler was invoked.
         */
        public function uninstall(Module $module): void
        {
            $this->uninstalled = true;
        }
    };
}

/**
 * Build the lifecycle service against the hermetic sandbox, recording
 * every composer and git invocation through the injectable seams.
 */
function sandboxService(
    string $sandbox,
    array &$composerCalls,
    array &$gitCalls,
    bool $gitTracked,
): ModuleLifecycleService {
    return new ModuleLifecycleService(
        app(),
        app(Filesystem::class),
        $sandbox,
        // Record every composer invocation; vendor reinstall re-creates the package.
        function (array $args) use (&$composerCalls, $sandbox): bool {
            $composerCalls[] = implode(' ', $args);

            if ($args[0] === 'require') {
                $package = $args[1];
                [$vendorName, $packageName] = explode('/', $package);

                File::makeDirectory("{$sandbox}/vendor/{$vendorName}/{$packageName}/database/migrations", 0755, true);
                File::put("{$sandbox}/vendor/{$vendorName}/{$packageName}/module.json", json_encode([
                    'name' => 'demo-module', 'version' => '1.0.0',
                    'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
                    'required' => false, 'protected' => false,
                ]));
                File::put(
                    "{$sandbox}/vendor/{$vendorName}/{$packageName}/database/migrations/2026_01_01_000001_create_demo_restore_table.php",
                    demoRestoreMigration(),
                );
            }

            return true;
        },
        // Record git invocations; simulate a restore by re-creating module files.
        function (array $args) use (&$gitCalls, $sandbox, $gitTracked): bool {
            $gitCalls[] = implode(' ', $args);

            if ($args[0] === 'restore') {
                File::makeDirectory("{$sandbox}/app-modules/demo-module/database/migrations", 0755, true);
                File::put("{$sandbox}/app-modules/demo-module/module.json", json_encode([
                    'name' => 'demo-module', 'version' => '1.0.0',
                    'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
                    'required' => false, 'protected' => false,
                ]));
                File::put(
                    "{$sandbox}/app-modules/demo-module/database/migrations/2026_01_01_000001_create_demo_restore_table.php",
                    demoRestoreMigration(),
                );

                return true;
            }

            return $gitTracked; // answers 'cat-file -e' existence checks
        },
    );
}

/**
 * Render the demo migration every sandbox restore re-runs so tests can
 * assert tables come back empty.
 */
function demoRestoreMigration(): string
{
    return <<<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_restore', function (Blueprint $table): void {
            $table->id();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_restore');
    }
};
PHP;
}
