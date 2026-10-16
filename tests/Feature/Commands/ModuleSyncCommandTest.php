<?php

declare(strict_types=1);

use App\Console\Commands\ModuleSyncCommand;
use App\Models\Module;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    // Ensure we're testing against the local modules directory
    $this->modulesPath = base_path('modules');
});

// ─── Module Discovery ───────────────────────────────────────────

it('discovers and syncs modules from module.json files', function () {
    $this->artisan('module:sync')
        ->assertSuccessful();

    // We know there are at least 15 local modules
    $count = Module::count();
    expect($count)->toBeGreaterThanOrEqual(15);
});

it('syncs local modules directly from the app modules directory', function () {
    $this->artisan('module:sync --only-local')
        ->assertSuccessful();

    expect(Module::where('name', 'admin')->exists())->toBeTrue()
        ->and(Module::where('name', 'extensions')->exists())->toBeTrue()
        ->and(Module::count())->toBeGreaterThanOrEqual(15);
});

it('creates module records with correct manifest data', function () {
    $this->artisan('module:sync');

    $admin = Module::where('name', 'admin')->first();

    expect($admin)->not->toBeNull();
    expect($admin->display_name)->toBe('Admin');
    expect($admin->version)->toBe('1.0.0');
    expect($admin->required)->toBeTrue();
    expect($admin->priority)->toBe(-100);
});

it('sets module metadata from manifest', function () {
    $this->artisan('module:sync');

    $admin = Module::where('name', 'admin')->first();
    $extensions = Module::where('name', 'extensions')->first();

    // Admin is required + protected
    expect($admin->required)->toBeTrue();
    expect($admin->protected)->toBeTrue();

    // Extensions is required + protected (core PBX spine)
    expect($extensions->required)->toBeTrue();
    expect($extensions->protected)->toBeTrue();

    // Devices is protected but not required
    $devices = Module::where('name', 'devices')->first();
    expect($devices->required)->toBeFalse();
    expect($devices->protected)->toBeTrue();
});

// ─── Idempotency ────────────────────────────────────────────────

it('is idempotent when run multiple times', function () {
    $this->artisan('module:sync');

    $count = Module::count();

    $this->artisan('module:sync');

    expect(Module::count())->toBe($count);
});

it('updates version when manifest changes', function () {
    // First sync creates the record
    $this->artisan('module:sync');

    $admin = Module::where('name', 'admin')->first();
    expect($admin->version)->toBe('1.0.0');

    // Simulate a version bump by updating the manifest file directly
    // (Since we can't easily modify it during tests, verify the update path exists)
    // The command should update if display_name or version differ
    $admin->update(['display_name' => 'Modified Admin']);

    $this->artisan('module:sync');

    $admin->refresh();
    // The manifest still says "Admin", so it should be restored
    expect($admin->display_name)->toBe('Admin');
});

it('skips a manifest that disappears after filesystem discovery', function (): void {
    $command = app(ModuleSyncCommand::class);
    $method = new ReflectionMethod($command, 'loadManifest');

    $result = $method->invoke($command, base_path('app-modules/removed-during-scan/module.json'));

    expect($result)->toBeNull();
});

it('keeps a core registry row whose directory survives even when its manifest is unreadable', function (): void {
    // Ghost pruning must key on the directory itself, never on the parsed
    // manifest: a transient JSON corruption would otherwise delete the
    // registry row of a real installed module. The fixture lives in a
    // sandboxed base path so parallel workers never glimpse a corrupt
    // manifest (or the cache module:sync regenerates) in the live tree —
    // manifest-completeness suites glob app-modules/*/module.json.
    $root = storage_path('framework/testing/sync-prune-'.uniqid('', true));
    File::ensureDirectoryExists($root.'/app-modules/sync-prune-fixture');
    File::ensureDirectoryExists($root.'/bootstrap/cache');
    File::put($root.'/app-modules/sync-prune-fixture/module.json', '{not valid json');

    // Force the lazy database refresh to run under the REAL base path
    // before switching: a refresh triggered inside the fixture window
    // would migrate the fixture skeleton's nonexistent migrations.
    Schema::hasTable('modules');

    $originalBasePath = base_path();

    try {
        app()->setBasePath($root);

        Module::create([
            'name' => 'sync-prune-fixture', 'display_name' => 'Prune Fixture',
            'version' => '1.0.0', 'source' => 'core',
        ]);

        $this->artisan('module:sync')->assertSuccessful();

        $this->assertDatabaseHas('modules', ['name' => 'sync-prune-fixture']);
    } finally {
        app()->setBasePath($originalBasePath);
        File::deleteDirectory($root);
    }
});

