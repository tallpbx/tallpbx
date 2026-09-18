<?php

declare(strict_types=1);

use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MenuService;
use Database\Seeders\DatabaseSeeder;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->admin = grantAdminPermissions(permissions: [
        'admin.dashboard.view',
        'admin.users.view',
        'admin.tenants.view',
        'extensions.view',
        'inbound-routes.view',
        'outbound-routes.view',
        'sip-profiles.view',
        'sip-accounts.view',
        'extension-settings.view',
        'gateways.view',
        'devices.view',
        'access-controls.view',
        'call-broadcast.view',
        'feature-codes.view',
        'pin-numbers.view',
        'time-conditions.view',
        'voicemails.view',
        'provision.view',
        'dialplans.view',
        'destinations.view',
    ]);
    $this->user = User::factory()->create();
    $this->tenant = Tenant::factory()->create();
    $this->tenant->users()->attach($this->user, ['role' => 'member']);

    $group = Group::factory()->system()->create(['name' => 'Portal Users']);
    $this->user->groups()->attach($group);
    foreach (['admin.dashboard.view', 'inbound-routes.view', 'outbound-routes.view'] as $permName) {
        $perm = Permission::firstOrCreate([
            'name' => $permName,
            'module' => explode('.', $permName)[0],
        ]);
        $group->permissions()->syncWithoutDetaching([$perm->id]);
    }
});

