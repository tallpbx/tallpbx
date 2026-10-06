<?php

declare(strict_types=1);

use App\Jobs\ManageGateway;
use App\Jobs\ReloadSofiaProfile;
use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantServiceInterface;
use App\Support\LivewireActionPermissions;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\Gateways\Livewire\GatewaysEdit;
use Modules\Gateways\Livewire\GatewaysList;
use Modules\Gateways\Models\Gateway;

use function Pest\Laravel\actingAs;

/**
 * Read a protected or private property from a queued job via reflection.
 */
function gatewayJobProperty(object $job, string $property): mixed
{
    $reflection = new ReflectionProperty($job, $property);
    $reflection->setAccessible(true);

    return $reflection->getValue($job);
}

describe('List Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
    });

    it('renders the gateways list component', function () {
        Gateway::factory()->count(3)->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysList::class)
            ->assertOk()
            ->assertSee('Gateways')
            ->assertViewHas('gateways', function ($gateways) {
                return $gateways->count() === 3;
            });
    });

    it('displays gateway name and host', function () {
        Gateway::factory()->create([
            'name' => 'ITSP Outbound',
            'host' => 'sip.itsp.com',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysList::class)
            ->assertSee('ITSP Outbound')
            ->assertSee('sip.itsp.com');
    });

    it('deletes a gateway', function () {
        $gateway = Gateway::factory()->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysList::class)
            ->call('deleteGateway', $gateway->id)
            ->assertDispatched('gateway-deleted');

        $this->assertModelMissing($gateway);
    });

    it('opens the shared confirmation modal before deleting a gateway', function (): void {
        $gateway = Gateway::factory()->create(['name' => 'Primary gateway']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysList::class)
            ->call('confirmGatewayDeletion', $gateway->id)
            ->assertSet('pendingDeletionId', $gateway->id)
            ->assertSet('pendingDeletionName', 'Primary gateway')
            ->assertSee('Delete Gateway?');
    });

    it('shows empty state when no gateways exist', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysList::class)
            ->assertSee('No gateways found');
    });

    it('shows registration status', function () {
        Gateway::factory()->create(['name' => 'Registered GW', 'register' => true]);
        Gateway::factory()->create(['name' => 'Unregistered GW', 'register' => false]);

        $component = Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysList::class);

        $gateways = $component->viewData('gateways');
        $registered = $gateways->firstWhere('name', 'Registered GW');
        $unregistered = $gateways->firstWhere('name', 'Unregistered GW');

        expect($registered->register)->toBeTrue()
            ->and($unregistered->register)->toBeFalse();
    });
});

