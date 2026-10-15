<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Module;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use Modules\Admin\Livewire\ModulesList;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the modules list', function () {
    Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => true]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->assertOk()
        ->assertSee('Modules')
        ->assertSee('extensions')
        ->assertSee('Extensions');
});

it('shows module enabled status', function () {
    Module::create(['name' => 'admin', 'display_name' => 'Admin', 'version' => '1.0', 'enabled' => true]);
    Module::create(['name' => 'gateways', 'display_name' => 'Gateways', 'version' => '1.0', 'enabled' => false]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->assertSee('Admin')
        ->assertSee('Gateways');
});

it('toggles module enabled status', function () {
    $module = Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => true]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('optimize:clear')
        ->andReturn(0);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id)
        ->assertDispatched('module-toggled');

    $this->assertDatabaseHas('modules', [
        'id' => $module->id,
        'enabled' => false,
        'status' => Module::StatusDisabled,
    ]);
});

it('refuses to delete protected custom extensions through the dialog', function () {
    // protected forbids uninstall (service-level refusal), not disabling:
    // the two flags must not be conflated (plan §7 Step 1.3).
    $fixtureDir = base_path('custom-modules/protected-rogue');
    File::makeDirectory($fixtureDir, 0755, true);
    File::put($fixtureDir.'/module.json', json_encode([
        'name' => 'protected-rogue', 'version' => '1.0.0',
        'namespace' => 'CustomModules\\ProtectedRogue', 'display_name' => 'Protected Rogue',
        'source' => 'custom', 'protected' => true,
    ]));

    $module = Module::create([
        'name' => 'protected-rogue', 'display_name' => 'Protected Rogue', 'version' => '1.0.0',
        'category' => 'custom', 'source' => 'custom', 'enabled' => true,
        'protected' => true, 'required' => false,
    ]);

    try {
        $component = Livewire::actingAs($this->admin, 'admin')->test(ModulesList::class);

        $component->call('confirmDeleteCustom', $module->id)
            ->assertSet('pendingDeletionPhrase', 'UNINSTALL protected-rogue');

        $component->set('confirmTypedInput', 'UNINSTALL protected-rogue')
            ->call('deleteCustomModule', $module->id);

        expect($component->instance()->deleteError)->not->toBeNull()
            ->and(File::exists($fixtureDir))->toBeTrue();
    } finally {
        File::deleteDirectory($fixtureDir);
    }
});

it('loads the module registry exactly once on the initial render', function () {
    // The registry is loaded at render time only; a second load in mount()
    // doubles every page-open query for no gain.
    Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => true]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    Livewire::actingAs($this->admin, 'admin')->test(ModulesList::class);

    $moduleQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'from "modules"'))
        ->count();

    expect($moduleQueries)->toBe(1);
});

it('can re-enable disabled modules', function () {
    $module = Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => false]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('optimize:clear')
        ->andReturn(0);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id)
        ->assertDispatched('module-toggled');

    $this->assertDatabaseHas('modules', [
        'id' => $module->id,
        'enabled' => true,
        'status' => Module::StatusEnabled,
    ]);
});

it('clears framework caches when module state changes', function () {
    $module = Module::create(['name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0', 'enabled' => true]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('optimize:clear')
        ->andReturn(0);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id);
});

it('prevents disabling required modules', function () {
    $module = Module::create(['name' => 'admin', 'display_name' => 'Admin', 'version' => '1.0', 'enabled' => true, 'required' => true]);

    Artisan::shouldReceive('call')->never();

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id);

    $this->assertDatabaseHas('modules', [
        'id' => $module->id,
        'enabled' => true,
    ]);
});

it('shows a CLI restore hint for uninstalled modules without destructive buttons', function () {
    // Under the modular-monolith lifecycle only vendor modules are ever
    // uninstalled (and restorable via Composer), so the fixture carries the
    // composer package a real vendor uninstall would have recorded.
    $module = Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0',
        'composer_package' => 'acme/extensions',
        'enabled' => false,
        'status' => Module::StatusUninstalled,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->assertSee('php artisan module:restore extensions')
        ->assertDontSee('prepareUninstall')
        ->assertDontSee('reinstallModule');
});

// ─── Task 7: category grouping, custom deletion, toggle cache invalidation ──

it('groups modules into the nine canonical navigation categories', function () {
    $fixtures = [
        'core' => 'admin-panel-mod',
        'accounts' => 'accounts-mod',
        'connectivity' => 'connectivity-mod',
        'routing' => 'routing-mod',
        'features' => 'features-mod',
        'media' => 'media-mod',
        'advanced' => 'advanced-mod',
        'monitoring' => 'monitoring-mod',
        'custom' => 'rogue-tool',
    ];

    foreach ($fixtures as $category => $name) {
        Module::create([
            'name' => $name,
            'display_name' => $name,
            'version' => '1.0',
            'category' => $category,
            'source' => $category === 'custom' ? 'custom' : 'core',
            'enabled' => true,
        ]);
    }

    $component = Livewire::actingAs($this->admin, 'admin')->test(ModulesList::class);

    $grouped = $component->instance()->groupedModules();

    expect(array_keys($grouped))->toBe(ModulesList::CATEGORY_ORDER)
        ->and($grouped['custom']->pluck('name'))->toContain('rogue-tool')
        ->and($grouped['core']->pluck('name'))->toContain('admin-panel-mod');

    foreach (ModulesList::CATEGORY_ORDER as $category) {
        expect($component->instance()->categoryLabel($category))->not->toBe('');
    }
});

