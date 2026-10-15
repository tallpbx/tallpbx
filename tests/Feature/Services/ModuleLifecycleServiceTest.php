<?php

declare(strict_types=1);

use App\Contracts\ModuleUninstaller;
use App\Models\Module;
use App\Models\Permission;
use App\Services\ModuleLifecycleService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    // Build a hermetic sandbox that mirrors the project layout, so tests
    // never touch the real app-modules/, custom-modules/, or composer.json.
    $this->sandbox = sys_get_temp_dir().'/pbx-lifecycle-'.bin2hex(random_bytes(8));

    // A core module: permanent monorepo code that must refuse uninstall.
    File::makeDirectory($this->sandbox.'/app-modules/demo-module', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
        'name' => 'demo-module',
        'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule',
        'display_name' => 'Demo Module',
        'source' => 'core',
        'required' => false,
        'protected' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    // A custom extension: untracked, deletable with typed confirmation.
    File::makeDirectory($this->sandbox.'/custom-modules/rogue-tool', 0755, true);
    File::put($this->sandbox.'/custom-modules/rogue-tool/module.json', json_encode([
        'name' => 'rogue-tool',
        'version' => '1.0.0',
        'namespace' => 'CustomModules\\RogueTool',
        'display_name' => 'Rogue Tool',
        'source' => 'custom',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    File::put($this->sandbox.'/composer.json', json_encode([
        'require' => [],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $this->composerCalls = [];

    $this->service = sandboxService($this->sandbox, $this->composerCalls);
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('exposes the uninstall confirmation phrase', function (): void {
    expect($this->service->confirmationPhrase('rogue-tool'))->toBe('UNINSTALL rogue-tool');
});

it('refuses to uninstall built-in core modules and points at the disable toggle', function (): void {
    $preview = $this->service->previewUninstall('demo-module');

    expect($preview['can_uninstall'])->toBeFalse()
        ->and($preview['reason'])->toBe('Built-in core modules cannot be uninstalled. Use the disable toggle instead.');

    expect(fn () => $this->service->uninstall('demo-module', 'UNINSTALL demo-module'))
        ->toThrow(ValidationException::class, 'Built-in core modules cannot be uninstalled. Use the disable toggle instead.');

    // The core module directory is permanent monorepo code — untouched.
    expect(File::exists($this->sandbox.'/app-modules/demo-module/module.json'))->toBeTrue()
        // No Composer manipulation of any kind for a refused core module.
        ->and($this->composerCalls)->toBe([]);
});

it('refuses modules that are neither core, custom, nor vendor', function (): void {
    expect(fn () => $this->service->uninstall('ghost-module', 'UNINSTALL ghost-module'))
        ->toThrow(ValidationException::class);
});

it('refuses invalid module names', function (): void {
    expect(fn () => $this->service->uninstall('../evil', 'UNINSTALL ../evil'))
        ->toThrow(ValidationException::class);
});

it('refuses required or protected custom extensions', function (): void {
    File::put($this->sandbox.'/custom-modules/rogue-tool/module.json', json_encode([
        'name' => 'rogue-tool', 'version' => '1.0.0',
        'namespace' => 'CustomModules\\RogueTool', 'display_name' => 'Rogue Tool',
        'source' => 'custom', 'protected' => true,
    ]));

    expect(fn () => $this->service->uninstall('rogue-tool', 'UNINSTALL rogue-tool'))
        ->toThrow(ValidationException::class);

    expect(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeTrue();
});

it('refuses a mismatched confirmation phrase', function (): void {
    expect(fn () => $this->service->uninstall('rogue-tool', 'not the phrase'))
        ->toThrow(ValidationException::class);

    expect(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeTrue();
});

it('uninstalls a custom extension and deletes its directory and registry record', function (): void {
    Module::create(['name' => 'rogue-tool', 'display_name' => 'Rogue Tool', 'version' => '1.0.0']);
    Permission::create(['name' => 'rogue-tool.view', 'module' => 'rogue-tool']);

    $report = $this->service->uninstall('rogue-tool', 'UNINSTALL rogue-tool');

    expect($report['module_kind'])->toBe('custom')
        ->and($report['module_dir_deleted'])->toBeTrue()
        ->and($report['permissions_deleted'])->toBe(1)
        ->and($report['uninstall_ran'])->toBeFalse()
        // Custom extensions are irreversible: no restore hint exists.
        ->and($report['restore_hint'])->toBe('')
        // No Composer manipulation of any kind for a custom extension.
        ->and($this->composerCalls)->toBe([])
        ->and(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeFalse()
        ->and(Permission::where('module', 'rogue-tool')->exists())->toBeFalse();

    // The registry record is deleted with the extension: nothing on disk or
    // in git can bring a custom extension back, so there is no marker to keep.
    $this->assertDatabaseMissing('modules', ['name' => 'rogue-tool']);

    // The root composer.json is never touched by a custom uninstall.
    $composer = json_decode((string) file_get_contents($this->sandbox.'/composer.json'), true);

    expect($composer)->toBe(['require' => []]);
});

it('keeps a custom extension intact when its directory cannot be deleted', function (): void {
    $stubbornFiles = new class extends Filesystem
    {
        /**
         * Pretend the target directory cannot be removed.
         */
        public function deleteDirectory($directory, $preserve = false): bool
        {
            return false;
        }
    };

    Module::create(['name' => 'rogue-tool', 'display_name' => 'Rogue Tool', 'version' => '1.0.0']);

    $service = new ModuleLifecycleService(
        app(),
        $stubbornFiles,
        $this->sandbox,
        fn (array $args): bool => true,
    );

    $report = $service->uninstall('rogue-tool', 'UNINSTALL rogue-tool');

    expect($report['module_dir_deleted'])->toBeFalse()
        ->and(implode(' ', $report['warnings']))->toContain('could not be deleted')
        ->and(File::exists($this->sandbox.'/custom-modules/rogue-tool/module.json'))->toBeTrue();

    // A failed directory deletion must keep the registry row: deleting it
    // would re-register a default-enabled, panel-invisible extension on the
    // next boot.
    $this->assertDatabaseHas('modules', ['name' => 'rogue-tool']);
});

it('runs the module uninstall handler when one is registered', function (): void {
    $uninstaller = testRogueUninstaller();
    registerRogueUninstaller($uninstaller);
    Module::create(['name' => 'rogue-tool', 'display_name' => 'Rogue Tool', 'version' => '1.0.0']);

    $report = $this->service->uninstall('rogue-tool', 'UNINSTALL rogue-tool');

    expect($report['uninstall_ran'])->toBeTrue()
        ->and($uninstaller->uninstalled)->toBeTrue();
});

it('uninstalls a vendor module through Composer and keeps the restore marker', function (): void {
    File::makeDirectory($this->sandbox.'/vendor/acme/demo-package', 0755, true);
    File::put($this->sandbox.'/vendor/acme/demo-package/module.json', json_encode([
        'name' => 'demo-package', 'version' => '1.0.0',
        'namespace' => 'Vendor\\DemoPackage', 'display_name' => 'Demo Package',
        'source' => 'vendor',
    ]));

    Module::create(['name' => 'demo-package', 'display_name' => 'Demo Package', 'version' => '1.0.0']);
    Permission::create(['name' => 'demo-package.view', 'module' => 'demo-package']);

    $report = $this->service->uninstall('demo-package', 'UNINSTALL demo-package');

    expect($report['module_kind'])->toBe('vendor')
        ->and($report['module_dir_deleted'])->toBeFalse()
        ->and($report['composer_package_removed'])->toBeTrue()
        ->and($this->composerCalls)->toBe(['remove acme/demo-package --no-interaction'])
        // Vendor files belong to Composer: the service leaves them for composer remove.
        ->and(File::exists($this->sandbox.'/vendor/acme/demo-package/module.json'))->toBeTrue()
        ->and($report['restore_hint'])->toContain('module:restore demo-package');

    // The registry marker is what makes vendor restore possible.
    $this->assertDatabaseHas('modules', [
        'name' => 'demo-package',
        'enabled' => false,
        'status' => Module::StatusUninstalled,
        'composer_package' => 'acme/demo-package',
    ]);
});

it('refuses to uninstall while installed modules require it', function (): void {
    File::makeDirectory($this->sandbox.'/custom-modules/dependent-tool', 0755, true);
    File::put($this->sandbox.'/custom-modules/dependent-tool/module.json', json_encode([
        'name' => 'dependent-tool', 'version' => '1.0.0',
        'namespace' => 'CustomModules\\DependentTool', 'display_name' => 'Dependent Tool',
        'source' => 'custom',
        'requirements' => ['modules' => ['rogue-tool']],
    ]));

    try {
        $this->service->uninstall('rogue-tool', 'UNINSTALL rogue-tool');
        $this->fail('Uninstall should have been refused.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->first())->toContain('dependent-tool');
    }

    expect(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeTrue();
});

it('refuses to uninstall while a dependent declares the requirement in the legacy object shape', function (): void {
    File::makeDirectory($this->sandbox.'/custom-modules/dependent-tool', 0755, true);
    File::put($this->sandbox.'/custom-modules/dependent-tool/module.json', json_encode([
        'name' => 'dependent-tool', 'version' => '1.0.0',
        'namespace' => 'CustomModules\\DependentTool', 'display_name' => 'Dependent Tool',
        'source' => 'custom',
        'requirements' => ['modules' => [['name' => 'rogue-tool', 'version' => '1.0.0']]],
    ]));

    // A legacy object-shaped dependency entry must count as a dependent too,
    // otherwise the uninstall guard is silently bypassed for that manifest.
    try {
        $this->service->uninstall('rogue-tool', 'UNINSTALL rogue-tool');
        $this->fail('Uninstall should have been refused.');
    } catch (ValidationException $exception) {
        expect(collect($exception->errors())->flatten()->first())->toContain('dependent-tool');
    }

    expect(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeTrue();
});

it('scans only module code and central tests for cross-module references, skipping dependency trees', function (): void {
    // A real centralized reference must be reported...
    File::makeDirectory($this->sandbox.'/tests/Feature', 0755, true);
    File::put($this->sandbox.'/tests/Feature/CentralTest.php', "<?php\n\nuse Modules\\RogueTool\\Something;\n");

    // ...while identical text inside vendored dependency trees and VCS
    // directories must never surface as a false cross-module hit.
    File::makeDirectory($this->sandbox.'/app-modules/demo-module/vendor/lib', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/vendor/lib/Fixture.php', "<?php\n\nuse Modules\\RogueTool\\Something;\n");
    File::makeDirectory($this->sandbox.'/app-modules/demo-module/node_modules/pkg', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/node_modules/pkg/fixture.js', 'Modules\\RogueTool\\Something');
    File::makeDirectory($this->sandbox.'/app-modules/demo-module/.git', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/.git/HEAD', 'Modules\\RogueTool\\Something');

    $preview = $this->service->previewUninstall('rogue-tool');
    $warnings = implode(' ', $preview['warnings']);

    expect($warnings)->toContain('tests/Feature/CentralTest.php')
        ->and($warnings)->not->toContain('vendor/lib/Fixture.php')
        ->and($warnings)->not->toContain('node_modules/pkg/fixture.js')
        ->and($warnings)->not->toContain('.git');
});

// ─── Restore: vendor re-require only ─────────────────────────────

it('restores a vendor module through Composer re-require', function (): void {
    File::makeDirectory($this->sandbox.'/vendor/acme/demo-package', 0755, true);
    File::put($this->sandbox.'/vendor/acme/demo-package/module.json', json_encode([
        'name' => 'demo-package', 'version' => '1.0.0',
        'namespace' => 'Vendor\\DemoPackage', 'display_name' => 'Demo Package',
        'source' => 'vendor',
    ]));

    $this->service->uninstall('demo-package', 'UNINSTALL demo-package');

    $report = $this->service->restore('demo-package');

    expect($report['source'])->toBe('composer')
        ->and($report['files_restored'])->toBeTrue()
        ->and($this->composerCalls)->toBe(['remove acme/demo-package --no-interaction', 'require acme/demo-package --no-interaction']);

    $this->assertDatabaseHas('modules', [
        'name' => 'demo-package',
        'enabled' => true,
        'status' => Module::StatusEnabled,
    ]);
});

it('refuses to restore when no Composer package is recorded', function (): void {
    // A registry row without a composer package has no origin — custom
    // extensions are deleted outright and vendor rows always carry one.
    Module::create([
        'name' => 'rogue-tool', 'display_name' => 'Rogue Tool', 'version' => '1.0.0',
        'enabled' => false, 'status' => Module::StatusUninstalled,
    ]);

    $preview = $this->service->previewRestore('rogue-tool');

    expect($preview['can_restore'])->toBeFalse()
        ->and($preview['reason'])->toContain('no recorded Composer package');

    expect(fn () => $this->service->restore('rogue-tool'))
        ->toThrow(ValidationException::class);
});

it('refuses to restore a module that is not marked uninstalled', function (string $status): void {
    // A registry row that is enabled or disabled means the module is still
    // installed; restoring would overwrite its live files and force it on.
    Module::create([
        'name' => 'rogue-tool', 'display_name' => 'Rogue Tool', 'version' => '1.0.0',
        'composer_package' => 'acme/demo-package',
        'enabled' => $status === Module::StatusEnabled, 'status' => $status,
    ]);

    // Uncommitted local work a stray restore would silently destroy.
    File::put($this->sandbox.'/custom-modules/rogue-tool/local-work.php', '<?php // local work');

    $preview = $this->service->previewRestore('rogue-tool');

    expect($preview['can_restore'])->toBeFalse()
        ->and($preview['reason'])->toContain('still installed');

    expect(fn () => $this->service->restore('rogue-tool'))
        ->toThrow(ValidationException::class);

    expect(File::exists($this->sandbox.'/custom-modules/rogue-tool/local-work.php'))->toBeTrue();
})->with([Module::StatusEnabled, Module::StatusDisabled]);

it('refuses to restore a module that was never marked uninstalled', function (): void {
    // No registry marker exists, so this module was never uninstalled.
    File::put($this->sandbox.'/custom-modules/rogue-tool/local-work.php', '<?php // local work');

    $preview = $this->service->previewRestore('rogue-tool');

    expect($preview['can_restore'])->toBeFalse()
        ->and($preview['reason'])->toContain('still installed');

    expect(fn () => $this->service->restore('rogue-tool'))
        ->toThrow(ValidationException::class);

    expect(File::exists($this->sandbox.'/custom-modules/rogue-tool/local-work.php'))->toBeTrue();
});

/**
 * Register a module uninstall handler under the tagged binding the
 * lifecycle service resolves at runtime.
 */
function registerRogueUninstaller(ModuleUninstaller $uninstaller): void
{
    $binding = 'tests.module-lifecycle.uninstaller';

    app()->instance($binding, $uninstaller);
    app()->tag([$binding], 'module.uninstallers');
}

/**
 * Build an anonymous uninstall handler that records when it ran.
 */
function testRogueUninstaller(): ModuleUninstaller
{
    return new class implements ModuleUninstaller
    {
        public bool $uninstalled = false;

        /**
         * Return the module this handler owns.
         */
        public function moduleName(): string
        {
            return 'rogue-tool';
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
            return ['Drop rogue-tool tables'];
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
 * every Composer invocation through the injectable seam. Git is gone:
 * the modular-monolith lifecycle has no git-based restore.
 */
function sandboxService(
    string $sandbox,
    array &$composerCalls,
): ModuleLifecycleService {
    return new ModuleLifecycleService(
        app(),
        app(Filesystem::class),
        $sandbox,
        function (array $args) use (&$composerCalls, $sandbox): bool {
            $composerCalls[] = implode(' ', $args);

            if ($args[0] === 'require') {
                $package = $args[1];
                [$vendorName, $packageName] = explode('/', $package);

                // The fixture vendor directory already exists; makeDirectory
                // would throw instead of silently re-creating it.
                if (! is_dir("{$sandbox}/vendor/{$vendorName}/{$packageName}")) {
                    File::makeDirectory("{$sandbox}/vendor/{$vendorName}/{$packageName}", 0755, true);
                }

                File::put("{$sandbox}/vendor/{$vendorName}/{$packageName}/module.json", json_encode([
                    'name' => 'demo-package', 'version' => '1.0.0',
                    'namespace' => 'Vendor\\DemoPackage', 'display_name' => 'Demo Package',
                    'source' => 'vendor',
                ]));
            }

            return true;
        },
    );
}
