<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Menu;
use App\Services\MenuService;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->service = app(MenuService::class);
    $this->service->reset();
});

// ─── In-memory Registration ──────────────────────────────────────────────────

it('registers menu items in memory', function (): void {
    $this->service->register('admin', [
        [
            'key' => 'panel.dashboard',
            'label' => 'Dashboard',
            'route' => 'panel.dashboard',
            'icon' => 'heroicon-o-home',
            'guard' => 'admin',
            'order' => 10,
        ],
    ]);

    $flat = $this->service->getFlat('admin');

    expect($flat)->toHaveCount(1);
    expect($flat[0]['label'])->toBe('Dashboard');
    expect($flat[0]['key'])->toBe('panel.dashboard');
});

// ─── Guard Filtering ─────────────────────────────────────────────────────────

it('appends multiple register calls for the same module', function (): void {
    $this->service->register('admin', [
        [
            'key' => 'panel.dashboard',
            'label' => 'Dashboard',
            'route' => 'panel.dashboard',
            'guard' => 'admin',
            'order' => 10,
        ],
    ]);
    $this->service->register('admin', [
        [
            'key' => 'panel.users',
            'label' => 'Users',
            'route' => 'panel.users.index',
            'guard' => 'admin',
            'order' => 20,
        ],
    ]);

    $flat = $this->service->getFlat('admin');

    expect($flat)->toHaveCount(2);
    expect($flat[0]['key'])->toBe('panel.dashboard');
    expect($flat[1]['key'])->toBe('panel.users');
});

it('filters menu items by guard', function (): void {
    $this->service->register('admin', [
        [
            'key' => 'panel.dashboard',
            'label' => 'Admin Dashboard',
            'route' => 'panel.dashboard',
            'icon' => 'heroicon-o-home',
            'guard' => 'admin',
            'order' => 10,
        ],
        [
            'key' => 'portal.dashboard',
            'label' => 'Portal Dashboard',
            'route' => 'dashboard',
            'icon' => 'heroicon-o-home',
            'guard' => 'web',
            'order' => 10,
        ],
    ]);

    $adminItems = $this->service->getFlat('admin');
    $portalItems = $this->service->getFlat('web');

    expect($adminItems)->toHaveCount(1);
    expect($adminItems[0]['key'])->toBe('panel.dashboard');
    expect($portalItems)->toHaveCount(1);
    expect($portalItems[0]['key'])->toBe('portal.dashboard');
});

// ─── Sorting ─────────────────────────────────────────────────────────────────

it('sorts menu items by order', function (): void {
    $this->service->register('admin', [
        [
            'key' => 'panel.zzz',
            'label' => 'Z',
            'guard' => 'admin',
            'order' => 20,
        ],
        [
            'key' => 'panel.aaa',
            'label' => 'A',
            'guard' => 'admin',
            'order' => 10,
        ],
    ]);

    $flat = $this->service->getFlat('admin');

    expect($flat[0]['key'])->toBe('panel.aaa');
    expect($flat[1]['key'])->toBe('panel.zzz');
});

// ─── Tree Building ───────────────────────────────────────────────────────────

it('builds a tree from flat parent/child items', function (): void {
    $this->service->register('admin', [
        [
            'key' => 'panel.switch',
            'label' => 'Switch',
            'guard' => 'admin',
            'order' => 10,
        ],
        [
            'key' => 'panel.extensions',
            'label' => 'Extensions',
            'route' => 'panel.extensions.index',
            'icon' => 'heroicon-o-users',
            'parent' => 'panel.switch',
            'guard' => 'admin',
            'order' => 10,
        ],
        [
            'key' => 'panel.trunks',
            'label' => 'Trunks',
            'route' => 'panel.trunks.index',
            'icon' => 'heroicon-o-server',
            'parent' => 'panel.switch',
            'guard' => 'admin',
            'order' => 20,
        ],
    ]);

    $tree = $this->service->getTree('admin');

    expect($tree)->toHaveCount(1);
    expect($tree[0]['key'])->toBe('panel.switch');
    expect($tree[0]['children'])->toHaveCount(2);
    expect($tree[0]['children'][0]['key'])->toBe('panel.extensions');
    expect($tree[0]['children'][1]['key'])->toBe('panel.trunks');
});

it('returns flat items that are not part of a tree', function (): void {
    $this->service->register('admin', [
        [
            'key' => 'panel.dashboard',
            'label' => 'Dashboard',
            'guard' => 'admin',
            'order' => 10,
        ],
    ]);

    $tree = $this->service->getTree('admin');

    expect($tree)->toHaveCount(1);
    expect($tree[0]['key'])->toBe('panel.dashboard');
    expect($tree[0])->not->toHaveKey('children');
});

// ─── Permission Gating ───────────────────────────────────────────────────────

it('hides menu items when user lacks the required permission', function (): void {
    Gate::define('panel.secret', fn (): bool => false);

    $this->service->register('admin', [
        [
            'key' => 'panel.dashboard',
            'label' => 'Dashboard',
            'route' => 'panel.dashboard',
            'icon' => 'heroicon-o-home',
            'guard' => 'admin',
            'order' => 10,
        ],
        [
            'key' => 'panel.secret',
            'label' => 'Secret',
            'route' => 'panel.secret',
            'icon' => 'heroicon-o-lock',
            'guard' => 'admin',
            'order' => 20,
            'permission' => 'panel.secret',
        ],
    ]);

    actingAs(Admin::factory()->create(), 'admin');

    $tree = $this->service->getTree('admin');

    expect($tree)->toHaveCount(1);
    expect($tree[0]['key'])->toBe('panel.dashboard');
});

