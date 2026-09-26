<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Acl\Livewire\AclEdit;
use Modules\Acl\Models\AccessControl;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->tenant = Tenant::factory()->create();
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AclEdit::class)
        ->assertOk()
        ->assertSee('Create Access Control')
        ->assertSet('name', '')
        ->assertSet('action', 'allow');
});

it('renders the edit form with existing rule data', function () {
    $rule = AccessControl::factory()->create([
        'name' => 'Allow LAN',
        'action' => 'allow',
        'description' => 'LAN traffic',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AclEdit::class, ['ruleId' => $rule->id])
        ->assertOk()
        ->assertSee('Edit Access Control')
        ->assertSet('name', 'Allow LAN')
        ->assertSet('action', 'allow');
});

it('creates a new access control rule', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AclEdit::class)
        ->set('tenantId', $this->tenant->id)
        ->set('name', 'Allow Office')
        ->set('action', 'allow')
        ->set('description', 'Office IP range')
        ->call('save')
        ->assertRedirect(route('panel.acl.index'));

    $this->assertDatabaseHas('access_controls', [
        'name' => 'Allow Office',
        'action' => 'allow',
    ]);
});

it('updates an existing access control', function () {
    $rule = AccessControl::factory()->create(['name' => 'Old Rule', 'action' => 'allow']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AclEdit::class, ['ruleId' => $rule->id])
        ->set('name', 'Updated Rule')
        ->set('action', 'deny')
        ->call('save')
        ->assertRedirect(route('panel.acl.index'));

    $this->assertDatabaseHas('access_controls', [
        'id' => $rule->id,
        'name' => 'Updated Rule',
        'action' => 'deny',
    ]);
});

it('validates name is required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AclEdit::class)
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('validates action is allow or deny', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AclEdit::class)
        ->set('name', 'Test')
        ->set('action', 'invalid')
        ->call('save')
        ->assertHasErrors(['action']);
});
