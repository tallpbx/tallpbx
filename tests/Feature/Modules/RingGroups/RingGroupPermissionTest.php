<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Modules\RingGroups\Livewire\RingGroupsEdit;
use Modules\RingGroups\Livewire\RingGroupsList;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('allows admin with ring-groups.view permission to view ring groups page', function () {
    $admin = grantAdminPermissions(null, ['ring-groups.view']);

    actingAs($admin, 'admin')
        ->get(route('panel.ring-groups.index'))
        ->assertOk();
});

it('denies admin without ring-groups.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.ring-groups.index'))
        ->assertForbidden();
});

it('allows tenant user with ring-groups.view permission to view ring groups page', function () {
    $user = grantTenantUserPermissions($this->tenant, ['ring-groups.view']);

    actingAs($user, 'web')
        ->get(route('panel.ring-groups.index'))
        ->assertOk();
});

it('denies tenant user without ring-groups.view permission', function () {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

    actingAs($user, 'web')
        ->get(route('panel.ring-groups.index'))
        ->assertForbidden();
});

it('resolves correct Livewire action permission requirements for ring groups', function () {
    $resolver = app(LivewireActionPermissions::class);

    // Save on Edit component resolves to edit or create
    $editAbilities = $resolver->abilitiesFor(RingGroupsEdit::class, 'save');
    expect($editAbilities)->toEqual([['ring-groups.edit', 'ring-groups.create']]);

    // deleteRingGroup on List component resolves to delete
    $deleteAbilities = $resolver->abilitiesFor(RingGroupsList::class, 'deleteRingGroup');
    expect($deleteAbilities)->toEqual([['ring-groups.delete']]);

    // confirm action resolves to view
    $confirmAbilities = $resolver->abilitiesFor(RingGroupsList::class, 'confirmRingGroupDeletion');
    expect($confirmAbilities)->toEqual([['ring-groups.view']]);
});
