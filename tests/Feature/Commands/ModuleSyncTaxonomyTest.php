<?php

declare(strict_types=1);

use App\Models\Module;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/*
 * Task 3 taxonomy contract: the nine canonical UI categories every built-in
 * module is assigned to (plan §1.6). Kept in one place so the manifests, the
 * schema, the database sync, and the tests agree on a single source of truth.
 */
const CANONICAL_CATEGORIES = ['core', 'accounts', 'connectivity', 'routing', 'features', 'media', 'advanced', 'monitoring', 'custom'];

/*
 * Manifest category ↔ sidebar navigation parent parity (AGENTS.md invariant).
 * 'core' modules are infrastructure (top-level or menu-less by design) and
 * 'custom' modules define their own layout; both are exempt.
 */
const CATEGORY_NAV_PARENTS = [
    'accounts' => 'pbx.accounts',
    'connectivity' => 'pbx.connectivity',
    'routing' => 'pbx.routing',
    'features' => 'pbx.features',
    'media' => 'pbx.media',
    'advanced' => 'pbx.advanced',
    'monitoring' => 'pbx.monitoring',
];

it('keeps every built-in module menu item under the nav parent matching its manifest category', function () {
    $mismatches = [];

    foreach (File::glob(base_path('app-modules/*/module.json')) as $manifestPath) {
        $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);
        $category = $manifest['category'] ?? null;

        if (! isset(CATEGORY_NAV_PARENTS[$category])) {
            continue;
        }

        $expectedParent = CATEGORY_NAV_PARENTS[$category];

        foreach ($manifest['providers'] as $providerClass) {
            $provider = new $providerClass(app());
            // menuItems() is protected; reflection can invoke it since PHP 8.1.
            $items = (new ReflectionMethod($provider, 'menuItems'))->invoke($provider);

            foreach ($items as $item) {
                if (($item['parent'] ?? null) !== $expectedParent) {
                    $mismatches[] = "{$manifest['name']}: {$item['key']} parent=".
                        var_export($item['parent'] ?? null, true)." expected {$expectedParent}";
                }
            }
        }
    }

    expect($mismatches)->toBe([]);
});

afterEach(function (): void {
    // Tests in this file purge or rebuild the shared generated module cache
    // and may scaffold a probe module; always clean up the scaffold and leave
    // the live server a warm, valid, ghost-free cache behind.
    File::deleteDirectory(base_path('custom-modules/taxonomy-cache-probe'));
    Artisan::call('module:cache');
});

it('adds source and category columns to the modules table', function () {
    expect(Schema::hasColumn('modules', 'source'))->toBeTrue();
    expect(Schema::hasColumn('modules', 'category'))->toBeTrue();
});

it('declares a canonical category in every built-in module manifest', function () {
    $invalid = [];

    foreach (glob(base_path('app-modules/*/module.json')) as $path) {
        $manifest = json_decode((string) file_get_contents($path), true);
        $name = $manifest['name'] ?? basename(dirname($path));
        $category = $manifest['category'] ?? null;

        if (! in_array($category, ['core', 'accounts', 'connectivity', 'routing', 'features', 'media', 'advanced', 'monitoring'], true)) {
            $invalid[] = "{$name}: ".var_export($category, true);
        }
    }

    expect($invalid)->toBeEmpty('Every built-in manifest must declare one of the 8 core UI categories. Invalid: '.implode('; ', $invalid));
});

it('constrains the manifest schema to source and canonical category values', function () {
    $schema = json_decode((string) file_get_contents(base_path('resources/schemas/module.json')), true);

    $categoryEnum = $schema['properties']['category']['enum'] ?? null;

    expect($categoryEnum)->toBe(CANONICAL_CATEGORIES);
    expect($schema['properties']['source']['enum'] ?? null)->toBe(['core', 'custom', 'vendor']);
});

it('module:sync populates every built-in module with core source and its manifest category', function () {
    Artisan::call('module:sync', ['--only-local' => true]);

    $manifests = [];

    foreach (glob(base_path('app-modules/*/module.json')) as $path) {
        $manifest = json_decode((string) file_get_contents($path), true);
        $manifests[$manifest['name']] = $manifest;
    }

    expect(Module::count())->toBe(60);

    foreach (Module::all() as $module) {
        expect($module->source)->toBe('core', "[{$module->name}] source");
        expect($module->category)->toBe($manifests[$module->name]['category'], "[{$module->name}] category");
    }

    // Plan §1.6 spot checks for the assignments the original taxonomy got wrong.
    expect(Module::where('name', 'backups')->value('category'))->toBe('core');
    expect(Module::where('name', 'email-connector')->value('category'))->toBe('advanced');
    expect(Module::where('name', 'click-to-call')->value('category'))->toBe('features');
    expect(Module::where('name', 'destinations')->value('category'))->toBe('routing');
    expect(Module::where('name', 'extension-settings')->value('category'))->toBe('accounts');
});

