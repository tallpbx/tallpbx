<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\CallFlows\Livewire\CallFlowsEdit;
use Modules\CallFlows\Livewire\CallFlowsList;
use Modules\CallFlows\Models\CallFlow;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the call flows list component', function () {
    CallFlow::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallFlowsList::class)
        ->assertOk()
        ->assertSee('Call Flows')
        ->assertViewHas('callFlows', function ($flows) {
            return $flows->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallFlowsEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('enabled', true);
});

it('creates a new call flow', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallFlowsEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Sales DID')
        ->set('extension', '15551234567')
        ->set('destinationType', 'ring_group')
        ->set('destinationId', 'some-uuid')
        ->call('save')
        ->assertRedirect(route('panel.call-flows.index'));

    $this->assertDatabaseHas('call_flows', [
        'name' => 'Sales DID',
        'extension' => '15551234567',
        'destination_type' => 'ring_group',
        'destination_id' => 'some-uuid',
    ]);
});

it('updates an existing call flow', function () {
    $flow = CallFlow::factory()->create(['name' => 'Old Flow']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallFlowsEdit::class, ['callFlowId' => $flow->id])
        ->set('name', 'Updated Flow')
        ->set('extension', '15559876543')
        ->call('save')
        ->assertRedirect(route('panel.call-flows.index'));

    $this->assertDatabaseHas('call_flows', [
        'id' => $flow->id,
        'name' => 'Updated Flow',
        'extension' => '15559876543',
    ]);
});

it('deletes a call flow', function () {
    $flow = CallFlow::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallFlowsList::class)
        ->call('deleteCallFlow', $flow->id)
        ->assertDispatched('call-flow-deleted');

    $this->assertModelMissing($flow);
});

it('opens the shared confirmation modal before deleting a call flow', function (): void {
    $flow = CallFlow::factory()->create(['name' => 'After-hours flow']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallFlowsList::class)
        ->call('confirmCallFlowDeletion', $flow->id)
        ->assertSet('pendingDeletionId', $flow->id)
        ->assertSet('pendingDeletionName', 'After-hours flow')
        ->assertSee('Delete Call Flow?');
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallFlowsEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('shows empty state when no call flows exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallFlowsList::class)
        ->assertSee('No call flows found');
});
