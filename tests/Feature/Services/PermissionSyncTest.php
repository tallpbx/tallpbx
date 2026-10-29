<?php

declare(strict_types=1);

use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Providers\AppServiceProvider;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->service = app(PermissionService::class);
    $this->service->reset();
});

it('syncs registered permissions to the database', function () {
    $this->service->register('extensions', [
        'extensions.view',
        'extensions.create',
    ]);
    $this->service->register('admin', [
        'panel.dashboard.view',
    ]);

    $this->service->syncToDatabase();

    expect(Permission::count())->toBe(3);
    expect(Permission::where('module', 'extensions')->count())->toBe(2);
    expect(Permission::where('module', 'admin')->count())->toBe(1);
});

it('does not duplicate permissions on repeated sync', function () {
    $this->service->register('test', ['test.view']);

    $this->service->syncToDatabase();
    $this->service->syncToDatabase();
    $this->service->syncToDatabase();

    expect(Permission::where('name', 'test.view')->count())->toBe(1);
});

it('rebuilds permissions from registrations after the table has been emptied post sync', function () {
    $this->service->register('admin', ['admin.dashboard.view']);
    $this->service->register('extensions', ['extensions.view']);

    $this->service->syncToDatabase();

    Permission::query()->delete();

    $this->service->syncToDatabase();

    expect(Permission::query()->pluck('name')->all())
        ->toContain('admin.dashboard.view')
        ->toContain('extensions.view');
});

it('removes stale permissions not in current registration', function () {
    Permission::factory()->create(['name' => 'stale.permission', 'module' => 'stale']);

    $this->service->register('extensions', ['extensions.view']);
    $this->service->syncToDatabase();

    expect(Permission::where('name', 'stale.permission')->exists())->toBeFalse();
    expect(Permission::where('name', 'extensions.view')->exists())->toBeTrue();
});

it('preserves permissions for disabled modules during sync', function () {
    Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0.0',
        'enabled' => false,
    ]);

    Permission::factory()->create(['name' => 'extensions.view', 'module' => 'extensions']);

    $this->service->register('admin', ['admin.dashboard.view']);
    $this->service->syncToDatabase();

    expect(Permission::where('name', 'extensions.view')->exists())->toBeTrue()
        ->and(Permission::where('name', 'admin.dashboard.view')->exists())->toBeTrue();
});

it('hides disabled module permissions from grouped output by default', function () {
    Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0.0',
        'enabled' => false,
    ]);

    Permission::factory()->create(['name' => 'extensions.view', 'module' => 'extensions']);

    $this->service->register('admin', ['admin.dashboard.view']);
    $this->service->syncToDatabase();

    expect($this->service->grouped())->not->toHaveKey('extensions')
        ->and($this->service->grouped(includeDisabled: true))->toHaveKey('extensions');
});

it('returns all permissions from database after sync', function () {
    $this->service->register('admin', [
        'panel.users.view',
        'panel.users.create',
    ]);
    $this->service->syncToDatabase();

    $all = $this->service->all();

    expect($all)->toHaveCount(2);
    expect($all)->toContain('panel.users.view');
    expect($all)->toContain('panel.users.create');
});

it('returns grouped permissions from database after sync', function () {
    $this->service->register('extensions', ['extensions.view']);
    $this->service->register('ivr', ['ivr.view', 'ivr.create']);
    $this->service->syncToDatabase();

    $grouped = $this->service->grouped();

    expect($grouped)->toHaveKeys(['extensions', 'ivr']);
    expect($grouped['extensions'])->toHaveCount(1);
    expect($grouped['ivr'])->toHaveCount(2);
    expect($grouped['extensions'][0])->toHaveKeys(['name', 'description']);
});

it('persists descriptions when registering with associative array', function () {
    $this->service->register('extensions', [
        'extensions.view' => 'View all extensions',
        'extensions.create' => 'Create new extensions',
    ]);
    $this->service->syncToDatabase();

    expect(Permission::where('name', 'extensions.view')->value('description'))->toBe('View all extensions');
    expect(Permission::where('name', 'extensions.create')->value('description'))->toBe('Create new extensions');
});

it('updates description on re-sync', function () {
    $this->service->register('extensions', [
        'extensions.view' => 'Old description',
    ]);
    $this->service->syncToDatabase();

    $this->service->register('extensions', [
        'extensions.view' => 'Updated description',
    ]);
    $this->service->syncToDatabase();

    expect(Permission::where('name', 'extensions.view')->value('description'))->toBe('Updated description');
});

it('does not automatically grant newly synced permissions to existing groups', function (): void {
    $group = Group::factory()->system()->create();

    $this->service->register('admin', ['admin.users.view']);
    $this->service->syncToDatabase();

    expect($group->permissions()->exists())->toBeFalse();

    (new AppServiceProvider(app()))->boot();

    expect($group->permissions()->where('name', 'admin.users.view')->exists())->toBeFalse();
});

it('syncs on syncIfStale when cached hash is missing and skips when warm', function (): void {
    Cache::forget('permissions.sync.hash');

    $this->service->register('test', ['test.stale.view']);

    expect($this->service->syncIfStale())->toBeTrue();
    expect(Permission::where('name', 'test.stale.view')->exists())->toBeTrue();
    expect(Cache::get('permissions.sync.hash'))->toBe($this->service->registryHash());

    // Second call is a no-op
    expect($this->service->syncIfStale())->toBeFalse();
});

it('artisan permissions:sync writes current hash to cache', function (): void {
    Cache::forget('permissions.sync.hash');

    $this->service->register('test', ['test.artisan.view']);

    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Permission::where('name', 'test.artisan.view')->exists())->toBeTrue();
    expect(Cache::get('permissions.sync.hash'))->toBe($this->service->registryHash());
});

it('evaluates syncIfStale as false on a fresh unsynced instance when cache is warmed by permissions:sync', function (): void {
    Cache::forget('permissions.sync.hash');

    $this->service->register('extensions', [
        'extensions.view' => 'View extensions',
        'extensions.create' => 'Create extensions',
    ]);
    $this->service->register('admin', [
        'panel.dashboard.view' => 'View panel dashboard',
    ]);

    // Warm cache via artisan permissions:sync
    $this->artisan('permissions:sync')->assertSuccessful();

    expect(Cache::has('permissions.sync.hash'))->toBeTrue();

    // Create a fresh PermissionService instance simulating a new HTTP request boot ($synced = false)
    $freshService = new PermissionService;
    $freshService->register('extensions', [
        'extensions.view' => 'View extensions',
        'extensions.create' => 'Create extensions',
    ]);
    $freshService->register('admin', [
        'panel.dashboard.view' => 'View panel dashboard',
    ]);

    expect($freshService->isSynced())->toBeFalse();

    // Verify syncIfStale returns false and does not trigger synchronization
    expect($freshService->syncIfStale())->toBeFalse()
        ->and($freshService->isSynced())->toBeFalse();
});