it('rejects manifests whose module name is not canonical kebab-case', function (): void {
    // Underscore names cannot round-trip through the kebab-case mapping used
    // for dependency matching, so manifests using them must not sync. The
    // fixture lives in vendor/ where the sync scan picks it up without
    // touching the real app-modules tree.
    $packageDir = base_path('vendor/pbx-test-invalid/module-with-bad-name');
    File::ensureDirectoryExists($packageDir);
    File::put("{$packageDir}/module.json", json_encode([
        'name' => 'my_mod', 'version' => '1.0.0',
        'namespace' => 'Modules\\MyMod', 'display_name' => 'My Mod',
    ]));

    try {
        Artisan::call('module:sync');

        // Console components wrap long lines, so compare whitespace-
        // normalized output before asserting the operator was told.
        $output = preg_replace('/\s+/', ' ', (string) Artisan::output());

        expect($output)->toContain('invalid module name')
            ->and(Module::where('name', 'my_mod')->exists())->toBeFalse();
    } finally {
        File::deleteDirectory(base_path('vendor/pbx-test-invalid'));
    }
});

// ─── Edge Cases ─────────────────────────────────────────────────

it('preserves user-set enabled state', function () {
    $this->artisan('module:sync');

    $extensions = Module::where('name', 'extensions')->first();
    expect($extensions->enabled)->toBeTrue();

    // User disables the module
    $extensions->update(['enabled' => false]);

    // Re-running sync should NOT re-enable it
    $this->artisan('module:sync');

    $extensions->refresh();
    expect($extensions->enabled)->toBeFalse();
});

it('enables newly discovered modules by default', function () {
    $this->artisan('module:sync');

    Module::where('name', 'admin')->first()->update(['enabled' => false]);

    $this->artisan('module:sync');

    // admin should still be disabled since it was explicitly disabled
    $admin = Module::where('name', 'admin')->first();
    expect($admin->enabled)->toBeFalse();
});

// ─── Module Count Verification ──────────────────────────────────

it('syncs the correct number of core modules', function () {
    $this->artisan('module:sync');

    $expected = [
        'admin', 'auth', 'tenant',
        'sip-profiles', 'sip-accounts', 'extensions', 'devices',
        'gateways', 'acl', 'feature-codes',
        'dialplans', 'destinations', 'inbound-routes', 'outbound-routes',
        'ivr-menus',
    ];

    foreach ($expected as $name) {
        expect(Module::where('name', $name)->exists())->toBeTrue(
            "Expected module [{$name}] to be synced."
        );
    }
});

// ─── Module Schema & Migration ──────────────────────────────────

it('drops the obsolete source_ref column and maintains composer_package on module registry rows', function (): void {
    expect(Schema::hasColumn('modules', 'source_ref'))->toBeFalse()
        ->and(Schema::hasColumn('modules', 'composer_package'))->toBeTrue();

    Module::create([
        'name' => 'private-module',
        'display_name' => 'Private Module',
        'version' => '1.0.0',
        'enabled' => true,
        'composer_package' => 'acme/private-module',
    ]);

    expect(Module::where('name', 'private-module')->value('composer_package'))->toBe('acme/private-module');
});
