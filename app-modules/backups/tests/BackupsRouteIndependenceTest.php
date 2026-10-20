<?php

declare(strict_types=1);

use App\Services\MenuService;
use App\Services\PermissionService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Modules\Backups\Services\BackupServiceInterface;
use Modules\Backups\Services\RestoreOperationExecutor;

// ─── Route ownership ─────────────────────────────────────────────

it('registers the panel backups routes through the backups module', function (): void {
    // Behavioral: every backup panel route resolves.
    foreach (['panel.backups.index', 'panel.backups.create', 'panel.backups.edit', 'panel.backups.restore'] as $name) {
        expect(Route::getRoutes()->getByName($name))->not->toBeNull("Route [{$name}] must be registered.");
    }

    // Ownership: the route definitions live in the Backups module, not the admin module.
    expect(is_file(base_path('app-modules/backups/routes/web.php')))
        ->toBeTrue('The Backups module must own its own routes/web.php.');

    $adminRoutes = (string) file_get_contents(base_path('app-modules/admin/routes/web.php'));

    expect(str_contains($adminRoutes, 'Backups'))
        ->toBeFalse('The admin module must not register Backups routes.');
});

// ─── Menu and permission ownership ───────────────────────────────

it('registers the backups menu item at top level from the backups module', function (): void {
    /** @var MenuService $menu */
    $menu = app(MenuService::class);
    $registrations = (function (): array {
        // The registrations map is private; tests read it to assert module
        // ownership of menu entries.
        $property = new ReflectionProperty($this, 'registrations');

        return $property->getValue($this);
    })->call($menu);

    // The Backups module owns the entry as a flat top-level sidebar item.
    $backupsItems = $registrations['backups'] ?? [];
    $entry = null;

    foreach ($backupsItems as $item) {
        if (($item['key'] ?? null) === 'backups') {
            $entry = $item;
        }
    }

    expect($entry)->not->toBeNull('The backups menu entry must be registered by the backups module.')
        ->and($entry['parent'] ?? null)->toBeNull('Backups must be a top-level sidebar entry (parent: null).')
        ->and($entry['order'] ?? null)->toBe(85)
        ->and($entry['route'] ?? null)->toBe('panel.backups.index');

    // The admin module must no longer carry the entry.
    foreach ($registrations['admin'] ?? [] as $item) {
        expect($item['key'] ?? null)->not->toBe('backups', 'The admin module must not register the backups menu entry.');
    }

    // Behavioral sanity: the merged flat menu still exposes the entry at the
    // top level — a stale menus DB row must not resurrect the old placement.
    $flat = collect($menu->getFlat())->first(fn (array $item): bool => ($item['key'] ?? null) === 'backups');

    expect($flat)->not->toBeNull()
        ->and($flat['parent'] ?? null)->toBeNull('The merged sidebar entry must stay top-level.')
        ->and($flat['order'] ?? null)->toBe(85);
});

it('registers the backups permissions from the backups module', function (): void {
    /** @var PermissionService $permissions */
    $permissions = app(PermissionService::class);
    $grouped = $permissions->grouped();

    $backupsNames = array_column($grouped['backups'] ?? [], 'name');

    foreach (['backups.view', 'backups.create', 'backups.update', 'backups.delete', 'backups.run', 'backups.restore'] as $name) {
        expect($backupsNames)->toContain($name);
    }

    $adminNames = array_column($grouped['admin'] ?? [], 'name');

    foreach ($adminNames as $name) {
        expect(str_starts_with((string) $name, 'backups.'))
            ->toBeFalse('The admin module must not register backups permissions.');
    }
});

// ─── custom-modules/ disaster-recovery scope ─────────────────────

