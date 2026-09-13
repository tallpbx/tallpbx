<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\SipTrunks\Livewire\SipTrunksEdit;
use Modules\SipTrunks\Livewire\SipTrunksList;
use Modules\SipTrunks\Models\SipTrunk;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('renders the list component', function () {
    SipTrunk::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipTrunksList::class)
        ->assertOk()
        ->assertSee('SIP Trunks')
        ->assertViewHas('trunks', fn ($items) => $items->count() === 2);
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipTrunksEdit::class)
        ->assertOk()
        ->assertSee('Create');
});

it('renders the edit form', function () {
    $trunk = SipTrunk::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipTrunksEdit::class, ['trunkId' => $trunk->id])
        ->assertOk()
        ->assertSee('Edit');
});

it('creates a SIP trunk', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipTrunksEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'My SIP Trunk')
        ->set('host', 'sip.example.com')
        ->set('port', 5060)
        ->set('username', 'user123')
        ->set('password', 'secret')
        ->set('codecs', 'PCMU,PCMA')
        ->call('save')
        ->assertRedirect(route('panel.sip-trunks.index'));

    expect(SipTrunk::count())->toBe(1);
});

it('validates required fields', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipTrunksEdit::class)
        ->call('save')
        ->assertHasErrors(['tenantId', 'name', 'host']);
});

it('deletes a SIP trunk', function () {
    $trunk = SipTrunk::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipTrunksList::class)
        ->call('deleteTrunk', $trunk->id)
        ->assertOk();

    expect(SipTrunk::count())->toBe(0);
});

it('opens the shared confirmation modal before deleting a SIP trunk', function (): void {
    $trunk = SipTrunk::factory()->create(['name' => 'Primary carrier']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SipTrunksList::class)
        ->call('confirmTrunkDeletion', $trunk->id)
        ->assertSet('pendingDeletionId', $trunk->id)
        ->assertSet('pendingDeletionName', 'Primary carrier')
        ->assertSee('Delete SIP Trunk?');
});

it('shows empty state when no trunks exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SipTrunksList::class)
        ->assertSee('No trunks found');
});