it('shows menu items when user has the required permission', function (): void {
    Gate::define('panel.secret', fn (): bool => true);

    $this->service->register('admin', [
        [
            'key' => 'panel.secret',
            'label' => 'Secret',
            'route' => 'panel.secret',
            'icon' => 'heroicon-o-lock',
            'guard' => 'admin',
            'order' => 10,
            'permission' => 'panel.secret',
        ],
    ]);

    actingAs(Admin::factory()->create(), 'admin');

    $tree = $this->service->getTree('admin');

    expect($tree)->toHaveCount(1);
    expect($tree[0]['key'])->toBe('panel.secret');
});

it('shows menu items when permission is not defined as a gate', function (): void {
    $this->service->register('admin', [
        [
            'key' => 'panel.future',
            'label' => 'Future',
            'route' => 'panel.future',
            'icon' => 'heroicon-o-star',
            'guard' => 'admin',
            'order' => 10,
            'permission' => 'not.yet.defined',
        ],
    ]);

    actingAs(Admin::factory()->create(), 'admin');

    $tree = $this->service->getTree('admin');

    // Permission gates are not defined yet → item is visible (phased approach)
    expect($tree)->toHaveCount(1);
    expect($tree[0]['key'])->toBe('panel.future');
});

it('hides menu items from guests when permission is required', function (): void {
    Gate::define('panel.secret', fn (): bool => false);

    $this->service->register('admin', [
        [
            'key' => 'panel.secret',
            'label' => 'Secret',
            'route' => 'panel.secret',
            'icon' => 'heroicon-o-lock',
            'guard' => 'admin',
            'order' => 10,
            'permission' => 'panel.secret',
        ],
    ]);

    $tree = $this->service->getTree('admin');

    // Not authenticated → item hidden
    expect($tree)->toBeEmpty();
});

// ─── DB Merge ────────────────────────────────────────────────────────────────

it('uses DB items over in-memory when both exist with same key', function (): void {
    $this->service->register('admin', [
        [
            'key' => 'panel.dashboard',
            'label' => 'In-Memory Dashboard',
            'route' => 'panel.dashboard',
            'icon' => 'heroicon-o-home',
            'guard' => 'admin',
            'order' => 10,
        ],
    ]);

    Menu::factory()->create([
        'key' => 'panel.dashboard',
        'label' => 'DB Dashboard Override',
        'route' => 'panel.dashboard',
        'icon' => 'heroicon-o-star',
        'guard' => 'admin',
        'order' => 10,
    ]);

    $flat = $this->service->getFlat('admin');

    expect($flat)->toHaveCount(1);
    expect($flat[0]['label'])->toBe('DB Dashboard Override');
});

it('includes DB-only items not registered in memory', function (): void {
    Menu::factory()->create([
        'key' => 'panel.db-only',
        'label' => 'DB Only',
        'route' => 'panel.dbonly',
        'icon' => 'heroicon-o-database',
        'guard' => 'admin',
        'order' => 10,
        'enabled' => true,
    ]);

    $flat = $this->service->getFlat('admin');

    expect($flat)->toHaveCount(1);
    expect($flat[0]['key'])->toBe('panel.db-only');
});

it('filters out disabled DB items', function (): void {
    $this->service->register('admin', [
        [
            'key' => 'panel.dashboard',
            'label' => 'Dashboard',
            'guard' => 'admin',
            'order' => 10,
        ],
    ]);

    Menu::factory()->create([
        'key' => 'panel.disabled',
        'label' => 'Disabled',
        'guard' => 'admin',
        'order' => 20,
        'enabled' => false,
    ]);

    $flat = $this->service->getFlat('admin');

    expect($flat)->toHaveCount(1);
    expect($flat[0]['key'])->toBe('panel.dashboard');
});

// ─── All Permissions ─────────────────────────────────────────────────────────

it('gracefully handles missing menus table', function (): void {
    Schema::dropIfExists('menus');

    $this->service->register('admin', [
        [
            'key' => 'panel.dashboard',
            'label' => 'Dashboard',
            'route' => 'panel.dashboard',
            'icon' => 'heroicon-o-home',
            'guard' => 'admin',
            'order' => 10,
        ],
    ]);

    $flat = $this->service->getFlat('admin');
    $tree = $this->service->getTree('admin');

    expect($flat)->toHaveCount(1);
    expect($flat[0]['key'])->toBe('panel.dashboard');
    expect($tree)->toHaveCount(1);
    expect($tree[0]['key'])->toBe('panel.dashboard');

    // Restore table for subsequent tests
    $this->artisan('migrate', [
        '--force' => true,
        '--path' => 'database/migrations/2026_06_30_000001_create_menus_table.php',
    ])->run();
});

it('aggregates all registered permissions', function (): void {
    $this->service->registerPermissions('admin', [
        'panel.dashboard.view',
        'panel.settings.edit',
    ]);
    $this->service->registerPermissions('extensions', [
        'extensions.view',
        'extensions.create',
        'extensions.update',
        'extensions.delete',
    ]);

    $all = $this->service->allPermissions();

    expect($all)->toContain('panel.dashboard.view');
    expect($all)->toContain('extensions.create');
    expect($all)->toContain('extensions.delete');
    expect($all)->toHaveCount(6);
});
