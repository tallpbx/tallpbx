<?php

declare(strict_types=1);

use App\Services\ModuleLifecycleService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->sandbox = sys_get_temp_dir().'/pbx-uninstall-cmd-'.bin2hex(random_bytes(8));

    File::makeDirectory($this->sandbox.'/app-modules/demo-module', 0755, true);
    File::put($this->sandbox.'/app-modules/demo-module/module.json', json_encode([
        'name' => 'demo-module', 'version' => '1.0.0',
        'namespace' => 'Modules\\DemoModule', 'display_name' => 'Demo Module',
        'required' => false, 'protected' => false,
    ]));
    File::put($this->sandbox.'/composer.json', json_encode(['repositories' => [], 'require' => []]));

    $this->composerCalls = [];

    app()->instance(ModuleLifecycleService::class, new ModuleLifecycleService(
        app(),
        app(Filesystem::class),
        $this->sandbox,
        fn (array $args): bool => (bool) ($this->composerCalls[] = implode(' ', $args)),
        fn (array $args): bool => false,
    ));
});

afterEach(function () {
    File::deleteDirectory($this->sandbox);
});

it('uninstalls a module with the exact confirmation phrase', function (): void {
    $this->artisan('module:uninstall demo-module --confirm="UNINSTALL demo-module"')
        ->expectsOutputToContain('Uninstalled module [demo-module]')
        ->expectsOutputToContain('php artisan module:restore demo-module')
        ->assertSuccessful();

    expect(File::exists($this->sandbox.'/app-modules/demo-module'))->toBeFalse();
});

it('refuses without the exact confirmation phrase', function (): void {
    $this->artisan('module:uninstall demo-module --confirm="nope"')->assertFailed();

    expect(File::exists($this->sandbox.'/app-modules/demo-module'))->toBeTrue();
});

it('refuses unknown modules', function (): void {
    $this->artisan('module:uninstall ghost-module --confirm="UNINSTALL ghost-module"')->assertFailed();
});
