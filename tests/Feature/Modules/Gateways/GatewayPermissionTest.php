<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Modules\Gateways\Livewire\GatewaysEdit;
use Modules\Gateways\Livewire\GatewaysList;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('allows admin with gateways.view permission to view gateways page', function () {
    $admin = grantAdminPermissions(null, ['gateways.view']);

    actingAs($admin, 'admin')
        ->get(route('panel.gateways.index'))
        ->assertOk();
});

it('denies admin without gateways.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.gateways.index'))
        ->assertForbidden();
});

it('allows tenant user with gateways.view permission to view gateways page', function () {
    $user = grantTenantUserPermissions($this->tenant, ['gateways.view']);

    actingAs($user, 'web')
        ->get(route('panel.gateways.index'))
        ->assertOk();
});

it('denies tenant user without gateways.view permission', function () {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

    actingAs($user, 'web')
        ->get(route('panel.gateways.index'))
        ->assertForbidden();
});

it('resolves correct Livewire action permission requirements for gateways', function () {
    $resolver = app(LivewireActionPermissions::class);

    // Save on Edit component resolves to edit or create
    $editAbilities = $resolver->abilitiesFor(GatewaysEdit::class, 'save');
    expect($editAbilities)->toEqual([['gateways.edit', 'gateways.create']]);

    // deleteGateway on List component resolves to delete
    $deleteAbilities = $resolver->abilitiesFor(GatewaysList::class, 'deleteGateway');
    expect($deleteAbilities)->toEqual([['gateways.delete']]);

    // confirm action resolves to view
    $confirmAbilities = $resolver->abilitiesFor(GatewaysList::class, 'confirmGatewayDeletion');
    expect($confirmAbilities)->toEqual([['gateways.view']]);
});
