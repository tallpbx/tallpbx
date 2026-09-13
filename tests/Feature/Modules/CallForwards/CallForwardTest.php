<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\CallForwards\Livewire\CallForwardsEdit;
use Modules\CallForwards\Livewire\CallForwardsList;
use Modules\CallForwards\Models\CallForward;
use Modules\CallForwards\Services\CallForwardService;
use Modules\Extensions\Models\Extension;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the forward dialplan condition with the extension number', function () {
    $tenant = Tenant::factory()->create();
    $extension = Extension::withoutGlobalScope('tenant')->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '2001',
        'display_name' => 'Forward source',
        'enabled' => true,
    ]);
    CallForward::withoutGlobalScope('tenant')->create([
        'tenant_id' => $tenant->id,
        'extension_uuid' => $extension->id,
        'forward_type' => 'unconditional',
        'destination' => '2000',
        'ring_timeout' => 20,
        'enabled' => true,
    ]);

    $xml = app(CallForwardService::class)->generateDialplanXml((int) $tenant->id, 'tenant_'.$tenant->id.'_internal', '2001');

    // The condition must match the dialed extension number, not the internal
    // extension uuid — otherwise the forward rule never fires. The bridge
    // must re-enter the tenant dialplan so local and external destinations
    // both resolve (a bare number is not originatible).
    expect($xml)->not->toBeNull()
        ->and($xml)->toContain('expression="^2001$"')
        ->and($xml)->toContain('<action application="bridge" data="{dialplan=XML,context=${context}}2000"/>');
});

it('renders the call forwards list component', function () {
    CallForward::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallForwardsList::class)
        ->assertOk()
        ->assertSee('Call Forward')
        ->assertViewHas('forwards', function ($forwards) {
            return $forwards->count() === 2;
        });
});

it('displays forward type and destination', function () {
    CallForward::factory()->create([
        'forward_type' => 'unconditional',
        'destination' => '101',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallForwardsList::class)
        ->assertSee('unconditional')
        ->assertSee('101');
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallForwardsEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('forwardType', 'unconditional')
        ->assertSet('ringTimeout', 30);
});

it('creates a new call forward rule', function () {
    $tenant = Tenant::factory()->create();
    $extension = Extension::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallForwardsEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('extensionUuid', $extension->id)
        ->set('forwardType', 'unconditional')
        ->set('destination', '102')
        ->call('save')
        ->assertRedirect(route('panel.call-forwards.index'));

    $this->assertDatabaseHas('call_forwards', [
        'extension_uuid' => $extension->id,
        'forward_type' => 'unconditional',
        'destination' => '102',
    ]);
});

it('updates an existing call forward rule', function () {
    $forward = CallForward::factory()->create([
        'destination' => 'Old Destination',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallForwardsEdit::class, ['forwardId' => $forward->id])
        ->set('destination', 'New Destination')
        ->call('save')
        ->assertRedirect(route('panel.call-forwards.index'));

    $this->assertDatabaseHas('call_forwards', [
        'id' => $forward->id,
        'destination' => 'New Destination',
    ]);
});

it('deletes a call forward rule', function () {
    $forward = CallForward::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallForwardsList::class)
        ->call('deleteForward', $forward->id)
        ->assertDispatched('forward-deleted');

    $this->assertModelMissing($forward);
});

it('opens the shared confirmation modal before deleting a call forward', function (): void {
    $forward = CallForward::factory()->create(['destination' => '15551234567']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CallForwardsList::class)
        ->call('confirmForwardDeletion', $forward->id)
        ->assertSet('pendingDeletionId', $forward->id)
        ->assertSet('pendingDeletionName', '15551234567')
        ->assertSee('Delete Call Forward?');
});

it('validates destination is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallForwardsEdit::class)
        ->set('destination', '')
        ->call('save')
        ->assertHasErrors(['destination' => 'required']);
});

it('validates forward type is valid', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallForwardsEdit::class)
        ->set('forwardType', 'invalid')
        ->call('save')
        ->assertHasErrors(['forwardType' => 'in']);
});

it('shows empty state when no call forwards exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CallForwardsList::class)
        ->assertSee('No call forwards found');
});
