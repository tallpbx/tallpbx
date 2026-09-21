<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Modules\Extensions\Livewire\ExtensionsBulkCreate;
use Modules\Extensions\Livewire\ExtensionsEdit;
use Modules\Extensions\Livewire\ExtensionsList;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('allows admin with extensions.view permission to view extensions page', function () {
    $admin = grantAdminPermissions(null, ['extensions.view']);

    actingAs($admin, 'admin')
        ->get(route('panel.extensions.index'))
        ->assertOk();
});

it('denies admin without extensions.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.extensions.index'))
        ->assertForbidden();
});

it('allows tenant user with extensions.view permission to view extensions page', function () {
    $user = grantTenantUserPermissions($this->tenant, ['extensions.view']);

    actingAs($user, 'web')
        ->get(route('panel.extensions.index'))
        ->assertOk();
});

it('denies tenant user without extensions.view permission', function () {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

    actingAs($user, 'web')
        ->get(route('panel.extensions.index'))
        ->assertForbidden();
});

it('resolves correct Livewire action permission requirements for extensions', function () {
    $resolver = app(LivewireActionPermissions::class);

    // Save on Edit component resolves to edit or create
    $editAbilities = $resolver->abilitiesFor(ExtensionsEdit::class, 'save');
    expect($editAbilities)->toEqual([['extensions.edit', 'extensions.create']]);

    // Save on BulkCreate resolves to extensions.create via component override
    $bulkAbilities = $resolver->abilitiesFor(ExtensionsBulkCreate::class, 'save');
    expect($bulkAbilities)->toEqual([['extensions.create']]);

    // deleteExtension on List component resolves to delete
    $deleteAbilities = $resolver->abilitiesFor(ExtensionsList::class, 'deleteExtension');
    expect($deleteAbilities)->toEqual([['extensions.delete']]);

    // confirm action resolves to view
    $confirmAbilities = $resolver->abilitiesFor(ExtensionsList::class, 'confirmExtensionDeletion');
    expect($confirmAbilities)->toEqual([['extensions.view']]);
});