it('covers every built-in module manifest with a canonical category', function () {
    $manifests = glob(base_path('app-modules/*/module.json'));

    expect($manifests)->toHaveCount(60);

    foreach ($manifests as $path) {
        $manifest = json_decode((string) file_get_contents($path), true);

        expect(in_array($manifest['category'] ?? null, ModulesList::CATEGORY_ORDER, true))
            ->toBeTrue("Manifest [{$manifest['name']}] declares a non-canonical category.");
    }
});

it('invalidates caches when toggling a module', function () {
    $module = Module::create([
        'name' => 'gateways', 'display_name' => 'Gateways', 'version' => '1.0',
        'category' => 'accounts', 'source' => 'core', 'enabled' => true,
    ]);

    Artisan::shouldReceive('call')->once()->with('optimize:clear');

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id);

    expect($module->fresh()->enabled)->toBeFalse();
});

it('deletes a custom extension through the typed-confirmation dialog', function () {
    $fixtureDir = base_path('custom-modules/delete-me');
    File::makeDirectory($fixtureDir, 0755, true);
    File::put($fixtureDir.'/module.json', json_encode([
        'name' => 'delete-me', 'version' => '1.0.0',
        'namespace' => 'CustomModules\\DeleteMe', 'display_name' => 'Delete Me',
        'source' => 'custom',
    ]));

    $module = Module::create([
        'name' => 'delete-me', 'display_name' => 'Delete Me', 'version' => '1.0.0',
        'category' => 'custom', 'source' => 'custom', 'enabled' => true,
    ]);

    try {
        $component = Livewire::actingAs($this->admin, 'admin')->test(ModulesList::class);

        // The lifecycle service runs optimize:clear outside tests only; the
        // runningUnitTests guard keeps the real Artisan calls out of the test.
        $component->call('confirmDeleteCustom', $module->id)
            ->assertSet('pendingDeletionId', $module->id)
            ->assertSet('pendingDeletionPhrase', 'UNINSTALL delete-me');

        // A wrong typed phrase surfaces the error inside the dialog.
        $component->set('confirmTypedInput', 'wrong phrase')
            ->call('deleteCustomModule', $module->id);

        expect($component->instance()->deleteError)->not->toBeNull()
            ->and(File::exists($fixtureDir))->toBeTrue();

        // The exact typed phrase performs the destructive delete.
        $component->set('confirmTypedInput', 'UNINSTALL delete-me')
            ->call('deleteCustomModule', $module->id);

        expect($component->instance()->pendingDeletionId)->toBeNull()
            ->and(File::exists($fixtureDir))->toBeFalse();

        $this->assertDatabaseMissing('modules', ['id' => $module->id]);
    } finally {
        File::deleteDirectory($fixtureDir);
    }
});

it('refuses to delete non-custom modules from the workbench', function () {
    $module = Module::create([
        'name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0',
        'category' => 'accounts', 'source' => 'core', 'enabled' => true,
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')->test(ModulesList::class)
        ->call('confirmDeleteCustom', $module->id);

    expect($component->instance()->pendingDeletionId)->toBeNull();
});

// ─── Whole-plan review fix pass ───────────────────────────────────

it('translates every workbench category and workflow string', function () {
    // The missing-key fallback returns the key itself, so these assertions
    // fail when a key is absent from every dictionary.
    $component = Livewire::actingAs($this->admin, 'admin')->test(ModulesList::class)->instance();

    expect($component->categoryLabel('core'))->toBe('Core Administration')
        ->and($component->categoryLabel('custom'))->toBe('Custom Extensions');

    foreach (['modules_source', 'modules_builtin', 'modules_custom', 'modules_vendor', 'modules_enabling', 'modules_disabling', 'modules_delete_extension', 'modules_delete_confirm', 'modules_protected_hint'] as $key) {
        expect(__("admin.{$key}"))->not->toBe("admin.{$key}", "Missing translation key admin.{$key}");
    }
});

it('allows toggling protected modules that are not required', function () {
    // protected only forbids uninstall; required forbids disabling (plan §7
    // Step 1.3 — the two flags must not be conflated).
    $module = Module::create([
        'name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0',
        'category' => 'accounts', 'source' => 'core', 'enabled' => true,
        'protected' => true, 'required' => false,
    ]);

    Artisan::shouldReceive('call')->once()->with('optimize:clear');

    Livewire::actingAs($this->admin, 'admin')
        ->test(ModulesList::class)
        ->call('toggleEnabled', $module->id);

    expect($module->fresh()->enabled)->toBeFalse();
});

it('surfaces translated delete-flow errors', function () {
    // Assert against the Spanish dictionary so a hardcoded English string
    // can never masquerade as a translated one.
    App::setLocale('es');

    try {
        $module = Module::create([
            'name' => 'extensions', 'display_name' => 'Extensions', 'version' => '1.0',
            'category' => 'accounts', 'source' => 'core', 'enabled' => true,
        ]);

        $component = Livewire::actingAs($this->admin, 'admin')->test(ModulesList::class);

        $component->call('deleteCustomModule', $module->id);

        expect($component->instance()->deleteError)->toBe(__('admin.modules_delete_not_custom'));
    } finally {
        App::setLocale('en');
    }
});
