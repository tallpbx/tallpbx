<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Dialplans\Livewire\DialplansEdit;
use Modules\Dialplans\Models\Dialplan;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansEdit::class)
        ->assertOk()
        ->assertSee('Create Dialplan')
        ->assertSet('name', '')
        ->assertSet('context', '');
});

it('renders the edit form with existing data', function () {
    $dialplan = Dialplan::factory()->create([
        'name' => 'Default',
        'context' => 'default',
        'description' => 'Main routing dialplan',
        'order' => 100,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansEdit::class, ['dialplanId' => $dialplan->id])
        ->assertOk()
        ->assertSee('Edit Dialplan')
        ->assertSet('name', 'Default')
        ->assertSet('context', 'default');
});

it('creates a new dialplan', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Inbound')
        ->set('context', 'public')
        ->set('description', 'Inbound routing')
        ->set('order', '50')
        ->call('save')
        ->assertRedirect(route('panel.dialplans.index'));

    $this->assertDatabaseHas('dialplans', [
        'name' => 'Inbound',
        'context' => 'public',
    ]);
});

it('updates an existing dialplan', function () {
    $dialplan = Dialplan::factory()->create(['name' => 'Old']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansEdit::class, ['dialplanId' => $dialplan->id])
        ->set('name', 'Updated Dialplan')
        ->set('context', 'new_context')
        ->call('save')
        ->assertRedirect(route('panel.dialplans.index'));

    $this->assertDatabaseHas('dialplans', [
        'id' => $dialplan->id,
        'name' => 'Updated Dialplan',
        'context' => 'new_context',
    ]);
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates context is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(DialplansEdit::class)
        ->set('name', 'Test')
        ->set('context', '')
        ->call('save')
        ->assertHasErrors(['context' => 'required']);
});
