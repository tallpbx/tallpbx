<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Modules\Conferences\Livewire\ConferencesEdit;
use Modules\Conferences\Livewire\ConferencesList;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('allows admin with conferences.view permission to view conferences page', function () {
    $admin = grantAdminPermissions(null, ['conferences.view']);

    actingAs($admin, 'admin')
        ->get(route('panel.conferences.index'))
        ->assertOk();
});

it('denies admin without conferences.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.conferences.index'))
        ->assertForbidden();
});

it('allows tenant user with conferences.view permission to view conferences page', function () {
    $user = grantTenantUserPermissions($this->tenant, ['conferences.view']);

    actingAs($user, 'web')
        ->get(route('panel.conferences.index'))
        ->assertOk();
});

it('denies tenant user without conferences.view permission', function () {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

    actingAs($user, 'web')
        ->get(route('panel.conferences.index'))
        ->assertForbidden();
});

it('resolves correct Livewire action permission requirements for conferences', function () {
    $resolver = app(LivewireActionPermissions::class);

    // Save on Edit component resolves to edit or create
    $editAbilities = $resolver->abilitiesFor(ConferencesEdit::class, 'save');
    expect($editAbilities)->toEqual([['conferences.edit', 'conferences.create']]);

    // deleteConference on List component resolves to delete
    $deleteAbilities = $resolver->abilitiesFor(ConferencesList::class, 'deleteConference');
    expect($deleteAbilities)->toEqual([['conferences.delete']]);

    // confirm action resolves to view
    $confirmAbilities = $resolver->abilitiesFor(ConferencesList::class, 'confirmConferenceDeletion');
    expect($confirmAbilities)->toEqual([['conferences.view']]);
});
