<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\TenantManager;
use App\Support\LivewireActionPermissions;
use Database\Seeders\AdminSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Livewire\Component;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Factory\Factory;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Modules\Admin\Livewire\AdminsEdit;
use Modules\Admin\Livewire\GitUpdate;
use Modules\Admin\Livewire\NotificationsList;
use Modules\Admin\Livewire\ProfileEdit;
use Modules\Admin\Livewire\SettingsEdit;
use Modules\Auth\Livewire\ForgotPassword;
use Modules\CallBroadcast\Livewire\BroadcastCreate;
use Modules\EventRateLimits\Livewire\EventRateLimitsSettings;
use Modules\Extensions\Livewire\ExtensionsEdit;
use Modules\Extensions\Livewire\ExtensionsList;
use Modules\Extensions\Models\Extension;
use Modules\Tenant\Livewire\Dashboard;

/**
 * Action-level permission enforcement for the Livewire update endpoint.
 *
 * Page routes are guarded by admin.can:{module}.{action} middleware, but
 * Livewire mutation actions travel through the shared update endpoint,
 * which historically carried no permission checks at all. A tenant user
 * holding only the view permission could therefore delete records simply
 * by invoking the component's delete action. These tests drive the real
 * HTTP update endpoint with snapshots captured from rendered pages.
 */

/**
 * Resolve the per-installation Livewire update endpoint path.
 */
function livewireUpdateEndpoint(): string
{
    return EndpointResolver::updatePath();
}

/**
 * Grant the given permission names to a tenant user through a tenant group.
 *
 * Mirrors how the panel assigns permissions: each permission becomes a
 * database row attached to a group that belongs to the user's tenant.
 *
 * @param  array<int, string>  $permissions  Permission names to grant
 */
function grantTenantPermissions(User $user, Tenant $tenant, array $permissions): void
{
    $group = Group::factory()->forTenant($tenant->id)->create();

    foreach ($permissions as $name) {
        $permission = Permission::firstOrCreate(
            ['name' => $name],
            [
                'module' => explode('.', $name)[0] ?? 'test',
                'description' => 'Test permission',
            ],
        );

        $group->permissions()->attach($permission);
    }

    $user->groups()->attach($group);
}

/**
 * Extract a component's signed snapshot JSON from rendered page HTML.
 *
 * The snapshot is captured exactly as Livewire emitted it so that the
 * checksum remains valid when it is replayed against the update endpoint.
 * Snapshot memo names are aliases (e.g. extensions.extensions-list), so
 * each candidate is resolved back to its class through Livewire's factory.
 */
function extractComponentSnapshot(string $html, string $componentClass): string
{
    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $matches);

    foreach ($matches[1] as $encoded) {
        $snapshot = html_entity_decode($encoded, ENT_QUOTES, 'UTF-8');
        $decoded = json_decode($snapshot, true);
        $name = $decoded['memo']['name'] ?? null;

        if ($name === null) {
            continue;
        }

        try {
            /** @var Factory $factory */
            $factory = app('livewire.factory');
            $resolved = $factory->resolveComponentClass($name);
        } catch (ComponentNotFoundException) {
            continue;
        }

        if ($resolved === $componentClass) {
            return $snapshot;
        }
    }

    throw new RuntimeException("No snapshot found for {$componentClass} in the rendered page.");
}

/**
 * POST one or more component updates to the Livewire update endpoint.
 *
 * @param  array<int, array{snapshot: string, updates?: array, calls?: array}>  $components
 * @return TestResponse
 */
function postLivewireUpdate(array $components)
{
    $payload = ['components' => array_map(
        fn (array $component): array => [
            'snapshot' => $component['snapshot'],
            'updates' => $component['updates'] ?? [],
            'calls' => $component['calls'] ?? [],
        ],
        $components,
    )];

    return test()->postJson(livewireUpdateEndpoint(), $payload, ['X-Livewire' => 'true']);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->extension = Extension::factory()->create(['tenant_id' => $this->tenant->id]);
});

