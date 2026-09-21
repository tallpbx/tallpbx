<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Modules\OutboundRoutes\Livewire\OutboundRoutesEdit;
use Modules\OutboundRoutes\Livewire\OutboundRoutesList;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('allows admin with outbound-routes.view permission to view outbound routes page', function () {
    $admin = grantAdminPermissions(null, ['outbound-routes.view']);

    actingAs($admin, 'admin')
        ->get(route('panel.outbound-routes.index'))
        ->assertOk();
});

it('denies admin without outbound-routes.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.outbound-routes.index'))
        ->assertForbidden();
});

it('allows tenant user with outbound-routes.view permission to view outbound routes page', function () {
    $user = grantTenantUserPermissions($this->tenant, ['outbound-routes.view']);

    actingAs($user, 'web')
        ->get(route('panel.outbound-routes.index'))
        ->assertOk();
});

it('denies tenant user without outbound-routes.view permission', function () {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

    actingAs($user, 'web')
        ->get(route('panel.outbound-routes.index'))
        ->assertForbidden();
});

it('resolves correct Livewire action permission requirements for outbound routes', function () {
    $resolver = app(LivewireActionPermissions::class);

    // Save on Edit component resolves to edit or create
    $editAbilities = $resolver->abilitiesFor(OutboundRoutesEdit::class, 'save');
    expect($editAbilities)->toEqual([['outbound-routes.edit', 'outbound-routes.create']]);

    // deleteRoute on List component resolves to delete
    $deleteAbilities = $resolver->abilitiesFor(OutboundRoutesList::class, 'deleteRoute');
    expect($deleteAbilities)->toEqual([['outbound-routes.delete']]);

    // confirm action resolves to view
    $confirmAbilities = $resolver->abilitiesFor(OutboundRoutesList::class, 'confirmRouteDeletion');
    expect($confirmAbilities)->toEqual([['outbound-routes.view']]);
});
