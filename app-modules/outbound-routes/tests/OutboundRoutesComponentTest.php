<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use App\Support\LivewireActionPermissions;
use Livewire\Livewire;
use Modules\Gateways\Models\Gateway;
use Modules\OutboundRoutes\Livewire\OutboundRoutesEdit;
use Modules\OutboundRoutes\Livewire\OutboundRoutesList;
use Modules\OutboundRoutes\Models\OutboundRoute;

use function Pest\Laravel\actingAs;

describe('List Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
        $this->tenant = Tenant::factory()->create();
    });

    it('renders the outbound routes list component', function () {
        OutboundRoute::factory()->count(2)->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesList::class)
            ->assertOk()
            ->assertSee('Outbound Routes')
            ->assertViewHas('routes', function ($routes) {
                return $routes->count() === 2;
            });
    });

    it('displays route name and dial pattern', function () {
        OutboundRoute::factory()->create([
            'name' => 'US Long Distance',
            'dial_pattern' => '^(\\d{10})$',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesList::class)
            ->assertSee('US Long Distance')
            ->assertSee('^(\\d{10})$');
    });

    it('deletes an outbound route', function () {
        $route = OutboundRoute::factory()->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesList::class)
            ->call('deleteRoute', $route->id)
            ->assertDispatched('route-deleted');

        $this->assertModelMissing($route);
    });

    it('opens the shared confirmation modal before deleting an outbound route', function (): void {
        $route = OutboundRoute::factory()->create(['name' => 'Long distance']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesList::class)
            ->call('confirmRouteDeletion', $route->id)
            ->assertSet('pendingDeletionId', $route->id)
            ->assertSet('pendingDeletionName', 'Long distance')
            ->assertSee('Delete Outbound Route?');
    });

    it('shows empty state when no routes exist', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesList::class)
            ->assertSee(__('admin.no_outbound_routes_found'));
    });

    it('admin sees routes from all tenants', function () {
        $otherTenant = Tenant::factory()->create();
        OutboundRoute::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Tenant A Route',
        ]);
        OutboundRoute::factory()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Tenant B Route',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesList::class)
            ->assertSee('Tenant A Route')
            ->assertSee('Tenant B Route');
    });

    it('tenant user sees only their own routes', function () {
        $otherTenant = Tenant::factory()->create();
        $user = User::factory()->create();
        $this->tenant->users()->attach($user, ['role' => 'admin']);

        app(TenantManager::class)->setTenantId((string) $this->tenant->id);

        OutboundRoute::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'My Route',
        ]);
        OutboundRoute::factory()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Route',
        ]);

        Livewire::actingAs($user, 'web')
            ->test(OutboundRoutesList::class)
            ->assertSee('My Route')
            ->assertDontSee('Other Route');

        app(TenantManager::class)->setTenantId(null);
    });

    it('tenant user cannot delete routes', function () {
        $user = User::factory()->create();
        $this->tenant->users()->attach($user, ['role' => 'admin']);

        app(TenantManager::class)->setTenantId((string) $this->tenant->id);

        $route = OutboundRoute::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        Livewire::actingAs($user, 'web')
            ->test(OutboundRoutesList::class)
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
            ->test(OutboundRoutesEdit::class)
            ->assertOk()
            ->assertSee('Create Outbound Route')
            ->assertSet('name', '')
            ->assertSet('priority', 100)
            ->assertSet('enabled', true);
    });

    it('renders the edit form with existing route data', function () {
        $route = OutboundRoute::factory()->create([
            'name' => 'US Long Distance',
            'dial_pattern' => '^(\\d{10})$',
            'gateway' => 'sip-provider',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesEdit::class, ['routeId' => $route->id])
            ->assertOk()
            ->assertSee('Edit Outbound Route')
            ->assertSet('name', 'US Long Distance')
            ->assertSet('dialPattern', '^(\\d{10})$')
            ->assertSet('gateway', 'sip-provider');
    });

    it('creates a new outbound route', function () {
        $gateway = Gateway::factory()->create([
            'tenant_id' => $this->tenant->id,
            'enabled' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'International')
            ->set('dialPattern', '^(011\\d+)$')
            ->set('gatewayId', $gateway->id)
            ->call('save')
            ->assertRedirect(route('panel.outbound-routes.index'));

        $this->assertDatabaseHas('outbound_routes', [
            'name' => 'International',
            'dial_pattern' => '^(011\\d+)$',
            'gateway_id' => $gateway->id,
        ]);
    });

    it('updates an existing outbound route', function () {
        $route = OutboundRoute::factory()->create(['name' => 'Old Route', 'priority' => 100]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesEdit::class, ['routeId' => $route->id])
            ->set('name', 'Updated Route')
            ->set('priority', 50)
            ->call('save')
            ->assertRedirect(route('panel.outbound-routes.index'));

        $this->assertDatabaseHas('outbound_routes', [
            'id' => $route->id,
            'name' => 'Updated Route',
            'priority' => 50,
        ]);
    });

    it('validates name is required', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesEdit::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    });

    it('validates dial pattern is required', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesEdit::class)
            ->set('dialPattern', '')
            ->call('save')
            ->assertHasErrors(['dialPattern' => 'required']);
    });

    it('validates dial pattern has a capturing group', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'No Capture')
            ->set('dialPattern', '^011\\d+$')
            ->call('save')
            ->assertHasErrors(['dialPattern']);
    });

    it('rejects gateways from another tenant', function () {
        $otherTenant = Tenant::factory()->create();
        $gateway = Gateway::factory()->create([
            'tenant_id' => $otherTenant->id,
            'enabled' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'Cross Tenant Gateway')
            ->set('dialPattern', '^(\\d{10})$')
            ->set('gatewayId', $gateway->id)
            ->call('save')
            ->assertHasErrors(['gatewayId']);
    });

    it('rejects disabled gateways', function () {
        $gateway = Gateway::factory()->create([
            'tenant_id' => $this->tenant->id,
            'enabled' => false,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(OutboundRoutesEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'Disabled Gateway')
            ->set('dialPattern', '^(\\d{10})$')
            ->set('gatewayId', $gateway->id)
            ->call('save')
            ->assertHasErrors(['gatewayId']);
    });
});

describe('Permission Gates', function () {
    beforeEach(function () {
        $this->tenant = Tenant::factory()->create();
    });

    it('allows admin with outbound-routes.view permission to view outbound routes page', function () {
        $admin = grantAdminPermissions(null, ['outbound-routes.view']);

        actingAs($admin, 'admin')
            ->get(route('panel.outbound-routes.index'))
            ->assertOk();
    });

    it('denies admin without outbound-routes.view permission', function () {
        $admin = Admin::factory()->create(['enabled' => true]);

        actingAs($admin, 'admin')
            ->get(route('panel.outbound-routes.index'))
            ->assertForbidden();
    });

    it('allows tenant user with outbound-routes.view permission to view outbound routes page', function () {
        $user = grantTenantUserPermissions($this->tenant, ['outbound-routes.view']);

        actingAs($user, 'web')
            ->get(route('panel.outbound-routes.index'))
            ->assertOk();
    });

    it('denies tenant user without outbound-routes.view permission', function () {
        $user = User::factory()->create(['enabled' => true]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

        actingAs($user, 'web')
            ->get(route('panel.outbound-routes.index'))
            ->assertForbidden();
    });

    it('resolves correct Livewire action permission requirements for outbound routes', function () {
        $resolver = app(LivewireActionPermissions::class);

        // Save on Edit component resolves to edit or create
        $editAbilities = $resolver->abilitiesFor(OutboundRoutesEdit::class, 'save');
        expect($editAbilities)->toEqual([['outbound-routes.edit', 'outbound-routes.create']]);

        // deleteRoute on List component resolves to delete
        $deleteAbilities = $resolver->abilitiesFor(OutboundRoutesList::class, 'deleteRoute');
        expect($deleteAbilities)->toEqual([['outbound-routes.delete']]);

        // confirm action resolves to view
        $confirmAbilities = $resolver->abilitiesFor(OutboundRoutesList::class, 'confirmRouteDeletion');
        expect($confirmAbilities)->toEqual([['outbound-routes.view']]);
    });
});