afterEach(function () {
    app(TenantManager::class)->clear();
});

it('blocks a view-only tenant user from calling delete actions through the update endpoint', function () {
    $this->skipWhenModuleUninstalled('extensions');

    $user = tenantUser($this->tenant);
    grantTenantPermissions($user, $this->tenant, ['extensions.view']);

    // Capture a legitimate, signed snapshot of the list page.
    $page = $this->actingAs($user, 'web')->get('/panel/extensions')->assertOk();
    $snapshot = extractComponentSnapshot($page->getContent(), ExtensionsList::class);

    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [
            ['method' => 'confirmExtensionDeletion', 'params' => [$this->extension->id]],
            ['method' => 'deleteExtension', 'params' => []],
        ],
    ]])->assertForbidden();

    $this->assertDatabaseHas('extensions', ['id' => $this->extension->id]);
});

it('still lets a view-only tenant user sync properties and use confirmation dialogs', function () {
    $this->skipWhenModuleUninstalled('extensions');

    $user = tenantUser($this->tenant);
    grantTenantPermissions($user, $this->tenant, ['extensions.view']);

    $page = $this->actingAs($user, 'web')->get('/panel/extensions')->assertOk();
    $snapshot = extractComponentSnapshot($page->getContent(), ExtensionsList::class);

    // Property sync (the pending-deletion marker) carries no action calls.
    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'updates' => ['pendingDeletionId' => (string) $this->extension->id],
    ]])->assertSuccessful();

    // Opening the confirmation dialog is harmless UI state.
    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [
            ['method' => 'confirmExtensionDeletion', 'params' => [$this->extension->id]],
        ],
    ]])->assertSuccessful();
});

it('allows a tenant user with the delete permission to delete through the update endpoint', function () {
    $this->skipWhenModuleUninstalled('extensions');

    $user = tenantUser($this->tenant);
    grantTenantPermissions($user, $this->tenant, ['extensions.view', 'extensions.delete']);

    $page = $this->actingAs($user, 'web')->get('/panel/extensions')->assertOk();
    $snapshot = extractComponentSnapshot($page->getContent(), ExtensionsList::class);

    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [
            ['method' => 'confirmExtensionDeletion', 'params' => [$this->extension->id]],
            ['method' => 'deleteExtension', 'params' => []],
        ],
    ]])->assertSuccessful();

    $this->assertDatabaseMissing('extensions', ['id' => $this->extension->id]);
});

it('blocks a view-only tenant user from saving an edit component', function () {
    $this->skipWhenModuleUninstalled('extensions');

    // Capture the edit form snapshot with a fully privileged user, then
    // replay it as a view-only user — snapshots are signed but not bound
    // to a user, so this is exactly the replay a restricted user performs.
    $privileged = tenantUser($this->tenant);
    grantTenantPermissions($privileged, $this->tenant, ['extensions.view', 'extensions.create', 'extensions.edit']);

    $page = $this->actingAs($privileged, 'web')->get('/panel/extensions/create')->assertOk();
    $snapshot = extractComponentSnapshot($page->getContent(), ExtensionsEdit::class);

    $viewOnly = tenantUser($this->tenant);
    grantTenantPermissions($viewOnly, $this->tenant, ['extensions.view']);

    $this->actingAs($viewOnly, 'web');

    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [['method' => 'save', 'params' => []]],
    ]])->assertForbidden();
});

