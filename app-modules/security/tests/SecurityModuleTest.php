<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Services\MenuService;
use Database\Seeders\AdminSeeder;
use Livewire\Livewire;
use Modules\Security\Livewire\SecurityManager;

/**
 * Feature tests for the Security module scaffolding and registration.
 *
 * Validates module database presence, permission seeding, menu hierarchy,
 * route authentication/authorization, and initial Livewire component rendering.
 */
beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);
});

it('has security module registered and enabled in the database', function (): void {
    $module = Module::where('name', 'security')->first();

    expect($module)->not->toBeNull()
        ->and($module->display_name)->toBe('Security')
        ->and($module->enabled)->toBeTrue();
});

it('registers security permissions and assigns them to super administrators', function (): void {
    $viewPerm = Permission::where('name', 'security.view')->first();
    $editPerm = Permission::where('name', 'security.edit')->first();

    expect($viewPerm)->not->toBeNull()
        ->and($editPerm)->not->toBeNull();

    expect($this->superAdminGroup->fresh()->permissions->pluck('name'))->toContain('security.view', 'security.edit');
});

it('registers security menu item under the PBX advanced group in MenuService', function (): void {
    $menuService = app(MenuService::class);
    $items = $menuService->getFlat('admin');

    $securityItem = collect($items)->firstWhere('key', 'security');

    // Nav parity: the item's parent mirrors the manifest category "advanced"
    // (AGENTS.md invariant), so it groups under PBX → Advanced.
    expect($securityItem)->not->toBeNull()
        ->and($securityItem['label'])->toBe('admin.security')
        ->and($securityItem['route'])->toBe('panel.security.index')
        ->and($securityItem['permission'])->toBe('security.view')
        ->and($securityItem['parent'] ?? null)->toBe('pbx.advanced')
        ->and($securityItem['order'])->toBe(39);
});

it('places security nav link inside the PBX advanced group in menu tree', function (): void {
    $this->actingAs($this->admin, 'admin');

    $menuService = app(MenuService::class);
    $tree = $menuService->getTree();
    $pbx = collect($tree)->firstWhere('key', 'pbx');
    $advanced = collect($pbx['children'] ?? [])->firstWhere('key', 'pbx.advanced');
    $advancedKeys = collect($advanced['children'] ?? [])->pluck('key')->all();

    expect($advancedKeys)->toContain('security');
});

it('protects panel.security.index with auth and permission middleware', function (): void {
    // Unauthenticated guest redirects to login
    $this->get(route('panel.security.index'))
        ->assertRedirect('/panel/login');

    // Authenticated admin with security.view succeeds
    $this->actingAs($this->admin, 'admin')
        ->get(route('panel.security.index'))
        ->assertOk()
        ->assertSee('Security Center');
});

it('renders the SecurityManager Livewire component', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertOk()
        ->assertSee('Security Center')
        ->assertSee('nftables');
});

/*
|--------------------------------------------------------------------------
| Security Module Localization Keys Tests
|--------------------------------------------------------------------------
*/

test('geo and service modal keys resolve in all locales', function (string $locale): void {
    app()->setLocale($locale);

    expect(__('admin.security_rate_burst_packets'))->not->toBe('admin.security_rate_burst_packets');
    expect(__('admin.never'))->not->toBe('admin.never');

    if ($locale === 'fr') {
        expect(__('admin.security_rate_burst_packets'))->toBe('Tolérance de rafale (paquets)');
    }
})->with(['en', 'es', 'fr']);
