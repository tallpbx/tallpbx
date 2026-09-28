<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Modules\Dialplans\Livewire\DialplansEdit;
use Modules\Dialplans\Livewire\DialplansList;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('allows admin with dialplans.view permission to view dialplans page', function () {
    $admin = grantAdminPermissions(null, ['dialplans.view']);

    actingAs($admin, 'admin')
        ->get(route('panel.dialplans.index'))
        ->assertOk();
});

it('denies admin without dialplans.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.dialplans.index'))
        ->assertForbidden();
});

it('allows tenant user with dialplans.view permission to view dialplans page', function () {
    $user = grantTenantUserPermissions($this->tenant, ['dialplans.view']);

    actingAs($user, 'web')
        ->get(route('panel.dialplans.index'))
        ->assertOk();
});

it('denies tenant user without dialplans.view permission', function () {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

    actingAs($user, 'web')
        ->get(route('panel.dialplans.index'))
        ->assertForbidden();
});

it('resolves correct Livewire action permission requirements for dialplans', function () {
    $resolver = app(LivewireActionPermissions::class);

    // Save on Edit component resolves to edit or create
    $editAbilities = $resolver->abilitiesFor(DialplansEdit::class, 'save');
    expect($editAbilities)->toEqual([['dialplans.edit', 'dialplans.create']]);

    // deleteDialplan on List component resolves to delete
    $deleteAbilities = $resolver->abilitiesFor(DialplansList::class, 'deleteDialplan');
    expect($deleteAbilities)->toEqual([['dialplans.delete']]);

    // confirm action resolves to view
    $confirmAbilities = $resolver->abilitiesFor(DialplansList::class, 'confirmDialplanDeletion');
    expect($confirmAbilities)->toEqual([['dialplans.view']]);
});