it('requires an authenticated panel actor before touching module components', function () {
    $this->skipWhenModuleUninstalled('extensions');

    $user = tenantUser($this->tenant);
    grantTenantPermissions($user, $this->tenant, ['extensions.view']);

    $page = $this->actingAs($user, 'web')->get('/panel/extensions')->assertOk();
    $snapshot = extractComponentSnapshot($page->getContent(), ExtensionsList::class);

    // Anonymous request — the app boots a fresh request context here. The
    // middleware refuses the update; the exception handler then applies the
    // app-wide guest policy (redirectGuestsTo) and sends the browser to the
    // panel login page.
    auth('web')->logout();
    auth('admin')->logout();

    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [['method' => 'deleteExtension', 'params' => []]],
    ]])->assertRedirect(route('panel.login'));

    $this->assertDatabaseHas('extensions', ['id' => $this->extension->id]);
});

it('enforces the same action gate for restricted admins', function () {
    $this->skipWhenModuleUninstalled('extensions');

    $admin = grantAdminPermissions(null, ['panel.dashboard.view', 'extensions.view']);

    $page = $this->actingAs($admin, 'admin')->get('/panel/extensions')->assertOk();
    $snapshot = extractComponentSnapshot($page->getContent(), ExtensionsList::class);

    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [
            ['method' => 'confirmExtensionDeletion', 'params' => [$this->extension->id]],
            ['method' => 'deleteExtension', 'params' => []],
        ],
    ]])->assertForbidden();

    $this->assertDatabaseHas('extensions', ['id' => $this->extension->id]);
});

it('lets a fully privileged admin delete through the update endpoint', function () {
    $this->skipWhenModuleUninstalled('extensions');

    $admin = grantAdminPermissions(null, [
        'panel.dashboard.view',
        'extensions.view',
        'extensions.edit',
        'extensions.delete',
    ]);

    $page = $this->actingAs($admin, 'admin')->get('/panel/extensions')->assertOk();
    $snapshot = extractComponentSnapshot($page->getContent(), ExtensionsList::class);

    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [
            ['method' => 'confirmExtensionDeletion', 'params' => [$this->extension->id]],
            ['method' => 'deleteExtension', 'params' => []],
        ],
    ]])->assertSuccessful();

    $this->assertDatabaseMissing('extensions', ['id' => $this->extension->id]);
});

it('does not block guest unauthenticated requests to auth module components', function () {
    auth('web')->logout();
    auth('admin')->logout();

    $page = $this->get('/panel/forgot-password')->assertOk();
    $snapshot = extractComponentSnapshot($page->getContent(), ForgotPassword::class);

    $response = postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [['method' => 'sendResetLink', 'params' => []]],
    ]]);

    expect($response->status())->not->toBeIn([401, 403]);
});

it('allows tenant user with zero permissions on dashboard and self notifications while denying admin without notification delete', function () {
    $user = tenantUser($this->tenant);
    $dashboardPage = $this->actingAs($user, 'web')->get('/panel/dashboard')->assertOk();
    $dashboardSnapshot = extractComponentSnapshot($dashboardPage->getContent(), Dashboard::class);

    postLivewireUpdate([[
        'snapshot' => $dashboardSnapshot,
        'updates' => [],
    ]])->assertSuccessful();

    $notification = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\SystemAlert',
        'notifiable_type' => User::class,
        'notifiable_id' => $user->id,
        'data' => ['title' => 'Test Notification', 'message' => 'Hello'],
        'read_at' => null,
    ]);

    // Capture NotificationsList snapshot using an admin with view permission
    $adminWithView = grantAdminPermissions(null, ['panel.dashboard.view', 'admin.notifications.view']);
    $notifPage = $this->actingAs($adminWithView, 'admin')->get('/panel/notifications')->assertOk();
    $notifSnapshot = extractComponentSnapshot($notifPage->getContent(), NotificationsList::class);

    // Switch to tenant user: logout admin so tenant user is the resolved actor
    auth('admin')->logout();
    $this->actingAs($user, 'web');

    postLivewireUpdate([[
        'snapshot' => $notifSnapshot,
        'calls' => [['method' => 'markAsRead', 'params' => [$notification->id]]],
    ]])->assertSuccessful();

    postLivewireUpdate([[
        'snapshot' => $notifSnapshot,
        'calls' => [['method' => 'deleteNotification', 'params' => [$notification->id]]],
    ]])->assertSuccessful();

    $this->assertDatabaseMissing('notifications', ['id' => $notification->id]);

    $restrictedAdmin = grantAdminPermissions(null, ['panel.dashboard.view', 'admin.notifications.view']);
    $adminNotif = DatabaseNotification::create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\SystemAlert',
        'notifiable_type' => Admin::class,
        'notifiable_id' => $restrictedAdmin->id,
        'data' => ['title' => 'Admin Notice', 'message' => 'Hi'],
        'read_at' => null,
    ]);

    $this->actingAs($restrictedAdmin, 'admin');

    postLivewireUpdate([[
        'snapshot' => $notifSnapshot,
        'calls' => [['method' => 'deleteNotification', 'params' => [$adminNotif->id]]],
    ]])->assertForbidden();
});