it('includes custom-modules in the backup archive for the app files scope', function (): void {
    $service = app(BackupServiceInterface::class);
    $fixtureRoot = storage_path('framework/testing/backups-archive-'.uniqid('', true));
    $source = $fixtureRoot.'/custom-modules';
    // No dot prefix: Symfony Finder (File::directories) skips dot directories.
    $token = 'route-independence-'.uniqid('', true);

    File::ensureDirectoryExists($source.'/'.$token, 0755, true);
    file_put_contents($source.'/'.$token.'/marker.txt', 'backup me');
    file_put_contents($source.'/.gitkeep', 'keep me');

    $originalBasePath = base_path();

    try {
        // Point base_path at an isolated fixture root: archiveCustomModules
        // reads base_path('custom-modules'), and the developer's real
        // custom-modules directory must never be touched by a test.
        app()->setBasePath($fixtureRoot);

        $tempDir = $fixtureRoot.'/archive-staging';
        File::ensureDirectoryExists($tempDir, 0755, true);

        // archiveCustomModules() is private; invoke it directly so the test
        // exercises the real archiving behavior.
        $method = new ReflectionMethod($service, 'archiveCustomModules');
        $method->invoke($service, $tempDir);

        expect(is_file($tempDir.'/custom_modules/'.$token.'/marker.txt'))
            ->toBeTrue('The custom-modules directory must be captured into the backup archive.')
            ->and(is_file($tempDir.'/custom_modules/.gitkeep'))
            ->toBeTrue('Root dotfiles must be captured into the backup archive.');
    } finally {
        app()->setBasePath($originalBasePath);
        File::deleteDirectory($fixtureRoot);
    }
});

it('restores the custom-modules directory when the app files scope is selected', function (): void {
    // The restore machinery lives on the executor, not the backup service.
    $executor = app(RestoreOperationExecutor::class);
    $fixtureRoot = storage_path('framework/testing/backups-restore-'.uniqid('', true));
    $target = $fixtureRoot.'/custom-modules';
    $token = 'route-independence-'.uniqid('', true);

    // The sandbox starts with a pre-backup module and one "installed after
    // the backup" — the latter must survive a per-module restore.
    File::ensureDirectoryExists($target.'/'.$token, 0755, true);
    file_put_contents($target.'/'.$token.'/stale.txt', 'to be replaced');
    File::ensureDirectoryExists($target.'/post-backup-module', 0755, true);
    file_put_contents($target.'/post-backup-module/keep.txt', 'installed after the backup');

    $originalBasePath = base_path();

    try {
        // Point base_path at an isolated fixture root: restoreSelectedFiles
        // resolves base_path('custom-modules'), and the developer's real
        // custom-modules directory must never be touched by a test.
        app()->setBasePath($fixtureRoot);

        // Craft an extracted-archive root shaped like a real backup.
        $archiveRoot = $fixtureRoot.'/archive';
        File::ensureDirectoryExists($archiveRoot.'/custom_modules/'.$token, 0755, true);
        file_put_contents($archiveRoot.'/custom_modules/'.$token.'/restored.txt', 'restored content');
        file_put_contents($archiveRoot.'/custom_modules/.gitkeep', 'keep me');

        $method = new ReflectionMethod($executor, 'restoreSelectedFiles');
        $method->invoke($executor, $archiveRoot, ['app_files']);

        expect(is_file($target.'/'.$token.'/restored.txt'))
            ->toBeTrue('Selecting the app files scope must restore the custom-modules directory.')
            ->and(is_file($target.'/'.$token.'/stale.txt'))
            ->toBeFalse('The restore must replace the module tree from the archive.')
            ->and(is_file($target.'/post-backup-module/keep.txt'))
            ->toBeTrue('Modules installed after the backup must survive a restore.')
            ->and(is_file($target.'/.gitkeep'))
            ->toBeTrue('Root dotfiles from the archive must be restored.');
    } finally {
        app()->setBasePath($originalBasePath);
        File::deleteDirectory($fixtureRoot);
    }
});

// ─── Disaster-recovery availability ──────────────────────────────

it('marks the backups module as required so disaster recovery stays reachable', function (): void {
    $manifest = json_decode((string) file_get_contents(base_path('app-modules/backups/module.json')), true);

    expect($manifest['required'] ?? false)
        ->toBeTrue('Backups must not be disableable: a disabled Backups module would strand disaster recovery.');
});
