<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use Illuminate\Testing\TestResponse;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Factory\Factory;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Modules\Extensions\Livewire\ExtensionsEdit;
use Modules\Extensions\Livewire\ExtensionsList;
use Modules\Extensions\Models\Extension;

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
