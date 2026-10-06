<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Livewire\Livewire;
use Modules\Dialplans\Livewire\DialplansEdit;
use Modules\Dialplans\Livewire\DialplansList;
use Modules\Dialplans\Models\Dialplan;

use function Pest\Laravel\actingAs;

describe('List Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
    });

    it('renders the dialplans list component', function () {
        Dialplan::factory()->count(3)->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansList::class)
            ->assertOk()
            ->assertSee('Dialplans')
            ->assertViewHas('dialplans', function ($dialplans) {
                return $dialplans->count() === 3;
            });
    });

    it('displays dialplan name and context', function () {
        Dialplan::factory()->create([
            'name' => 'Default',
            'context' => 'default',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansList::class)
            ->assertSee('Default')
            ->assertSee('default');
    });

    it('deletes a dialplan', function () {
        $dialplan = Dialplan::factory()->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansList::class)
            ->call('deleteDialplan', $dialplan->id)
            ->assertDispatched('dialplan-deleted');

        $this->assertModelMissing($dialplan);
    });

    it('opens the shared confirmation modal before deleting a dialplan', function (): void {
        $dialplan = Dialplan::factory()->create(['name' => 'Office routing']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansList::class)
            ->call('confirmDialplanDeletion', $dialplan->id)
            ->assertSet('pendingDeletionId', $dialplan->id)
            ->assertSet('pendingDeletionName', 'Office routing')
            ->assertSee('Delete Dialplan?');
    });

    it('shows empty state when no dialplans exist', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansList::class)
            ->assertSee('No dialplans found');
    });

    it('shows detail count for each dialplan', function () {
        Dialplan::factory()->hasDetails(5)->create(['name' => 'With Details']);
        Dialplan::factory()->create(['name' => 'Empty']);

        $component = Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansList::class);

        $dialplans = $component->viewData('dialplans');
        $withDetails = $dialplans->firstWhere('name', 'With Details');
        $empty = $dialplans->firstWhere('name', 'Empty');

        expect($withDetails->details_count)->toBe(5)
            ->and($empty->details_count)->toBe(0);
    });
});

describe('Edit Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
        $this->tenant = Tenant::factory()->create();
    });

    it('renders the create form', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansEdit::class)
            ->assertOk()
            ->assertSee('Create Dialplan')
            ->assertSet('name', '')
            ->assertSet('context', '');
    });

    it('renders the edit form with existing data', function () {
        $dialplan = Dialplan::factory()->create([
            'name' => 'Default',
            'context' => 'default',
            'description' => 'Main routing dialplan',
            'order' => 100,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansEdit::class, ['dialplanId' => $dialplan->id])
            ->assertOk()
            ->assertSee('Edit Dialplan')
            ->assertSet('name', 'Default')
            ->assertSet('context', 'default');
    });

    it('creates a new dialplan', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'Inbound')
            ->set('context', 'public')
            ->set('description', 'Inbound routing')
            ->set('order', '50')
            ->call('save')
            ->assertRedirect(route('panel.dialplans.index'));

        $this->assertDatabaseHas('dialplans', [
            'name' => 'Inbound',
            'context' => 'public',
        ]);
    });

    it('updates an existing dialplan', function () {
        $dialplan = Dialplan::factory()->create(['name' => 'Old']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansEdit::class, ['dialplanId' => $dialplan->id])
            ->set('name', 'Updated Dialplan')
            ->set('context', 'new_context')
            ->call('save')
            ->assertRedirect(route('panel.dialplans.index'));

        $this->assertDatabaseHas('dialplans', [
            'id' => $dialplan->id,
            'name' => 'Updated Dialplan',
            'context' => 'new_context',
        ]);
    });

    it('validates name is required', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansEdit::class)
            ->set('name', '')
            ->call('save')
            ->assertHasErrors(['name' => 'required']);
    });

    it('validates context is required', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(DialplansEdit::class)
            ->set('name', 'Test')
            ->set('context', '')
            ->call('save')
            ->assertHasErrors(['context' => 'required']);
    });
});

describe('Permission Gates', function () {
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
});
