<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\PinNumbers\Livewire\PinNumbersEdit;
use Modules\PinNumbers\Livewire\PinNumbersList;
use Modules\PinNumbers\Models\PinNumber;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the pin numbers list component', function () {
    PinNumber::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(PinNumbersList::class)
        ->assertOk()
        ->assertSee('PIN Numbers')
        ->assertViewHas('pinNumbers', function ($pinNumbers) {
            return $pinNumbers->count() === 2;
        });
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(PinNumbersEdit::class)
        ->assertOk()
        ->assertSee('Create')
        ->assertSet('pinNumber', '')
        ->assertSet('enabled', true);
});

it('creates a new pin number', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(PinNumbersEdit::class)
        ->set('tenantId', $tenant->id)
        ->set('pinNumber', '1234')
        ->call('save')
        ->assertRedirect(route('panel.pin-numbers.index'));

    $this->assertDatabaseHas('pin_numbers', [
        'pin_number' => '1234',
    ]);
});

it('updates an existing pin number', function () {
    $pinNumber = PinNumber::factory()->create(['pin_number' => '0000']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(PinNumbersEdit::class, ['pinId' => $pinNumber->id])
        ->set('pinNumber', '5678')
        ->call('save')
        ->assertRedirect(route('panel.pin-numbers.index'));

    $this->assertDatabaseHas('pin_numbers', [
        'id' => $pinNumber->id,
        'pin_number' => '5678',
    ]);
});

it('deletes a pin number', function () {
    $pinNumber = PinNumber::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(PinNumbersList::class)
        ->call('deletePinNumber', $pinNumber->id)
        ->assertDispatched('pin-number-deleted');

    $this->assertModelMissing($pinNumber);
});

it('opens the shared confirmation modal before deleting a PIN number', function (): void {
    $pin = PinNumber::factory()->create(['pin_number' => '1234']);
    Livewire::actingAs($this->admin, 'admin')->test(PinNumbersList::class)->call('confirmPinNumberDeletion', $pin->id)->assertSet('pendingDeletionId', $pin->id)->assertSet('pendingDeletionName', '1234')->assertSee('Delete PIN Number?');
});

it('validates pin number is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(PinNumbersEdit::class)
        ->set('pinNumber', '')
        ->call('save')
        ->assertHasErrors(['pinNumber' => 'required']);
});

it('shows empty state when no pin numbers exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(PinNumbersList::class)
        ->assertSee('No PIN numbers found.');
});
