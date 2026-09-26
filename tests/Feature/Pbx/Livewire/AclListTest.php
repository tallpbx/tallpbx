<?php

declare(strict_types=1);

use App\Models\Admin;
use Livewire\Livewire;
use Modules\Acl\Livewire\AclList;
use Modules\Acl\Models\AccessControl;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
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
    Livewire::actingAs($this->admin, 'admin')->test(AclList::class)->call('confirmRuleDeletion', $rule->id)->assertSet('pendingDeletionId', $rule->id)->assertSet('pendingDeletionName', 'Allow LAN')->assertSee('Delete Access Control Rule?');
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
