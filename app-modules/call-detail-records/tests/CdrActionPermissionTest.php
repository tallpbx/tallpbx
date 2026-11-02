<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use App\Support\LivewireActionPermissions;
use Illuminate\Testing\TestResponse;
use Livewire\Exceptions\ComponentNotFoundException;
use Livewire\Factory\Factory;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Modules\CallDetailRecords\Livewire\CdrList;
use Modules\CallDetailRecords\Models\Cdr;

/**
 * Action-level permission enforcement for the CDR module.
 *
 * Page routes are guarded by admin.can middleware, but Livewire mutation
 * actions travel through the shared update endpoint, where the resolver in
 * App\Support\LivewireActionPermissions enforces permissions by module
 * naming convention. The module must register its permissions under the
 * call-detail-records namespace so the resolver — which derives "call-detail-records" from the
 * component namespace — finds them and gates the delete action.
 */

/**
 * Resolve the per-installation Livewire update endpoint path.
 */
function cdrUpdateEndpoint(): string
{
    return EndpointResolver::updatePath();
}

/**
 * Extract the signed CdrList snapshot JSON from rendered page HTML.
 *
 * The snapshot is captured exactly as Livewire emitted it so that the
 * checksum remains valid when it is replayed against the update endpoint.
 * Snapshot memo names are aliases (e.g. call-detail-records.cdr-list), so each
 * candidate is resolved back to its class through Livewire's factory.
 */
function cdrListSnapshot(string $html): string
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

        if ($resolved === CdrList::class) {
            return $snapshot;
        }
    }

    throw new RuntimeException('No snapshot found for '.CdrList::class.' in the rendered page.');
}

/**
 * POST one component update to the Livewire update endpoint.
 *
 * @param  array<int, array{snapshot: string, updates?: array, calls?: array}>  $components
 */
function postCdrUpdate(array $components): TestResponse
{
    $payload = ['components' => array_map(
        fn (array $component): array => [
            'snapshot' => $component['snapshot'],
            'updates' => $component['updates'] ?? [],
            'calls' => $component['calls'] ?? [],
        ],
        $components,
    )];

    return test()->postJson(cdrUpdateEndpoint(), $payload, ['X-Livewire' => 'true']);
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

afterEach(function () {
    app(TenantManager::class)->clear();
});

it('resolves the delete ability for the deleteCdr action from the call-detail-records namespaced permission', function () {
    $abilities = app(LivewireActionPermissions::class)->abilitiesFor(CdrList::class, 'deleteCdr');

    expect($abilities)->toBe([['call-detail-records.delete']]);
});

it('blocks a viewing-only user from deleting a call detail record through the update endpoint', function () {
    $user = grantTenantUserPermissions($this->tenant, ['call-detail-records.view']);
    $cdr = Cdr::factory()->create(['tenant_id' => $this->tenant->id]);

    // Capture a legitimate, signed snapshot of the list page as the user
    // would legitimately receive it — snapshots are signed but not bound
    // to a user, exactly as a browser presents them.
    $page = $this->actingAs($user, 'web')->get('/panel/cdr')->assertOk();
    $snapshot = cdrListSnapshot($page->getContent());

    postCdrUpdate([[
        'snapshot' => $snapshot,
        'calls' => [
            ['method' => 'confirmCdrDeletion', 'params' => [$cdr->id]],
            ['method' => 'deleteCdr', 'params' => []],
        ],
    ]])->assertForbidden();

    $this->assertDatabaseHas('cdrs', ['id' => $cdr->id]);
});

it('lets a user with the delete permission delete through the update endpoint', function () {
    $user = grantTenantUserPermissions($this->tenant, ['call-detail-records.view', 'call-detail-records.delete']);
    $cdr = Cdr::factory()->create(['tenant_id' => $this->tenant->id]);

    $page = $this->actingAs($user, 'web')->get('/panel/cdr')->assertOk();
    $snapshot = cdrListSnapshot($page->getContent());

    // Positive control: proves the harness genuinely reaches the delete
    // action, so the blocked assertion above is a real denial gate.
    postCdrUpdate([[
        'snapshot' => $snapshot,
        'calls' => [
            ['method' => 'confirmCdrDeletion', 'params' => [$cdr->id]],
            ['method' => 'deleteCdr', 'params' => []],
        ],
    ]])->assertSuccessful();

    $this->assertDatabaseMissing('cdrs', ['id' => $cdr->id]);
});

it('hides another tenant\'s call detail record from the deletion confirmation', function () {
    $otherTenant = Tenant::factory()->create();
    $user = grantTenantUserPermissions($this->tenant, ['call-detail-records.view', 'call-detail-records.delete']);
    $foreignCdr = Cdr::factory()->create(['tenant_id' => $otherTenant->id]);

    // The confirmation fetch is unscoped, so the handler must assert tenant
    // access before the modal can display another tenant's record.
    Livewire::actingAs($user, 'web')
        ->test(CdrList::class)
        ->call('confirmCdrDeletion', $foreignCdr->id)
        ->assertForbidden();
});
