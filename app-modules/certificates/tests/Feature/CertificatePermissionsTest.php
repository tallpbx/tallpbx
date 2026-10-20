<?php

declare(strict_types=1);

namespace Modules\Certificates\Tests\Feature;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Services\MenuService;
use Database\Seeders\AdminSeeder;
use Livewire\Livewire;
use Modules\Certificates\Livewire\CertificateManager;

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);
});

it('has certificates module registered and enabled in the database', function (): void {
    $module = Module::where('name', 'certificates')->first();

    expect($module)->not->toBeNull()
        ->and($module->display_name)->toBe('Certificates')
        ->and($module->enabled)->toBeTrue();
});

it('registers all certificate permissions and assigns them to super administrators', function (): void {
    $expected = [
        'certificates.view',
        'certificates.create',
        'certificates.deploy',
        'certificates.renew',
        'certificates.delete',
    ];

    foreach ($expected as $permName) {
        expect(Permission::where('name', $permName)->exists())->toBeTrue();
    }

    $assigned = $this->superAdminGroup->fresh()->permissions->pluck('name');
    foreach ($expected as $permName) {
        expect($assigned)->toContain($permName);
    }
});

it('registers certificates menu item beside security at order 39.5 on main panel menu', function (): void {
    $menuService = app(MenuService::class);
    $items = $menuService->getFlat('admin');

    $item = collect($items)->firstWhere('key', 'certificates');

    expect($item)->not->toBeNull()
        ->and($item['label'])->toBe('admin.certificates')
        ->and($item['route'])->toBe('panel.certificates.index')
        ->and($item['permission'])->toBe('certificates.view')
        ->and($item['icon'])->toBe('heroicon-o-key')
        ->and($item['guard'])->toBe('admin')
        ->and($item['order'])->toBe(39.5);
});

it('places certificates nav link after security inside the PBX advanced group', function (): void {
    $this->actingAs($this->admin, 'admin');

    $menuService = app(MenuService::class);
    $tree = $menuService->getTree();
    $pbx = collect($tree)->firstWhere('key', 'pbx');
    $advanced = collect($pbx['children'] ?? [])->firstWhere('key', 'pbx.advanced');
    $advancedKeys = collect($advanced['children'] ?? [])->pluck('key')->all();

    // Nav parity: certificates renders under PBX → Advanced (manifest
    // category "advanced"), keeping its order after security within the group.
    $securityIndex = array_search('security', $advancedKeys, true);
    $certificatesIndex = array_search('certificates', $advancedKeys, true);

    expect($securityIndex)->not->toBeFalse()
        ->and($certificatesIndex)->not->toBeFalse()
        ->and($securityIndex)->toBeLessThan($certificatesIndex);
});

it('protects panel.certificates.index with authentication and permission middleware', function (): void {
    // Unauthenticated guest redirects to login
    $this->get(route('panel.certificates.index'))
        ->assertRedirect('/panel/login');

    // Authenticated admin without permission is denied
    $unprivilegedAdmin = Admin::factory()->create(['enabled' => true]);
    $this->actingAs($unprivilegedAdmin, 'admin')
        ->get(route('panel.certificates.index'))
        ->assertForbidden();

    // Authenticated super admin with permission succeeds
    $this->actingAs($this->admin, 'admin')
        ->get(route('panel.certificates.index'))
        ->assertOk()
        ->assertSee(__('admin.certificates'));
});

it('renders CertificateManager Livewire component', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CertificateManager::class)
        ->assertOk()
        ->assertSee(__('admin.certificates'));
});
