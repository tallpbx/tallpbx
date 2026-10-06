<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use App\Support\LivewireActionPermissions;
use Livewire\Livewire;
use Modules\InboundRoutes\Livewire\InboundRoutesEdit;
use Modules\InboundRoutes\Livewire\InboundRoutesList;
use Modules\InboundRoutes\Models\InboundRoute;

use function Pest\Laravel\actingAs;

describe('List Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
        $this->tenant = Tenant::factory()->create();
    });

    it('renders the inbound routes list component', function () {
        InboundRoute::factory()->count(2)->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesList::class)
            ->assertOk()
            ->assertSee('Inbound Routes')
            ->assertViewHas('routes', function ($routes) {
                return $routes->count() === 2;
            });
    });

    it('displays route name and destination number', function () {
        InboundRoute::factory()->create([
            'name' => 'Main DID',
            'destination_number' => '+14155551212',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesList::class)
            ->assertSee('Main DID')
            ->assertSee('+14155551212');
    });

    it('deletes an inbound route', function () {
        $route = InboundRoute::factory()->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesList::class)
            ->call('deleteRoute', $route->id)
            ->assertDispatched('route-deleted');

        $this->assertModelMissing($route);
    });

    it('opens the shared confirmation modal before deleting an inbound route', function (): void {
        $route = InboundRoute::factory()->create(['name' => 'Main number']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesList::class)
            ->call('confirmRouteDeletion', $route->id)
            ->assertSet('pendingDeletionId', $route->id)
            ->assertSet('pendingDeletionName', 'Main number')
            ->assertSee('Delete Inbound Route?');
    });

    it('shows empty state when no routes exist', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesList::class)
            ->assertSee(__('admin.no_inbound_routes_found'));
    });

    it('admin sees routes from all tenants', function () {
        $otherTenant = Tenant::factory()->create();
        InboundRoute::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tenant A Route',
        ]);
        InboundRoute::factory()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Tenant B Route',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesList::class)
            ->assertSee('Tenant A Route')
            ->assertSee('Tenant B Route');
    });

    it('tenant user sees only their own routes', function () {
        $otherTenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $this->tenant->users()->attach($user, ['role' => 'admin']);

        app(TenantManager::class)->setTenantId((string) $this->tenant->id);

        InboundRoute::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'My Route',
        ]);
        InboundRoute::factory()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Route',
        ]);

        Livewire::actingAs($user, 'web')
            ->test(InboundRoutesList::class)
            ->assertSee('My Route')
            ->assertDontSee('Other Route');

        app(TenantManager::class)->setTenantId(null);
    });

    it('tenant user cannot delete routes', function () {
        $user = User::factory()->create();
        $this->tenant->users()->attach($user, ['role' => 'admin']);

        app(TenantManager::class)->setTenantId((string) $this->tenant->id);

        $route = InboundRoute::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Livewire::actingAs($user, 'web')
            ->test(InboundRoutesList::class)
            ->call('deleteRoute', $route->id)
            ->assertForbidden();

        $this->assertModelExists($route);

        app(TenantManager::class)->setTenantId(null);
    });
});

describe('Edit Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
        $this->tenant = Tenant::factory()->create();
    });

    it('renders the create form', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesEdit::class)
            ->assertOk()
            ->assertSee('Create Inbound Route')
            ->assertSet('name', '')
            ->assertSet('priority', 100)
            ->assertSet('enabled', true);
    });

    it('renders the edit form with existing route data', function () {
        $route = InboundRoute::factory()->create([
            'name' => 'Main DID',
            'destination_number' => '+14155551212',
            'action' => 'transfer',
            'action_data' => '1000',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesEdit::class, ['routeId' => $route->id])
            ->assertOk()
            ->assertSee('Edit Inbound Route')
            ->assertSet('name', 'Main DID')
            ->assertSet('destinationNumber', '+14155551212')
            ->assertSet('action', 'transfer');
    });

    it('creates a new inbound route', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'Support Line')
            ->set('destinationNumber', '+14155551234')
            ->set('action', 'transfer')
            ->set('actionData', '2000')
            ->call('save')
            ->assertRedirect(route('panel.inbound-routes.index'));

        $this->assertDatabaseHas('inbound_routes', [
            'name' => 'Support Line',
            'destination_number' => '+14155551234',
        ]);
    });

    it('updates an existing inbound route', function () {
        $route = InboundRoute::factory()->create(['name' => 'Old Route', 'priority' => 100]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesEdit::class, ['routeId' => $route->id])
            ->set('name', 'Updated Route')
            ->set('priority', 50)
            ->call('save')
            ->assertRedirect(route('panel.inbound-routes.index'));

        $this->assertDatabaseHas('inbound_routes', [
            'id' => $route->id,
            'name' => 'Updated Route',
            'priority' => 50,
        ]);
    });

    it('validates name is required', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesEdit::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    });

    it('validates destination number is required', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(InboundRoutesEdit::class)
            ->set('destinationNumber', '')
            ->call('save')
            ->assertHasErrors(['destinationNumber' => 'required']);
    });
});

describe('Permission Gates', function () {
    beforeEach(function () {
        $this->tenant = Tenant::factory()->create();
    });

    it('allows admin with inbound-routes.view permission to view inbound routes page', function () {
        $admin = grantAdminPermissions(null, ['inbound-routes.view']);

        actingAs($admin, 'admin')
            ->get(route('panel.inbound-routes.index'))
            ->assertOk();
    });

    it('denies admin without inbound-routes.view permission', function () {
        $admin = Admin::factory()->create(['enabled' => true]);

        actingAs($admin, 'admin')
            ->get(route('panel.inbound-routes.index'))
            ->assertForbidden();
    });

    it('allows tenant user with inbound-routes.view permission to view inbound routes page', function () {
        $user = grantTenantUserPermissions($this->tenant, ['inbound-routes.view']);

        actingAs($user, 'web')
            ->get(route('panel.inbound-routes.index'))
            ->assertOk();
    });

    it('denies tenant user without inbound-routes.view permission', function () {
        $user = User::factory()->create(['enabled' => true]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

        actingAs($user, 'web')
            ->get(route('panel.inbound-routes.index'))
            ->assertForbidden();
    });

    it('resolves correct Livewire action permission requirements for inbound routes', function () {
        $resolver = app(LivewireActionPermissions::class);

        // Save on Edit component resolves to edit or create
        $editAbilities = $resolver->abilitiesFor(InboundRoutesEdit::class, 'save');
        expect($editAbilities)->toEqual([['inbound-routes.edit', 'inbound-routes.create']]);

        // deleteRoute on List component resolves to delete
        $deleteAbilities = $resolver->abilitiesFor(InboundRoutesList::class, 'deleteRoute');
        expect($deleteAbilities)->toEqual([['inbound-routes.delete']]);

        // confirm action resolves to view
        $confirmAbilities = $resolver->abilitiesFor(InboundRoutesList::class, 'confirmRouteDeletion');
        expect($confirmAbilities)->toEqual([['inbound-routes.view']]);
    });
});