it('module:sync regenerates the module manifest cache', function () {
    Artisan::call('module:sync', ['--only-local' => true]);

    $cachePath = base_path('bootstrap/cache/modules.php');

    expect(is_file($cachePath))->toBeTrue();

    $cached = require $cachePath;

    expect($cached['backups']['category'] ?? null)->toBe('core');
    expect($cached['backups']['source'] ?? null)->toBe('core');
});

it('module:clear purges the module manifest cache', function () {
    expect(is_file(base_path('bootstrap/cache/modules.php')))->toBeTrue();

    Artisan::call('module:clear');

    expect(is_file(base_path('bootstrap/cache/modules.php')))->toBeFalse();
});

it('optimize:clear reconciles the module manifest cache through module:clear', function () {
    expect(is_file(base_path('bootstrap/cache/modules.php')))->toBeTrue();

    // Artisan::call() does not reroute Symfony TERMINATE events in the test
    // harness, so simulate exactly what the console kernel dispatches when
    // optimize:clear terminates on the CLI (verified live: the purge runs).
    event(new CommandFinished(
        'optimize:clear',
        new ArrayInput([]),
        new NullOutput,
        0,
    ));

    // The reconciliation must never leave the panel booting cold-scan: the
    // cache is purged and immediately rebuilt, so it exists and is fresh.
    expect(is_file(base_path('bootstrap/cache/modules.php')))
        ->toBeTrue('optimize:clear must reconcile the module manifest cache instead of leaving the panel without one.');

    $cached = require base_path('bootstrap/cache/modules.php');

    expect($cached['backups']['category'] ?? null)->toBe('core');
});

it('make:module refreshes the module manifest cache so the scaffold is discoverable', function () {
    Artisan::call('make:module', ['name' => 'taxonomy-cache-probe']);

    expect(is_dir(base_path('custom-modules/taxonomy-cache-probe')))->toBeTrue();

    $cached = require base_path('bootstrap/cache/modules.php');

    expect($cached)->toHaveKey('taxonomy-cache-probe');
    expect($cached['taxonomy-cache-probe']['source'])->toBe('custom');
});

it('scaffolds custom modules with the canonical custom category', function () {
    Artisan::call('make:module', ['name' => 'taxonomy-cache-probe']);

    $manifest = json_decode((string) file_get_contents(base_path('custom-modules/taxonomy-cache-probe/module.json')), true);

    expect($manifest['category'])
        ->toBe('custom', 'Scaffolds must use the canonical custom category so they group under Custom Extensions and satisfy the manifest schema enum.');
});

it('rejects non-canonical --category values when scaffolding', function () {
    Artisan::call('make:module', ['name' => 'taxonomy-cache-probe', '--category' => 'General']);

    expect(is_dir(base_path('custom-modules/taxonomy-cache-probe')))
        ->toBeFalse('A non-canonical category must fail the scaffold before any directory is created.');
});

it('module:cache leaves a parseable cache and no temp files behind', function () {
    Artisan::call('module:cache');

    $cached = require base_path('bootstrap/cache/modules.php');

    expect($cached)->toHaveCount(60);
    expect(glob(base_path('bootstrap/cache/modules.php.tmp.*')))
        ->toBeEmpty('The atomic cache writer must clean up its per-process temp file.');
});

it('removes the vestigial per-module composer.json files', function () {
    expect(glob(base_path('app-modules/*/composer.json')))
        ->toBeEmpty('Path-repository era composer.json files must be removed from app-modules/; root PSR-4 autoloading and module.json own everything now.');
});

it('prunes ghost core registry rows whose module directory no longer exists', function () {
    // Core modules are permanent monorepo code, so a core-sourced registry
    // row without an app-modules/ directory is always dead (the live
    // smtp-connector ghost pattern).
    $ghost = Module::create([
        'name' => 'smtp-connector-ghost',
        'display_name' => 'Ghost',
        'version' => '1.0.0',
        'source' => 'core',
        'enabled' => false,
    ]);

    // Unplugged custom/vendor rows keep their registry state for reinstall.
    $unplugged = Module::create([
        'name' => 'unplugged-extension',
        'display_name' => 'Unplugged',
        'version' => '1.0.0',
        'source' => 'custom',
        'enabled' => false,
    ]);

    $this->artisan('module:sync')->assertSuccessful();

    expect(Module::query()->whereKey($ghost->getKey())->exists())
        ->toBeFalse('Ghost core rows must be pruned by module:sync.')
        ->and(Module::query()->whereKey($unplugged->getKey())->exists())
        ->toBeTrue('Custom extension rows must never be pruned.');
});
