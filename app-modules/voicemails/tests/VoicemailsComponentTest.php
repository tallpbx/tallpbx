<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Livewire\Livewire;
use Modules\Voicemails\Livewire\VoicemailsEdit;
use Modules\Voicemails\Livewire\VoicemailsList;
use Modules\Voicemails\Models\Voicemail;

use function Pest\Laravel\actingAs;

describe('List Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
    });

    it('renders the voicemails list component', function () {
        Voicemail::factory()->count(3)->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsList::class)
            ->assertOk()
            ->assertSee('Voicemails')
            ->assertViewHas('voicemails', function ($voicemails) {
                return $voicemails->count() === 3;
            });
    });

    it('displays voicemail mailbox and name', function () {
        Voicemail::factory()->create([
            'voicemail_id' => '1000',
            'name' => 'John\'s Voicemail',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsList::class)
            ->assertSee('1000')
            ->assertSee('John\'s Voicemail');
    });

    it('deletes a voicemail', function () {
        $voicemail = Voicemail::factory()->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsList::class)
            ->call('deleteVoicemail', $voicemail->id)
            ->assertDispatched('voicemail-deleted');

        $this->assertModelMissing($voicemail);
    });

    it('opens the shared confirmation modal before deleting a voicemail mailbox', function (): void {
        $voicemail = Voicemail::factory()->create(['voicemail_id' => '1200']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsList::class)
            ->call('confirmVoicemailDeletion', $voicemail->id)
            ->assertSet('pendingDeletionId', $voicemail->id)
            ->assertSet('pendingDeletionName', '1200')
            ->assertSee('Delete Voicemail Mailbox?');
    });

    it('shows empty state when no voicemails exist', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsList::class)
            ->assertSee(__('admin.no_voicemails_found'));
    });

    it('shows password requirement badge', function () {
        Voicemail::factory()->create([
            'voicemail_id' => '1000',
            'require_password' => true,
        ]);
        Voicemail::factory()->create([
            'voicemail_id' => '1001',
            'require_password' => false,
        ]);

        $component = Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsList::class);

        $voicemails = $component->viewData('voicemails');
        $pwRequired = $voicemails->firstWhere('voicemail_id', '1000');
        $pwNotRequired = $voicemails->firstWhere('voicemail_id', '1001');

        expect($pwRequired->require_password)->toBeTrue()
            ->and($pwNotRequired->require_password)->toBeFalse();
    });
});

describe('Edit Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
        $this->tenant = Tenant::factory()->create();
    });

    it('renders the create form', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsEdit::class)
            ->assertOk()
            ->assertSee('Create Voicemail')
            ->assertSet('voicemailId', '')
            ->assertSet('name', '');
    });

    it('renders the edit form with existing voicemail data', function () {
        $voicemail = Voicemail::factory()->create([
            'voicemail_id' => '1000',
            'name' => 'John\'s Voicemail',
            'require_password' => true,
            'forward_to_email' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsEdit::class, ['voicemailUuid' => $voicemail->id])
            ->assertOk()
            ->assertSee('Edit Voicemail')
            ->assertSet('voicemailId', '1000')
            ->assertSet('name', 'John\'s Voicemail')
            ->assertSet('requirePassword', true)
            ->assertSet('forwardToEmail', true);
    });

    it('creates a new voicemail', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('voicemailId', '2000')
            ->set('mailbox', '2000')
            ->set('name', 'Jane\'s Voicemail')
            ->set('email', 'jane@example.com')
            ->set('password', '5678')
            ->call('save')
            ->assertRedirect(route('panel.voicemails.index'));

        $this->assertDatabaseHas('voicemails', [
            'voicemail_id' => '2000',
            'name' => 'Jane\'s Voicemail',
            'email' => 'jane@example.com',
        ]);
    });

    it('updates an existing voicemail', function () {
        $voicemail = Voicemail::factory()->create([
            'voicemail_id' => '1000',
            'name' => 'Old Name',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsEdit::class, ['voicemailUuid' => $voicemail->id])
            ->set('voicemailId', '1001')
            ->set('name', 'Updated Name')
            ->call('save')
            ->assertRedirect(route('panel.voicemails.index'));

        $this->assertDatabaseHas('voicemails', [
            'id' => $voicemail->id,
            'voicemail_id' => '1001',
            'name' => 'Updated Name',
        ]);
    });

    it('validates voicemail ID is required', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsEdit::class)
            ->set('voicemailId', '')
            ->call('save')
            ->assertHasErrors(['voicemailId' => 'required']);
    });

    it('validates voicemail ID uniqueness per tenant', function () {
        Voicemail::factory()->create([
            'tenant_id' => $this->tenant->id,
            'voicemail_id' => '1000',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('voicemailId', '1000')
            ->set('mailbox', '1000')
            ->set('name', 'Duplicate')
            ->call('save')
            ->assertHasErrors(['voicemailId']);
    });

    it('validates email format', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(VoicemailsEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('voicemailId', '3000')
            ->set('email', 'not-an-email')
            ->call('save')
            ->assertHasErrors(['email' => 'email']);
    });
});

describe('Permission Gates', function () {
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
});
