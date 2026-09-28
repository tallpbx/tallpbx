<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Modules\CallCenters\Livewire\QueueEdit;
use Modules\CallCenters\Livewire\QueueList;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
});

it('allows admin with call-centers.view permission to view queues page', function () {
    $admin = grantAdminPermissions(null, ['call-centers.view']);

    actingAs($admin, 'admin')
        ->get(route('panel.call-centers.queues.index'))
        ->assertOk();
});

it('denies admin without call-centers.view permission', function () {
    $admin = Admin::factory()->create(['enabled' => true]);

    actingAs($admin, 'admin')
        ->get(route('panel.call-centers.queues.index'))
        ->assertForbidden();
});

it('allows tenant user with call-centers.view permission to view queues page', function () {
    $user = grantTenantUserPermissions($this->tenant, ['call-centers.view']);

    actingAs($user, 'web')
        ->get(route('panel.call-centers.queues.index'))
        ->assertOk();
});

it('denies tenant user without call-centers.view permission', function () {
    $user = User::factory()->create(['enabled' => true]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'member', 'primary' => true]);

    actingAs($user, 'web')
        ->get(route('panel.call-centers.queues.index'))
        ->assertForbidden();
});

it('resolves correct Livewire action permission requirements for call centers', function () {
    $resolver = app(LivewireActionPermissions::class);

    // Save on Edit component resolves to edit or create
    $editAbilities = $resolver->abilitiesFor(QueueEdit::class, 'save');
    expect($editAbilities)->toEqual([['call-centers.edit', 'call-centers.create']]);

    // deleteQueue on List component resolves to delete
    $deleteAbilities = $resolver->abilitiesFor(QueueList::class, 'deleteQueue');
    expect($deleteAbilities)->toEqual([['call-centers.delete']]);

    // confirm action resolves to view
    $confirmAbilities = $resolver->abilitiesFor(QueueList::class, 'confirmQueueDeletion');
    expect($confirmAbilities)->toEqual([['call-centers.view']]);
});
