<?php

declare(strict_types=1);

use App\Models\Module;
use App\Services\ModuleLifecycleService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;

/*
|--------------------------------------------------------------------------
| Module Restore Command Tests
|--------------------------------------------------------------------------
*/

describe('module restore command', function (): void {
    beforeEach(function (): void {
        $this->sandbox = sys_get_temp_dir().'/pbx-restore-cmd-'.bin2hex(random_bytes(8));

        // A custom module: uninstallable, but irreversible (no restore).
        File::makeDirectory($this->sandbox.'/custom-modules/rogue-tool', 0755, true);
        File::put($this->sandbox.'/custom-modules/rogue-tool/module.json', json_encode([
            'name' => 'rogue-tool', 'version' => '1.0.0',
            'namespace' => 'CustomModules\\RogueTool', 'display_name' => 'Rogue Tool',
            'source' => 'custom',
        ]));

        // A vendor module: uninstallable AND restorable via Composer re-require.
        File::makeDirectory($this->sandbox.'/vendor/acme/demo-package', 0755, true);
        File::put($this->sandbox.'/vendor/acme/demo-package/module.json', json_encode([
            'name' => 'demo-package', 'version' => '1.0.0',
            'namespace' => 'Vendor\\DemoPackage', 'display_name' => 'Demo Package',
            'source' => 'vendor',
        ]));

        File::put($this->sandbox.'/composer.json', json_encode(['require' => []]));

        $sandbox = $this->sandbox;

        // The composer seam re-creates the vendor package when restore re-requires it.
        app()->instance(ModuleLifecycleService::class, new ModuleLifecycleService(
            app(),
            app(Filesystem::class),
            $this->sandbox,
            function (array $args) use ($sandbox): bool {
                if ($args[0] === 'require') {
                    if (! is_dir("{$sandbox}/vendor/acme/demo-package")) {
                        File::makeDirectory("{$sandbox}/vendor/acme/demo-package", 0755, true);
                    }

                    File::put("{$sandbox}/vendor/acme/demo-package/module.json", json_encode([
                        'name' => 'demo-package', 'version' => '1.0.0',
                        'namespace' => 'Vendor\\DemoPackage', 'display_name' => 'Demo Package',
                        'source' => 'vendor',
                    ]));
                }

                return true;
            },
        ));
    });

    afterEach(function (): void {
        File::deleteDirectory($this->sandbox);
    });

    it('restores a previously uninstalled vendor module', function (): void {
        $this->artisan('module:uninstall demo-package --confirm="UNINSTALL demo-package"')
            ->assertSuccessful();

        $this->assertDatabaseHas('modules', [
            'name' => 'demo-package',
            'status' => 'uninstalled',
            'composer_package' => 'acme/demo-package',
        ]);

        $this->artisan('module:restore demo-package')
            ->expectsOutputToContain('Restored module [demo-package] from composer.')
            ->assertSuccessful();
    });

    it('refuses to restore a custom module', function (): void {
        $this->artisan('module:restore rogue-tool')->assertFailed();
    });
});

/*
|--------------------------------------------------------------------------
| Module Uninstall Command Tests
|--------------------------------------------------------------------------
*/

