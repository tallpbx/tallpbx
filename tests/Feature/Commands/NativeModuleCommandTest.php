<?php

declare(strict_types=1);

use App\Models\Module;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

afterEach(function () {
    File::deleteDirectory(base_path('app-modules/native-command-test'));
});

it('exposes native module and modules command names', function () {
    $commands = array_keys(Artisan::all());

    expect($commands)->toContain('module:sync')
        ->and($commands)->toContain('module:cache')
        ->and($commands)->toContain('module:clear')
        ->and($commands)->toContain('module:list')
        ->and($commands)->toContain('modules:sync')
        ->and($commands)->toContain('modules:cache')
        ->and($commands)->toContain('modules:clear')
        ->and($commands)->toContain('modules:list')
        ->and($commands)->toContain('make:module');
});

it('lists modules using the native modules list alias', function () {
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

it('caches module manifests from app modules without generating a module autoloader', function () {
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

it('scaffolds modules using current TallPBX conventions', function () {
    $modulePath = base_path('app-modules/native-command-test');

    File::deleteDirectory($modulePath);

    $this->artisan('make:module native-command-test --display-name="Native Command Test"')
        ->assertSuccessful();

    expect($modulePath.'/src/Livewire')->toBeDirectory()
        ->and($modulePath.'/src/Providers/ModuleServiceProvider.php')->toBeFile()
        ->and($modulePath.'/composer.json')->toBeFile()
        ->and($modulePath.'/module.json')->toBeFile()
        ->and($modulePath.'/routes/web.php')->not->toBeFile();

    $composer = json_decode((string) file_get_contents($modulePath.'/composer.json'), true);

    expect($composer['extra']['laravel']['providers'])->toContain('Modules\\NativeCommandTest\\Providers\\ModuleServiceProvider');

    $provider = (string) file_get_contents($modulePath.'/src/Providers/ModuleServiceProvider.php');
    $stubView = (string) file_get_contents($modulePath.'/resources/views/index.blade.php');

    expect($provider)->toContain('extends \\App\\Support\\ModuleServiceProvider')
        ->and($provider)->toContain("return 'native-command-test';")
        ->and($provider)->toContain("return 'Modules\\NativeCommandTest';")
        ->and($stubView)->toContain('<x-inline-alert :type="$operationalMessageType ?? \'info\'">')
        ->and($stubView)->toContain('@if (($operationalMessage ?? null) !== null)');
});
