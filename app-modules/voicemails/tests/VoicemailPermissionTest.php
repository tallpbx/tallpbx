<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Modules\Voicemails\Livewire\VoicemailsEdit;
use Modules\Voicemails\Livewire\VoicemailsList;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('allows admin with voicemails.view permission to view voicemails page', function () {
    $admin = grantAdminPermissions(null, ['voicemails.view']);

    actingAs($admin, 'admin')
        ->get(route('panel.voicemails.index'))
        ->assertOk();
});

it('denies admin without voicemails.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.voicemails.index'))
        ->assertForbidden();
});

it('allows tenant user with voicemails.view permission to view voicemails page', function () {
    $user = grantTenantUserPermissions($this->tenant, ['voicemails.view']);

    actingAs($user, 'web')
        ->get(route('panel.voicemails.index'))
        ->assertOk();
});

it('denies tenant user without voicemails.view permission', function () {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

    actingAs($user, 'web')
        ->get(route('panel.voicemails.index'))
        ->assertForbidden();
});

it('resolves correct Livewire action permission requirements for voicemails', function () {
    $resolver = app(LivewireActionPermissions::class);

    // Save on Edit component resolves to edit or create
    $editAbilities = $resolver->abilitiesFor(VoicemailsEdit::class, 'save');
    expect($editAbilities)->toEqual([['voicemails.edit', 'voicemails.create']]);

    // deleteVoicemail on List component resolves to delete
    $deleteAbilities = $resolver->abilitiesFor(VoicemailsList::class, 'deleteVoicemail');
    expect($deleteAbilities)->toEqual([['voicemails.delete']]);

    // confirm action resolves to view
    $confirmAbilities = $resolver->abilitiesFor(VoicemailsList::class, 'confirmVoicemailDeletion');
    expect($confirmAbilities)->toEqual([['voicemails.view']]);
});
