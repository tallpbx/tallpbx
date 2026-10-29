<?php

declare(strict_types=1);

use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Acl\Livewire\AclEdit;
use Modules\Acl\Livewire\AclList;
use Modules\Acl\Models\AccessControl;

beforeEach(function () {
    $this->admin = grantAdminPermissions(permissions: ['acl.view', 'acl.create', 'acl.edit', 'acl.delete']);
    $this->tenant = Tenant::factory()->create();
});

it('renders the access controls list component', function () {
    AccessControl::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(AclList::class)
        ->assertOk()
        ->assertSee('Access Control Lists')
        ->assertViewHas('rules', function ($rules) {
            return $rules->count() === 2;
        });
});

it('displays rule name and action', function () {
    AccessControl::factory()->create([
        'name' => 'Allow LAN',
        'action' => 'allow',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(AclList::class)
        ->assertSee('Allow LAN')
        ->assertSee('Allow');
});

it('deletes an access control', function () {
    $rule = AccessControl::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(AclList::class)
        ->call('deleteRule', $rule->id)
        ->assertDispatched('rule-deleted');

    $this->assertModelMissing($rule);
});

it('opens the shared confirmation modal before deleting an access control rule', function (): void {
    $rule = AccessControl::factory()->create(['name' => 'Allow LAN']);
    Livewire::actingAs($this->admin, 'admin')
        ->test(AclList::class)
        ->call('confirmRuleDeletion', $rule->id)
        ->assertSet('pendingDeletionId', $rule->id)
        ->assertSet('pendingDeletionName', 'Allow LAN')
        ->assertSee('Delete Access Control Rule?');
});

it('shows empty state when no rules exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AclList::class)
        ->assertSee('No access control lists found');
});

it('displays the access control list tooltip differentiating from firewall settings', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(AclList::class)
        ->assertSee(__('admin.acl_tooltip'));
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
