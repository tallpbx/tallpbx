<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/*
 * `make:module` scaffolds a CUSTOM module under custom-modules/<name>/, while
 * the built-in convention (an explicit routes/web.php) is enforced separately
 * by ModuleArchitectureTest. This test pins both: the scaffold's actual output
 * and the built-in rule the skill describes.
 */

it('scaffolds a conforming custom module', function (): void {
    $originalBasePath = base_path();
    $tempRoot = sys_get_temp_dir().'/make-module-scaffold-'.Str::random(8);
    // Module names are canonical kebab-case: lowercase letters, digits, hyphens.
    $moduleName = 'scaffold-probe-'.strtolower(Str::random(6));

    File::ensureDirectoryExists($tempRoot.'/bootstrap/cache');
    app()->setBasePath($tempRoot);

    try {
        $this->artisan('make:module', ['name' => $moduleName])->assertSuccessful();

        $modulePath = $tempRoot.'/custom-modules/'.$moduleName;

        expect(file_exists($modulePath.'/module.json'))->toBeTrue()
            ->and(file_exists($modulePath.'/src/Providers/ModuleServiceProvider.php'))->toBeTrue()
            ->and(is_dir($modulePath.'/lang/en'))->toBeTrue()
            ->and(file_exists($modulePath.'/lang/en/admin.php'))->toBeTrue()
            ->and(is_dir($modulePath.'/lang/es'))->toBeTrue()
            ->and(is_dir($modulePath.'/lang/fr'))->toBeTrue()
            ->and(is_dir($modulePath.'/database/migrations'))->toBeTrue()
            ->and(is_dir($modulePath.'/config'))->toBeTrue();

        $provider = (string) file_get_contents($modulePath.'/src/Providers/ModuleServiceProvider.php');

        expect($provider)->toContain('function moduleName')
            ->and($provider)->toContain('function moduleNamespace');
    } finally {
        app()->setBasePath($originalBasePath);
        File::deleteDirectory($tempRoot);
    }
});

it('keeps an explicit routes/web.php in every built-in module', function (): void {
    $modules = glob(base_path('app-modules/*'), GLOB_ONLYDIR) ?: [];

    expect($modules)->not->toBeEmpty();

    $missing = [];

    foreach ($modules as $module) {
        if (! file_exists($module.'/routes/web.php')) {
            $missing[] = basename($module);
        }
    }

    expect($missing)->toBeEmpty(
        'Every built-in module must declare routes/web.php: '.implode(', ', $missing)
    );
});