describe('Edit Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
        $this->tenant = Tenant::factory()->create();
    });

    it('renders the create form', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysEdit::class)
            ->assertOk()
            ->assertSee('Create Gateway')
            ->assertSet('name', '')
            ->assertSet('host', '');
    });

    it('preselects the default tenant for admin-created shared gateways', function () {
        $defaultTenant = app(TenantServiceInterface::class)->defaultTenant();

        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysEdit::class)
            ->assertOk()
            ->assertSet('tenantId', $defaultTenant->id);
    });

    it('renders the edit form with existing gateway data', function () {
        $gateway = Gateway::factory()->create([
            'name' => 'ITSP Outbound',
            'host' => 'sip.itsp.com',
            'port' => 5060,
            'username' => 'myuser',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysEdit::class, ['gatewayId' => $gateway->id])
            ->assertOk()
            ->assertSee('Edit Gateway')
            ->assertSet('name', 'ITSP Outbound')
            ->assertSet('host', 'sip.itsp.com');
    });

    it('creates a new gateway', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'New Trunk')
            ->set('host', 'sip.provider.com')
            ->set('port', '5060')
            ->set('username', 'user')
            ->set('password', 'pass')
            ->call('save')
            ->assertRedirect(route('panel.gateways.index'));

        $this->assertDatabaseHas('gateways', [
            'name' => 'New Trunk',
            'host' => 'sip.provider.com',
        ]);
    });

    it('updates an existing gateway', function () {
        $gateway = Gateway::factory()->create(['name' => 'Old']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysEdit::class, ['gatewayId' => $gateway->id])
            ->set('name', 'Updated Trunk')
            ->set('host', 'new.provider.com')
            ->call('save')
            ->assertRedirect(route('panel.gateways.index'));

        $this->assertDatabaseHas('gateways', [
            'id' => $gateway->id,
            'name' => 'Updated Trunk',
        ]);
    });

    it('validates name is required', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysEdit::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    });

    it('validates host is required', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysEdit::class)
            ->set('name', 'Test')
            ->set('host', '')
            ->call('save')
            ->assertHasErrors(['host' => 'required']);
    });

    it('starts a new enabled gateway and rescans the profile', function () {
        Queue::fake();

        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'New Trunk')
            ->set('host', 'sip.provider.com')
            ->set('profile', 'external')
            ->set('enabled', true)
            ->call('save')
            ->assertRedirect(route('panel.gateways.index'));

        Queue::assertPushed(ManageGateway::class, fn (ManageGateway $job): bool => gatewayJobProperty($job, 'action') === ManageGateway::ACTION_START
            && gatewayJobProperty($job, 'profileName') === 'external');
        Queue::assertPushed(ReloadSofiaProfile::class);
    });

    it('kills old profile gateway and starts new profile gateway when profile changes', function () {
        Queue::fake();

        $gateway = Gateway::factory()->create([
            'tenant_id' => $this->tenant->id,
            'profile' => 'external',
            'enabled' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(GatewaysEdit::class, ['gatewayId' => $gateway->id])
            ->set('profile', 'internal')
            ->call('save')
            ->assertRedirect(route('panel.gateways.index'));

        Queue::assertPushed(ManageGateway::class, fn (ManageGateway $job): bool => gatewayJobProperty($job, 'action') === ManageGateway::ACTION_KILL
            && gatewayJobProperty($job, 'profileName') === 'external');
        Queue::assertPushed(ManageGateway::class, fn (ManageGateway $job): bool => gatewayJobProperty($job, 'action') === ManageGateway::ACTION_START
            && gatewayJobProperty($job, 'profileName') === 'internal');
    });
});

describe('Permission Gates', function () {
    beforeEach(function () {
        $this->tenant = Tenant::factory()->create();
    });

    it('allows admin with gateways.view permission to view gateways page', function () {
        $admin = grantAdminPermissions(null, ['gateways.view']);

        actingAs($admin, 'admin')
            ->get(route('panel.gateways.index'))
            ->assertOk();
    });

    it('denies admin without gateways.view permission', function () {
        $admin = Admin::factory()->create(['enabled' => true]);

        actingAs($admin, 'admin')
            ->get(route('panel.gateways.index'))
            ->assertForbidden();
    });

    it('allows tenant user with gateways.view permission to view gateways page', function () {
        $user = grantTenantUserPermissions($this->tenant, ['gateways.view']);

        actingAs($user, 'web')
            ->get(route('panel.gateways.index'))
            ->assertOk();
    });

    it('denies tenant user without gateways.view permission', function () {
        $user = User::factory()->create(['enabled' => true]);
        $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

        actingAs($user, 'web')
            ->get(route('panel.gateways.index'))
            ->assertForbidden();
    });

    it('resolves correct Livewire action permission requirements for gateways', function () {
        $resolver = app(LivewireActionPermissions::class);

        // Save on Edit component resolves to edit or create
        $editAbilities = $resolver->abilitiesFor(GatewaysEdit::class, 'save');
        expect($editAbilities)->toEqual([['gateways.edit', 'gateways.create']]);

        // deleteGateway on List component resolves to delete
        $deleteAbilities = $resolver->abilitiesFor(GatewaysList::class, 'deleteGateway');
        expect($deleteAbilities)->toEqual([['gateways.delete']]);

        // confirm action resolves to view
        $confirmAbilities = $resolver->abilitiesFor(GatewaysList::class, 'confirmGatewayDeletion');
        expect($confirmAbilities)->toEqual([['gateways.view']]);
    });
});
