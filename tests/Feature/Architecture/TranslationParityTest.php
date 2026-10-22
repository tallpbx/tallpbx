<?php

declare(strict_types=1);

namespace Tests\Feature\Architecture;

use App\Services\MenuService;
use App\Services\ModuleState;
use App\Services\PermissionService;
use App\Support\ModuleServiceProvider;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use LogicException;

beforeEach(function () {
    ModuleServiceProvider::clearRootTranslationKeysCache();
    // The inter-module key registry is static and shared per worker process.
    if (method_exists(ModuleServiceProvider::class, 'clearModuleTranslationKeysCache')) {
        ModuleServiceProvider::clearModuleTranslationKeysCache();
    }
});

it('enforces 100% key parity across root language files in en, es, and fr', function () {
    foreach (['admin', 'client'] as $group) {
        $enKeys = array_keys(require base_path("lang/en/{$group}.php"));
        $esKeys = array_keys(require base_path("lang/es/{$group}.php"));
        $frKeys = array_keys(require base_path("lang/fr/{$group}.php"));

        $missingEs = array_diff($enKeys, $esKeys);
        $missingFr = array_diff($enKeys, $frKeys);
        $extraEs = array_diff($esKeys, $enKeys);
        $extraFr = array_diff($frKeys, $enKeys);

        expect($missingEs)->toBeEmpty("Keys missing in lang/es/{$group}.php: ".implode(', ', $missingEs))
            ->and($missingFr)->toBeEmpty("Keys missing in lang/fr/{$group}.php: ".implode(', ', $missingFr))
            ->and($extraEs)->toBeEmpty("Extra keys in lang/es/{$group}.php not in en: ".implode(', ', $extraEs))
            ->and($extraFr)->toBeEmpty("Extra keys in lang/fr/{$group}.php not in en: ".implode(', ', $extraFr));
    }
});

it('enforces zero key collisions between module language files and root dictionary', function () {
    $moduleLangFiles = glob(base_path('app-modules/*/lang/*/*.php')) ?: [];
    $collisions = [];

    foreach ($moduleLangFiles as $file) {
        $locale = basename(dirname($file));
        $group = basename($file, '.php');
        $rootFile = base_path("lang/{$locale}/{$group}.php");

        if (! file_exists($rootFile)) {
            continue;
        }

        $rootKeys = array_keys(Arr::dot(require $rootFile));
        $moduleKeys = array_keys(Arr::dot(require $file));
        $intersect = array_intersect($moduleKeys, $rootKeys);

        if (! empty($intersect)) {
            $moduleName = basename(dirname($file, 3));
            $collisions[$moduleName." [{$locale}/{$group}]"] = $intersect;
        }
    }

    expect($collisions)->toBeEmpty(
        'Module translation keys collide with root dictionary: '.json_encode($collisions, JSON_PRETTY_PRINT)
    );
});

it('enforces zero cross-module key collisions across all modules', function () {
    $moduleDirs = glob(base_path('app-modules/*'), GLOB_ONLYDIR) ?: [];
    $seenKeys = [];
    $crossCollisions = [];

    foreach ($moduleDirs as $moduleDir) {
        $moduleName = basename($moduleDir);
        $enFiles = glob("{$moduleDir}/lang/en/*.php") ?: [];

        foreach ($enFiles as $file) {
            $group = basename($file, '.php');
            $moduleKeys = array_keys(Arr::dot(require $file));

            foreach ($moduleKeys as $key) {
                $scopedKey = "{$group}.{$key}";
                if (isset($seenKeys[$scopedKey])) {
                    $crossCollisions[$scopedKey] = [
                        'first_module' => $seenKeys[$scopedKey],
                        'second_module' => $moduleName,
                    ];
                } else {
                    $seenKeys[$scopedKey] = $moduleName;
                }
            }
        }
    }

    expect($crossCollisions)->toBeEmpty(
        'Cross-module translation key collisions detected: '.json_encode($crossCollisions, JSON_PRETTY_PRINT)
    );
});