it('blocks an admin with only view permission from saving or deleting settings', function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $superAdmin = Admin::factory()->create(['enabled' => true]);
    $superAdmin->groups()->attach($superAdminGroup->id);

    $page = $this->actingAs($superAdmin, 'admin')->get('/panel/settings')->assertOk();
    $snapshot = extractComponentSnapshot($page->getContent(), SettingsEdit::class);

    $restrictedAdmin = grantAdminPermissions(null, ['panel.dashboard.view', 'admin.settings.view']);
    $this->actingAs($restrictedAdmin, 'admin');

    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [['method' => 'saveSetting', 'params' => []]],
    ]])->assertForbidden();

    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [['method' => 'deleteSetting', 'params' => []]],
    ]])->assertForbidden();
});

it('blocks an admin with only view permission from executing git update actions', function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $superAdmin = Admin::factory()->create(['enabled' => true]);
    $superAdmin->groups()->attach($superAdminGroup->id);

    $page = $this->actingAs($superAdmin, 'admin')->get('/panel/git-update')->assertOk();
    $snapshot = extractComponentSnapshot($page->getContent(), GitUpdate::class);

    $restrictedAdmin = grantAdminPermissions(null, ['panel.dashboard.view', 'admin.git-update.view']);
    $this->actingAs($restrictedAdmin, 'admin');

    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [['method' => 'updateApp', 'params' => []]],
    ]])->assertForbidden();

    postLivewireUpdate([[
        'snapshot' => $snapshot,
        'calls' => [['method' => 'fetch', 'params' => []]],
    ]])->assertForbidden();
});

it('blocks an impersonating super admin acting as a tenant user from mutating admin components', function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $superAdmin = Admin::factory()->create(['enabled' => true]);
    $superAdmin->groups()->attach($superAdminGroup->id);

    $adminsPage = $this->actingAs($superAdmin, 'admin')->get('/panel/admins/create')->assertOk();
    $adminsSnapshot = extractComponentSnapshot($adminsPage->getContent(), AdminsEdit::class);

    $settingsPage = $this->actingAs($superAdmin, 'admin')->get('/panel/settings')->assertOk();
    $settingsSnapshot = extractComponentSnapshot($settingsPage->getContent(), SettingsEdit::class);

    $tenantUser = tenantUser($this->tenant);
    session(['impersonation.original_admin_id' => $superAdmin->id]);
    $this->actingAs($tenantUser, 'web');

    postLivewireUpdate([[
        'snapshot' => $adminsSnapshot,
        'calls' => [['method' => 'save', 'params' => []]],
    ]])->assertForbidden();

    postLivewireUpdate([[
        'snapshot' => $settingsSnapshot,
        'calls' => [['method' => 'saveSetting', 'params' => []]],
    ]])->assertForbidden();
});