describe('module uninstall command', function (): void {
    beforeEach(function (): void {
        $this->sandbox = sys_get_temp_dir().'/pbx-uninstall-cmd-'.bin2hex(random_bytes(8));

        // A core module: the command must refuse it outright.
        File::makeDirectory($this->sandbox.'/app-modules/demo-module', 0755, true);
        File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
            'name' => 'demo-module', 'version' => '1.0.0',
            'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
            'source' => 'core',
        ]));

        // A custom module: deletable with typed confirmation.
        File::makeDirectory($this->sandbox.'/custom-modules/rogue-tool', 0755, true);
        File::put($this->sandbox.'/custom-modules/rogue-tool/module.json', json_encode([
            'name' => 'rogue-tool', 'version' => '1.0.0',
            'namespace' => 'CustomModules\\RogueTool', 'display_name' => 'Rogue Tool',
            'source' => 'custom',
        ]));

        File::put($this->sandbox.'/composer.json', json_encode(['require' => []]));

        $this->composerCalls = [];

        app()->instance(ModuleLifecycleService::class, new ModuleLifecycleService(
            app(),
            app(Filesystem::class),
            $this->sandbox,
            fn (array $args): bool => (bool) ($this->composerCalls[] = implode(' ', $args)),
        ));
    });

    afterEach(function (): void {
        File::deleteDirectory($this->sandbox);
    });

    it('refuses built-in core modules outright', function (): void {
        $this->artisan('module:uninstall demo-module --confirm="UNINSTALL demo-module"')->assertFailed();

        expect(File::exists($this->sandbox.'/app-modules/demo-module/module.json'))->toBeTrue()
            ->and($this->composerCalls)->toBe([]);
    });

    it('uninstalls a custom module with the exact confirmation phrase', function (): void {
        $this->artisan('module:uninstall rogue-tool --confirm="UNINSTALL rogue-tool"')
            ->expectsOutputToContain('Uninstalled module [rogue-tool]')
            ->assertSuccessful();

        expect(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeFalse()
            ->and($this->composerCalls)->toBe([]);
    });

    it('refuses without the exact confirmation phrase', function (): void {
        $this->artisan('module:uninstall rogue-tool --confirm="nope"')->assertFailed();

        expect(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeTrue();
    });

    it('refuses unknown modules', function (): void {
        $this->artisan('module:uninstall ghost-module --confirm="UNINSTALL ghost-module"')->assertFailed();
    });

    it('surfaces partial cleanup failures instead of claiming a clean uninstall', function (): void {
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

        app()->instance(ModuleLifecycleService::class, new ModuleLifecycleService(
            app(),
            $stubbornFiles,
            $this->sandbox,
            fn (array $args): bool => true,
        ));

        $buffer = new BufferedOutput;
        $exitCode = Artisan::call('module:uninstall', [
            'name' => 'rogue-tool',
            '--confirm' => 'UNINSTALL rogue-tool',
        ], $buffer);
        $output = preg_replace('/\s+/', ' ', (string) $buffer->fetch());

        expect($exitCode)->toBe(0)
            ->and($output)->toContain('need manual attention')
            ->and($output)->toContain('could not be deleted');

        expect(File::exists($this->sandbox.'/custom-modules/rogue-tool'))->toBeTrue();
    });
});

/*
|--------------------------------------------------------------------------
| Native Module Commands & Scaffolding Tests
|--------------------------------------------------------------------------
*/

