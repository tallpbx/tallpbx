<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Emergency\Livewire\EmergencyEdit;
use Modules\Emergency\Livewire\EmergencyList;
use Modules\Emergency\Models\Emergency;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('renders the list component', function () {
    Emergency::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmergencyList::class)
        ->assertOk()
        ->assertSee('Emergency')
        ->assertViewHas('records', fn ($items) => $items->count() === 2);
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EmergencyEdit::class)
        ->assertOk()
        ->assertSee('Create');
});

it('renders the edit form', function () {
    $record = Emergency::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmergencyEdit::class, ['emergencyId' => $record->id])
        ->assertOk()
        ->assertSee('Edit');
});

it('creates an emergency record', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EmergencyEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('callerId', '+15551234567')
        ->set('address', '123 Main St, Springfield')
        ->set('latitude', '39.7817')
        ->set('longitude', '-89.6501')
        ->call('save')
        ->assertRedirect(route('panel.emergency.index'));

    expect(Emergency::count())->toBe(1);
});

it('validates required fields', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EmergencyEdit::class)
        ->call('save')
        ->assertHasErrors(['tenantId', 'address']);
});

it('deletes an emergency record', function () {
    $record = Emergency::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmergencyList::class)
        ->call('deleteRecord', $record->id)
        ->assertOk();

    expect(Emergency::count())->toBe(0);
});

it('opens the shared confirmation modal before deleting an emergency record', function (): void {
    $record = Emergency::factory()->create(['caller_id' => '+15551234567']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(EmergencyList::class)
        ->call('confirmRecordDeletion', $record->id)
        ->assertSet('pendingDeletionId', $record->id)
        ->assertSet('pendingDeletionName', '+15551234567')
        ->assertSee('Delete Emergency Record?');
});

it('shows empty state when no records exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(EmergencyList::class)
        ->assertSee('No emergency records found');
});
