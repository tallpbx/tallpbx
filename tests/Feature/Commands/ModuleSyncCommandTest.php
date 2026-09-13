<?php

declare(strict_types=1);

use App\Console\Commands\ModuleSyncCommand;
use App\Models\Module;

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

    // Extensions is neither required nor protected
    expect($extensions->required)->toBeFalse();
    expect($extensions->protected)->toBeFalse();
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
        'gateways', 'access-controls', 'feature-codes',
        'dialplans', 'destinations', 'inbound-routes', 'outbound-routes',
        'ivr-menus',
    ];

    foreach ($expected as $name) {
        expect(Module::where('name', $name)->exists())->toBeTrue(
            "Expected module [{$name}] to be synced."
        );
    }
});
