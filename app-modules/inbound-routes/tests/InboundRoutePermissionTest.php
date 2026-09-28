<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Modules\InboundRoutes\Livewire\InboundRoutesEdit;
use Modules\InboundRoutes\Livewire\InboundRoutesList;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('allows admin with inbound-routes.view permission to view inbound routes page', function () {
    $admin = grantAdminPermissions(null, ['inbound-routes.view']);

    actingAs($admin, 'admin')
        ->get(route('panel.inbound-routes.index'))
        ->assertOk();
});

it('denies admin without inbound-routes.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.inbound-routes.index'))
        ->assertForbidden();
});

it('allows tenant user with inbound-routes.view permission to view inbound routes page', function () {
    $user = grantTenantUserPermissions($this->tenant, ['inbound-routes.view']);

    actingAs($user, 'web')
        ->get(route('panel.inbound-routes.index'))
        ->assertOk();
});

it('denies tenant user without inbound-routes.view permission', function () {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

    actingAs($user, 'web')
        ->get(route('panel.inbound-routes.index'))
        ->assertForbidden();
});

it('resolves correct Livewire action permission requirements for inbound routes', function () {
    $resolver = app(LivewireActionPermissions::class);

    // Save on Edit component resolves to edit or create
    $editAbilities = $resolver->abilitiesFor(InboundRoutesEdit::class, 'save');
    expect($editAbilities)->toEqual([['inbound-routes.edit', 'inbound-routes.create']]);

    // deleteRoute on List component resolves to delete
    $deleteAbilities = $resolver->abilitiesFor(InboundRoutesList::class, 'deleteRoute');
    expect($deleteAbilities)->toEqual([['inbound-routes.delete']]);

    // confirm action resolves to view
    $confirmAbilities = $resolver->abilitiesFor(InboundRoutesList::class, 'confirmRouteDeletion');
    expect($confirmAbilities)->toEqual([['inbound-routes.view']]);
});