describe('native module commands', function (): void {
    afterEach(function (): void {
        File::deleteDirectory(base_path('app-modules/native-command-test'));
        File::deleteDirectory(base_path('custom-modules/native-command-test'));
        File::deleteDirectory(base_path('app-modules/my_mod'));
        File::deleteDirectory(base_path('custom-modules/my_mod'));

        Artisan::call('module:cache');
    });

    it('rejects module names that are not canonical kebab-case', function (): void {
        $this->artisan('make:module my_mod')->assertFailed();

        expect(File::exists(base_path('custom-modules/my_mod')))->toBeFalse();
    });

    it('propagates failure exit codes from wrapped module commands', function (): void {
        $this->artisan('make:module extensions')->assertFailed();
    });

    it('exposes native module and modules command names', function (): void {
        $commands = array_keys(Artisan::all());

        expect($commands)->toContain('module:sync')
            ->and($commands)->toContain('module:cache')
            ->and($commands)->toContain('module:clear')
            ->and($commands)->toContain('module:list')
            ->and($commands)->toContain('modules:sync')
            ->and($commands)->toContain('modules:cache')
            ->and($commands)->toContain('modules:clear')
            ->and($commands)->toContain('modules:list')
            ->and($commands)->toContain('module:uninstall')
            ->and($commands)->toContain('module:restore')
            ->and($commands)->toContain('make:module');
    });

    it('lists modules using the native modules list alias', function (): void {
        Module::create([
            'name' => 'extensions',
            'display_name' => 'Extensions',
            'version' => '1.0.0',
            'enabled' => true,
        ]);

        $this->artisan('modules:list')
            ->expectsTable(
                ['Name', 'Display Name', 'Version', 'Status', 'Required', 'Protected'],
                [['extensions', 'Extensions', '1.0.0', 'enabled', 'no', 'no']],
            )
            ->assertSuccessful();
    });

    it('caches module manifests from app modules without generating a module autoloader', function (): void {
        @unlink(base_path('bootstrap/cache/modules.php'));
        @unlink(base_path('bootstrap/cache/modules_autoload.php'));

        $this->artisan('module:cache')
            ->assertSuccessful();

        expect(base_path('bootstrap/cache/modules.php'))->toBeFile()
            ->and(base_path('bootstrap/cache/modules_autoload.php'))->not->toBeFile();

        $modules = require base_path('bootstrap/cache/modules.php');

        expect($modules)->toHaveKey('admin')
            ->and($modules)->toHaveKey('extensions');
    });

    it('scaffolds modules using current TallPBX conventions', function (): void {
        $modulePath = base_path('custom-modules/native-command-test');

        File::deleteDirectory($modulePath);

        $this->artisan('make:module native-command-test --display-name="Native Command Test"')
            ->assertSuccessful();

        expect($modulePath.'/src/Livewire')->toBeDirectory()
            ->and($modulePath.'/src/Database/Factories')->toBeDirectory()
            ->and($modulePath.'/tests')->toBeDirectory()
            ->and($modulePath.'/database/migrations')->toBeDirectory()
            ->and($modulePath.'/config')->toBeDirectory()
            ->and($modulePath.'/lang/en')->toBeDirectory()
            ->and($modulePath.'/resources/views')->toBeDirectory()
            ->and($modulePath.'/src/Providers/ModuleServiceProvider.php')->toBeFile()
            ->and($modulePath.'/composer.json')->not->toBeFile()
            ->and($modulePath.'/module.json')->toBeFile()
            ->and($modulePath.'/routes/web.php')->not->toBeFile();

        $manifest = json_decode((string) file_get_contents($modulePath.'/module.json'), true);

        expect($manifest['protected'])->toBeFalse()
            ->and($manifest['required'])->toBeFalse()
            ->and($manifest['source'])->toBe('custom')
            ->and($manifest['namespace'])->toBe('CustomModules\\NativeCommandTest')
            ->and($manifest['requirements']['modules'])->toBe([])
            ->and($manifest['providers'])->toContain('CustomModules\\NativeCommandTest\\Providers\\ModuleServiceProvider');

        $provider = (string) file_get_contents($modulePath.'/src/Providers/ModuleServiceProvider.php');
        $stubView = (string) file_get_contents($modulePath.'/resources/views/index.blade.php');

        expect($provider)->toContain('extends \\App\\Support\\ModuleServiceProvider')
            ->and($provider)->toContain("return 'native-command-test';")
            ->and($provider)->toContain("return 'CustomModules\\NativeCommandTest';")
            ->and($stubView)->toContain('<x-inline-alert :type="$operationalMessageType ?? \'info\'">')
            ->and($stubView)->toContain('@if (($operationalMessage ?? null) !== null)');

        foreach (['en', 'es', 'fr'] as $locale) {
            $langFile = $modulePath."/lang/{$locale}/admin.php";
            expect($langFile)->toBeFile();
            expect((string) file_get_contents($langFile))->not->toContain('COLLISION PREVENTION');
        }

        File::deleteDirectory($modulePath);
        Artisan::call('module:cache');
    });
});
