<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Modules\SipTrunks\Livewire\SipTrunksEdit;
use Modules\SipTrunks\Livewire\SipTrunksList;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('allows admin with sip-trunks.view permission to view sip trunks page', function () {
    $admin = grantAdminPermissions(null, ['sip-trunks.view']);

    actingAs($admin, 'admin')
        ->get(route('panel.sip-trunks.index'))
        ->assertOk();
});

it('denies admin without sip-trunks.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.sip-trunks.index'))
        ->assertForbidden();
});

it('allows tenant user with sip-trunks.view permission to view sip trunks page', function () {
    $user = grantTenantUserPermissions($this->tenant, ['sip-trunks.view']);

    actingAs($user, 'web')
        ->get(route('panel.sip-trunks.index'))
        ->assertOk();
});

it('denies tenant user without sip-trunks.view permission', function () {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

    actingAs($user, 'web')
        ->get(route('panel.sip-trunks.index'))
        ->assertForbidden();
});

it('resolves correct Livewire action permission requirements for sip trunks', function () {
    $resolver = app(LivewireActionPermissions::class);

    // Save on Edit component resolves to edit or create
    $editAbilities = $resolver->abilitiesFor(SipTrunksEdit::class, 'save');
    expect($editAbilities)->toEqual([['sip-trunks.edit', 'sip-trunks.create']]);

    // deleteTrunk on List component resolves to delete
    $deleteAbilities = $resolver->abilitiesFor(SipTrunksList::class, 'deleteTrunk');
    expect($deleteAbilities)->toEqual([['sip-trunks.delete']]);

    // read/confirm actions resolve to view
    $confirmAbilities = $resolver->abilitiesFor(SipTrunksList::class, 'confirmTrunkDeletion');
    expect($confirmAbilities)->toEqual([['sip-trunks.view']]);
});