it('blocks a view-only user from saving BroadcastCreate and EventRateLimitsSettings', function () {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $superAdmin = Admin::factory()->create(['enabled' => true]);
    $superAdmin->groups()->attach($superAdminGroup->id);

    $broadcastPage = $this->actingAs($superAdmin, 'admin')->get('/panel/call-broadcast/create')->assertOk();
    $broadcastSnapshot = extractComponentSnapshot($broadcastPage->getContent(), BroadcastCreate::class);

    $rateLimitsPage = $this->actingAs($superAdmin, 'admin')->get('/panel/event-rate-limits')->assertOk();
    $rateLimitsSnapshot = extractComponentSnapshot($rateLimitsPage->getContent(), EventRateLimitsSettings::class);

    $restrictedAdmin = grantAdminPermissions(null, [
        'panel.dashboard.view',
        'call-broadcast.view',
        'event-rate-limits.view',
    ]);
    $this->actingAs($restrictedAdmin, 'admin');

    postLivewireUpdate([[
        'snapshot' => $broadcastSnapshot,
        'calls' => [['method' => 'save', 'params' => []]],
    ]])->assertForbidden();

    postLivewireUpdate([[
        'snapshot' => $rateLimitsSnapshot,
        'calls' => [['method' => 'save', 'params' => []]],
    ]])->assertForbidden();
});

it('ensures every public action method across all panel Livewire components is mapped to valid registered permissions', function () {
    $service = app(PermissionService::class);
    $allPermissions = $service->all();
    $resolver = app(LivewireActionPermissions::class);

    $files = array_merge(
        glob(base_path('app-modules/*/src/Livewire/*.php')) ?: [],
        glob(base_path('app-modules/*/src/Livewire/**/*.php')) ?: []
    );
    $files = array_values(array_unique($files));
    expect($files)->not->toBeEmpty();

    $excludedMethods = ['boot', 'mount', 'render'];

    foreach ($files as $file) {
        if (str_contains($file, 'app-modules/auth/')) {
            continue;
        }

        $content = file_get_contents($file);
        if (! preg_match('/namespace\s+([^;]+);/', $content, $m1)) {
            continue;
        }
        if (! preg_match('/class\s+(\w+)/', $content, $m2)) {
            continue;
        }

        $class = $m1[1].'\\'.$m2[1];
        if (! class_exists($class)) {
            continue;
        }

        $ref = new ReflectionClass($class);
        if ($ref->isAbstract()) {
            continue;
        }

        $methods = [''];
        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $methodRef) {
            if ($methodRef->class === Component::class || $methodRef->isStatic()) {
                continue;
            }
            $name = $methodRef->name;
            if (in_array($name, $excludedMethods, true)) {
                continue;
            }
            if (str_starts_with($name, 'hydrate') || str_starts_with($name, 'dehydrate') || str_starts_with($name, 'updating') || str_starts_with($name, 'updated')) {
                continue;
            }
            $methods[] = $name;
        }

        foreach ($methods as $method) {
            $abilities = $resolver->abilitiesFor($class, $method);

            expect($abilities)
                ->not->toBeEmpty("{$class}::{$method} resolved to empty abilities")
                ->not->toBe([['__unmapped_deny__']], "{$class}::{$method} resolved to fail-closed __unmapped_deny__");

            foreach ($abilities as $group) {
                foreach ($group as $ability) {
                    if ($ability === '*') {
                        expect(in_array($class, [ProfileEdit::class, Dashboard::class], true))
                            ->toBeTrue("Wildcard ability '*' only permitted on ProfileEdit and Tenant Dashboard, found on {$class}::{$method}");
                    } elseif ($ability === '@tenant-user') {
                        expect($class)->toBe(
                            NotificationsList::class,
                            "Actor ability '@tenant-user' only permitted on NotificationsList, found on {$class}::{$method}"
                        );
                    } else {
                        expect(in_array($ability, $allPermissions, true))
                            ->toBeTrue("Ability '{$ability}' returned by {$class}::{$method} is not registered in PermissionService");
                    }
                }
            }
        }
    }
});
