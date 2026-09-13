<?php

declare(strict_types=1);

use App\Jobs\ManageGateway;
use App\Jobs\ReloadSofiaProfile;
use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantServiceInterface;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Modules\Gateways\Livewire\GatewaysEdit;
use Modules\Gateways\Models\Gateway;

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

function gatewayJobProperty(object $job, string $property): mixed
{
    $reflection = new ReflectionProperty($job, $property);
    $reflection->setAccessible(true);

    return $reflection->getValue($job);
}
