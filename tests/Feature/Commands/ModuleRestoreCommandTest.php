<?php

declare(strict_types=1);

use App\Services\ModuleLifecycleService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/pbx-restore-cmd-'.bin2hex(random_bytes(8));

    File::makeDirectory($this->sandbox.'/app-modules/demo-module', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
        'required' => false, 'protected' => false,
    ]));
    File::put($this->sandbox.'/composer.json', json_encode(['repositories' => [], 'require' => []]));

    $sandbox = $this->sandbox;

    // The git seam re-creates the module when restore asks git to restore it.
    app()->instance(ModuleLifecycleService::class, new ModuleLifecycleService(
        app(),
        app(Filesystem::class),
        $this->sandbox,
        fn (array $args): bool => true,
        function (array $args) use ($sandbox): bool {
            if ($args[0] === 'restore') {
                File::makeDirectory("{$sandbox}/app-modules/demo-module", 0755, true);
                File::put("{$sandbox}/app-modules/demo-module/module.json", json_encode([
                    'name' => 'demo-module', 'version' => '1.0.0',
                    'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
                ]));

                return true;
            }

            return true; // git-tracked
        },
    ));
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('restores a previously uninstalled module', function (): void {
    $this->artisan('module:uninstall demo-module --confirm="UNINSTALL demo-module"')
        ->assertSuccessful();

    $this->artisan('module:restore demo-module')
        ->expectsOutputToContain('Restored module [demo-module]')
        ->assertSuccessful();

    expect(File::exists($this->sandbox.'/app-modules/demo-module/module.json'))->toBeTrue();
});
