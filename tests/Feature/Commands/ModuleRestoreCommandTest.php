<?php

declare(strict_types=1);

use App\Services\ModuleLifecycleService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/pbx-restore-cmd-'.bin2hex(random_bytes(8));

    // A custom extension: uninstallable, but irreversible (no restore).
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
                // The fixture vendor directory already exists; makeDirectory
                // would throw instead of silently re-creating it.
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

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('restores a previously uninstalled vendor module', function (): void {
    // The vendor uninstall keeps a registry marker carrying the Composer
    // package name, which is what module:restore re-requires.
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

it('refuses to restore a custom extension', function (): void {
    // The custom extension's registry row was deleted with it, so restore
    // has no origin and must refuse instead of pretending.
    $this->artisan('module:restore rogue-tool')->assertFailed();
});