it('renders all top-level admin menu items', function (): void {
    actingAs($this->admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSeeInOrder(['Dashboard', 'Users', 'Tenants', 'PBX']);
});

it('renders admin PBX sub-items', function (): void {
    actingAs($this->admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSee('Inbound Routes')
        ->assertSee('Outbound Routes')
        ->assertSee('SIP Profiles')
        ->assertSee('Gateways')
        ->assertSee('Extensions')
        ->assertSee('Devices')
        ->assertSee('Access Control Lists')
        ->assertSee('Feature Codes')
        ->assertSee('PIN Numbers')
        ->assertSee('Time Conditions')
        ->assertSee('Voicemails')
        ->assertSee('Provisioning')
        ->assertSee('Dialplans')
        ->assertSee('Destinations')
        ->assertDontSee('Call Control')
        ->assertDontSee('Communications')
        ->assertDontSee('SIP Accounts')
        ->assertDontSee('Extension Settings');
});

it('matches the FusionPBX-style Accounts section without moving Users into PBX', function (): void {
    actingAs($this->admin, 'admin');

    $tree = app(MenuService::class)->getTree();
    $pbx = collect($tree)->firstWhere('key', 'pbx');
    $accounts = collect($pbx['children'] ?? [])->firstWhere('key', 'pbx.accounts');
    $accountKeys = collect($accounts['children'] ?? [])->pluck('key')->all();

    expect($accountKeys)
        ->toBe(['devices', 'extensions', 'gateways'])
        ->not->toContain('users')
        ->not->toContain('sip-accounts')
        ->not->toContain('extension-settings');
});

it('keeps PBX groups flat and places common PBX features under Features', function (): void {
    actingAs($this->admin, 'admin');

    $tree = app(MenuService::class)->getTree();
    $pbx = collect($tree)->firstWhere('key', 'pbx');

    expect($pbx)->not->toBeNull()
        ->and($pbx['flat_children'] ?? false)->toBeTrue();

    $groupKeys = collect($pbx['children'] ?? [])->pluck('key')->all();

    expect($groupKeys)
        ->toContain('pbx.accounts')
        ->toContain('pbx.connectivity')
        ->toContain('pbx.routing')
        ->toContain('pbx.features')
        ->toContain('pbx.media')
        ->toContain('pbx.advanced')
        ->toContain('pbx.monitoring')
        ->not->toContain('pbx.features.routing')
        ->not->toContain('pbx.features.control')
        ->not->toContain('pbx.features.comms')
        ->not->toContain('pbx.provisioning');

    $features = collect($pbx['children'] ?? [])->firstWhere('key', 'pbx.features');
    $featureKeys = collect($features['children'] ?? [])->pluck('key')->all();

    expect($featureKeys)
        ->toContain('feature-codes')
        ->toContain('pin-numbers')
        ->toContain('time-conditions')
        ->toContain('voicemails')
        ->toContain('provision');
});

it('renders admin menu items as links with correct routes', function (): void {
    actingAs($this->admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSee(route('panel.dashboard'))
        ->assertSee(route('panel.users.index'))
        ->assertSee(route('panel.tenants.index'))
        ->assertSee(route('panel.extensions.index'))
        ->assertSee(route('panel.gateways.index'));
});

it('highlights current admin menu item', function (): void {
    actingAs($this->admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSee('data-current:menu-active', false);
});

it('renders reported deep panel links in sidebar', function (string $routeName): void {
    actingAs($this->admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSee(route($routeName), false);
})->with([
    'access controls' => 'panel.access-controls.index',
    'destinations' => 'panel.destinations.index',
    'dialplans' => 'panel.dialplans.index',
]);

it('renders the unified panel sidebar with a persisted scroll container', function (): void {
    actingAs($this->admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSee('wire:navigate.preserve-scroll', false)
        ->assertSee('wire:navigate:scroll', false)
        ->assertSee('data-panel-sidebar-scroll="drawer"', false)
        ->assertSee('data-panel-sidebar-scroll="nav"', false);

    expect(file_get_contents(resource_path('views/layouts/app.blade.php')))
        ->toContain('@persist(\'panel-sidebar\')')
        ->toContain('data-panel-sidebar-scroll="drawer"')
        ->toContain('data-panel-sidebar-scroll="nav"');
});

it('keeps sidebar scroll JavaScript scoped to the unified panel layout', function (): void {
    $script = file_get_contents(resource_path('js/app.js'));

    expect($script)
        ->toContain('[data-panel-sidebar-scroll]')
        ->toContain('sidebarNavigateLinkSelector')
        ->toContain('wire\\\\:navigate\\\\.preserve-scroll')
        ->toContain('sidebarScrollKey')
        ->not->toContain('[data-admin-sidebar-scroll]')
        ->not->toContain('legacySidebarScrollStorageKey');
});

it('does not reference the removed admin layout', function (): void {
    $directories = [
        resource_path('views'),
        base_path('app-modules'),
    ];

    $files = collect($directories)
        ->flatMap(function (string $directory): array {
            $files = [];
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));

            foreach ($iterator as $file) {
                if ($file->isFile() && in_array($file->getExtension(), ['php'], true)) {
                    $files[] = $file->getPathname();
                }
            }

            return $files;
        });

    foreach ($files as $file) {
        expect(file_get_contents($file))->not->toContain('layouts.admin');
    }
});

it('loads admin sub-page successfully', function (): void {
    actingAs($this->admin, 'admin')
        ->get(route('panel.users.index'))
        ->assertOk();
});

it('renders admin PBX as a group header without a link', function (): void {
    $response = actingAs($this->admin, 'admin')
        ->get(route('panel.dashboard'))
        ->assertOk();
    $content = $response->getContent();
    expect(str_contains($content, 'PBX'))->toBeTrue();
});

it('does not show admin-only menu items in portal sidebar', function (): void {
    $response = actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenant->id])
        ->get(route('panel.dashboard'))
        ->assertOk();
    $content = $response->getContent();
    $navStart = strpos($content, '<nav');
    $navEnd = strpos($content, '</nav>', $navStart !== false ? $navStart : 0);
    $navContent = $navStart !== false && $navEnd !== false
        ? substr($content, $navStart, $navEnd - $navStart)
        : $content;
    expect(str_contains($navContent, 'Users'))->toBeFalse();
    expect(str_contains($navContent, 'Tenants'))->toBeFalse();
});

it('renders portal menu items for tenant users', function (): void {
    actingAs($this->user)
        ->withSession(['selected_tenant_id' => (string) $this->tenant->id])
        ->get(route('panel.dashboard'))
        ->assertOk()
        ->assertSee('Dashboard');
});

it('renders PBX sidebar links for the seeded tenant administrator', function (): void {
    $previousDemoMode = config('freeswitch.demo_mode');
    config(['freeswitch.demo_mode' => true]);

    try {
        $this->seed(DatabaseSeeder::class);

        $user = User::where('email', 'user@tallpbx.org')->firstOrFail();
        $tenant = Tenant::where('slug', 'tallpbx')->firstOrFail();

        $response = actingAs($user)
            ->withSession(['selected_tenant_id' => (string) $tenant->id])
            ->get(route('panel.dashboard'))
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Extensions')
            ->assertSee('Inbound Routes')
            ->assertSee('Outbound Routes');

        $content = $response->getContent();
        $navStart = strpos($content, '<nav');
        $navEnd = strpos($content, '</nav>', $navStart !== false ? $navStart : 0);
        $navContent = $navStart !== false && $navEnd !== false
            ? substr($content, $navStart, $navEnd - $navStart)
            : $content;

        expect(str_contains($navContent, 'Users'))->toBeFalse()
            ->and(str_contains($navContent, 'Tenants'))->toBeFalse();
    } finally {
        config(['freeswitch.demo_mode' => $previousDemoMode]);
    }
});
