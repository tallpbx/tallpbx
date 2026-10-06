<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantManager;
use App\Support\LivewireActionPermissions;
use Livewire\Livewire;
use Modules\SipTrunks\Livewire\SipTrunksEdit;
use Modules\SipTrunks\Livewire\SipTrunksList;
use Modules\SipTrunks\Models\SipTrunk;

use function Pest\Laravel\actingAs;

describe('Component Interactions', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
        $this->tenant = Tenant::factory()->create();
        app(TenantManager::class)->setTenantId((string) $this->tenant->id);
    });

    afterEach(function () {
        app(TenantManager::class)->setTenantId(null);
    });

    it('renders the list component', function () {
        SipTrunk::factory()->count(2)->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksList::class)
            ->assertOk()
            ->assertSee('SIP Trunks')
            ->assertViewHas('trunks', fn ($items) => $items->count() === 2);
    });

    it('renders the create form', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksEdit::class)
            ->assertOk()
            ->assertSee('Create');
    });

    it('renders the edit form', function () {
        $trunk = SipTrunk::factory()->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksEdit::class, ['trunkId' => $trunk->id])
            ->assertOk()
            ->assertSee('Edit');
    });

    it('creates a SIP trunk', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'My SIP Trunk')
            ->set('host', 'sip.example.com')
            ->set('port', 5060)
            ->set('username', 'user123')
            ->set('password', 'secret')
            ->set('codecs', 'PCMU,PCMA')
            ->call('save')
            ->assertRedirect(route('panel.sip-trunks.index'));

        expect(SipTrunk::count())->toBe(1);
    });

    it('validates required fields', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksEdit::class)
            ->call('save')
            ->assertHasErrors(['tenantId', 'name', 'host']);
    });

    it('deletes a SIP trunk', function () {
        $trunk = SipTrunk::factory()->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksList::class)
            ->call('deleteTrunk', $trunk->id)
            ->assertOk();

        expect(SipTrunk::count())->toBe(0);
    });

    it('opens the shared confirmation modal before deleting a SIP trunk', function (): void {
        $trunk = SipTrunk::factory()->create(['name' => 'Primary carrier']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksList::class)
            ->call('confirmTrunkDeletion', $trunk->id)
            ->assertSet('pendingDeletionId', $trunk->id)
            ->assertSet('pendingDeletionName', 'Primary carrier')
            ->assertSee('Delete SIP Trunk?');
    });

    it('updates a SIP trunk', function () {
        $trunk = SipTrunk::factory()->create([
            'tenant_id' => $this->tenant->id,
            'name' => 'Original Name',
            'host' => 'original.com',
            'port' => 5060,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksEdit::class, ['trunkId' => $trunk->id])
            ->set('name', 'Updated Name')
            ->set('host', 'updated.com')
            ->set('port', 5080)
            ->call('save')
            ->assertRedirect(route('panel.sip-trunks.index'));

        $fresh = $trunk->fresh();
        expect($fresh->name)->toBe('Updated Name')
            ->and($fresh->host)->toBe('updated.com')
            ->and($fresh->port)->toBe(5080);
    });

    it('validates port number boundaries', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'Test Trunk')
            ->set('host', 'sip.test.com')
            ->set('port', 0)
            ->call('save')
            ->assertHasErrors(['port' => 'min']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('name', 'Test Trunk')
            ->set('host', 'sip.test.com')
            ->set('port', 70000)
            ->call('save')
            ->assertHasErrors(['port' => 'max']);
    });

    it('shows empty state when no trunks exist', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(SipTrunksList::class)
            ->assertSee('No trunks found');
    });
});

describe('Permission Gates', function () {
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
});
