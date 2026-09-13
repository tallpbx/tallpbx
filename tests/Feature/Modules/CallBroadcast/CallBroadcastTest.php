<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\CallBroadcast\Livewire\BroadcastCreate;
use Modules\CallBroadcast\Livewire\BroadcastList;
use Modules\CallBroadcast\Models\CallBroadcast;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the broadcast list component', function () {
    CallBroadcast::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastList::class)
        ->assertOk()
        ->assertSee('Call Broadcasts')
        ->assertViewHas('broadcasts', function ($broadcasts) {
            return $broadcasts->count() === 2;
        });
});

it('renders the create broadcast form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastCreate::class)
        ->assertOk()
        ->assertSee('Create');
});

it('creates a broadcast with parsed recipients from the phone numbers textarea', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastCreate::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Emergency Alert')
        ->set('phoneNumbers', "+15551234567\n+15559876543\n\n +15551234567 \n")
        ->call('save')
        ->assertHasNoErrors();

    $broadcast = CallBroadcast::withoutGlobalScope('tenant')->where('name', 'Emergency Alert')->firstOrFail();

    expect($broadcast->status)->toBe('draft')
        ->and($broadcast->recipients()->pluck('phone_number')->all())
        ->toBe(['+15551234567', '+15559876543']); // whitespace trimmed, duplicates dropped
});

it('rejects an empty phone numbers textarea', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastCreate::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Empty')
        ->set('phoneNumbers', "\n  \n")
        ->call('save')
        ->assertHasErrors('phoneNumbers');

    expect(CallBroadcast::withoutGlobalScope('tenant')->where('name', 'Empty')->exists())->toBeFalse();
});

it('rejects invalid phone numbers without creating anything', function (string $badNumber) {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastCreate::class)
        ->set('tenantId', $tenant->id)
        ->set('name', 'Broken')
        ->set('phoneNumbers', "+15551234567\n{$badNumber}")
        ->call('save')
        ->assertHasErrors('phoneNumbers');

    expect(CallBroadcast::withoutGlobalScope('tenant')->where('name', 'Broken')->exists())->toBeFalse();
})->with([
    'letters' => 'abc123',
    'too short' => '12345',
    'too long' => '+155512345678901234',
    'dial string chars' => '1000@x;y',
    'spaces inside' => '+1 5551234567',
]);

it('deletes a broadcast', function () {
    $broadcast = CallBroadcast::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastList::class)
        ->call('confirmBroadcastDeletion', $broadcast->id)
        ->assertSet('pendingDeletionId', $broadcast->id)
        ->call('deleteBroadcast')
        ->assertSet('operationalMessage', 'Call broadcast deleted.')
        ->assertDispatched('broadcast-deleted');

    $this->assertModelMissing($broadcast);
});

it('opens the shared confirmation modal before deleting a broadcast', function (): void {
    $broadcast = CallBroadcast::factory()->create(['name' => 'Staff alert']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastList::class)
        ->call('confirmBroadcastDeletion', $broadcast->id)
        ->assertSet('pendingDeletionId', $broadcast->id)
        ->assertSet('pendingDeletionName', 'Staff alert')
        ->assertSee('Delete Call Broadcast?');
});

it('shows empty state when no broadcasts exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(BroadcastList::class)
        ->assertSee('No call broadcasts found');
});