it('enforces 100% locale parity across all built-in module language files', function () {
    $moduleDirs = glob(base_path('app-modules/*'), GLOB_ONLYDIR) ?: [];
    expect($moduleDirs)->not->toBeEmpty();

    foreach ($moduleDirs as $moduleDir) {
        $moduleName = basename($moduleDir);
        $enFiles = glob("{$moduleDir}/lang/en/*.php") ?: [];

        foreach ($enFiles as $enFile) {
            $group = basename($enFile, '.php');
            $enKeys = array_keys(Arr::dot(require $enFile));

            foreach (['es', 'fr'] as $locale) {
                $localeFile = "{$moduleDir}/lang/{$locale}/{$group}.php";
                expect(file_exists($localeFile))->toBeTrue(
                    "Module [{$moduleName}] has lang/en/{$group}.php but is missing lang/{$locale}/{$group}.php"
                );

                $localeKeys = array_keys(Arr::dot(require $localeFile));
                $missingInLocale = array_diff($enKeys, $localeKeys);
                $extraInLocale = array_diff($localeKeys, $enKeys);

                expect($missingInLocale)->toBeEmpty(
                    "Module [{$moduleName}] lang/{$locale}/{$group}.php is missing keys: ".implode(', ', $missingInLocale)
                );
                expect($extraInLocale)->toBeEmpty(
                    "Module [{$moduleName}] lang/{$locale}/{$group}.php has extra keys not in en: ".implode(', ', $extraInLocale)
                );
            }
        }
    }
});

it('enforces the collision warning docblock on every built-in module language file', function () {
    $moduleLangFiles = glob(base_path('app-modules/*/lang/*/*.php')) ?: [];
    expect($moduleLangFiles)->toBeArray();

    foreach ($moduleLangFiles as $file) {
        $content = (string) file_get_contents($file);

        expect($content)->toContain('COLLISION PREVENTION & KEY NAMING RULES:')
            ->and($content)->toContain('Module language files will be rejected at runtime by ModuleServiceProvider');
    }
});

it('throws LogicException at runtime when a module language file collides with root dictionary', function () {
    $testModuleDir = base_path('app-modules/collision-test-probe');
    File::deleteDirectory($testModuleDir);

    mkdir("{$testModuleDir}/lang/en", 0755, true);
    mkdir("{$testModuleDir}/src/Providers", 0755, true);

    // Save a colliding admin.php that re-defines root 'save' key
    file_put_contents(
        "{$testModuleDir}/lang/en/admin.php",
        "<?php\n\nreturn ['save' => 'Colliding Save'];\n"
    );

    $probeProvider = new class(app()) extends ModuleServiceProvider
    {
        /**
         * Path to the probe module.
         */
        protected function modulePath(): string
        {
            return base_path('app-modules/collision-test-probe');
        }

        /**
         * Name of the probe module.
         */
        protected function moduleName(): string
        {
            return 'collision-test-probe';
        }

        /**
         * Namespace of the probe module.
         */
        protected function moduleNamespace(): string
        {
            return 'Modules\\CollisionTestProbe';
        }

        /**
         * Boot the probe provider.
         */
        public function boot(MenuService $menu, PermissionService $permission, ModuleState $modules): void
        {
            parent::boot($menu, $permission, $modules);
        }
    };

    try {
        expect(fn () => $probeProvider->boot(
            app(MenuService::class),
            app(PermissionService::class),
            app(ModuleState::class)
        ))->toThrow(
            LogicException::class,
            'Module [collision-test-probe] language file collides with root dictionary for key(s): [admin.save]. Refusing to load module cascading path.'
        );
    } finally {
        File::deleteDirectory($testModuleDir);
    }
});

it('enforces that all registered menu item translation keys resolve across all locales', function (): void {
    $menuService = app(MenuService::class);
    $items = $menuService->getFlat();

    expect($items)->not->toBeEmpty();

    $missingByLocale = [];

    foreach (['en', 'es', 'fr'] as $locale) {
        $missing = [];

        foreach ($items as $item) {
            $label = $item['label'] ?? null;
            if (! is_string($label) || ! str_contains($label, '.')) {
                continue;
            }

            if (! Lang::has($label, $locale)) {
                $missing[$item['key']] = $label;
            }
        }

        if (! empty($missing)) {
            $missingByLocale[$locale] = $missing;
        }
    }

    expect($missingByLocale)->toBeEmpty(
        'Registered menu items reference missing translation keys: '.json_encode($missingByLocale, JSON_PRETTY_PRINT)
    );
});
