<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\User;
use App\Support\LivewireActionPermissions;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Modules\Extensions\Livewire\ExtensionsBulkCreate;
use Modules\Extensions\Livewire\ExtensionsEdit;
use Modules\Extensions\Livewire\ExtensionsList;
use Modules\Extensions\Models\Extension;
use Modules\SipAccounts\Models\SipAccount;
use Modules\Voicemails\Models\Voicemail;

use function Pest\Laravel\actingAs;

describe('List Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
    });

    it('renders the extensions list component', function () {
        Extension::factory()->count(3)->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsList::class)
            ->assertOk()
            ->assertSee('Extensions')
            ->assertSee('A logical dialable endpoint and identity on the PBX')
            ->assertSee('Create Multiple Extensions')
            ->assertViewHas('extensions', function ($extensions) {
                return $extensions->count() === 3;
            });
    });

    it('displays extension number and display name', function () {
        Extension::factory()->create([
            'extension_number' => '101',
            'display_name' => 'John Doe',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsList::class)
            ->assertSee('101')
            ->assertSee('John Doe');
    });

    it('deletes an extension', function () {
        $extension = Extension::factory()->create();

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsList::class)
            ->call('confirmExtensionDeletion', $extension->id)
            ->assertSet('pendingDeletionId', $extension->id)
            ->call('deleteExtension')
            ->assertSet('operationalMessage', 'Extension deleted.')
            ->assertDispatched('extension-deleted');

        $this->assertModelMissing($extension);
    });

    it('opens the shared confirmation modal before deleting an extension', function (): void {
        $extension = Extension::factory()->create(['extension_number' => '101']);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsList::class)
            ->call('confirmExtensionDeletion', $extension->id)
            ->assertSet('pendingDeletionId', $extension->id)
            ->assertSet('pendingDeletionName', '101')
            ->assertSee('Delete Extension?');
    });

    it('shows empty state when no extensions exist', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsList::class)
            ->assertSee('No extensions found');
    });

    it('renders when the create multiple extensions route is not loaded', function () {
        $originalRoutes = Route::getRoutes();

        $routes = new RouteCollection;
        $routes->add(
            (new Illuminate\Routing\Route(['GET', 'HEAD'], 'panel/extensions/create', ['uses' => fn (): string => '']))
                ->name('panel.extensions.create')
        );

        app('router')->setRoutes($routes);
        app('url')->setRoutes($routes);

        try {
            $html = view('extensions::extensions-list', [
                'extensions' => new EloquentCollection,
                'operationalMessage' => null,
                'operationalMessageType' => null,
                'pendingDeletionId' => null,
                'pendingDeletionName' => '',
            ])->render();

            expect($html)
                ->toContain('Create Extension')
                ->not->toContain('Create Multiple Extensions');
        } finally {
            app('router')->setRoutes($originalRoutes);
            app('url')->setRoutes($originalRoutes);
        }
    });

    it('shows voicemail status badge', function () {
        Extension::factory()->create([
            'extension_number' => '101',
            'voicemail_enabled' => true,
        ]);
        Extension::factory()->create([
            'extension_number' => '102',
            'voicemail_enabled' => false,
        ]);

        $component = Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsList::class);

        $extensions = $component->viewData('extensions');
        $vmEnabled = $extensions->firstWhere('extension_number', '101');
        $vmDisabled = $extensions->firstWhere('extension_number', '102');

        expect($vmEnabled->voicemail_enabled)->toBeTrue()
            ->and($vmDisabled->voicemail_enabled)->toBeFalse();
    });
});

describe('Edit Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
        $this->tenant = Tenant::factory()->create();
    });

    it('renders the create form', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class)
            ->assertOk()
            ->assertSee('Create Extension')
            ->assertSee('A logical dialable endpoint and identity on the PBX')
            ->assertSet('extensionNumber', '')
            ->assertSet('displayName', '');
    });

    it('renders the edit form with existing extension data', function () {
        $extension = Extension::factory()->create([
            'extension_number' => '101',
            'display_name' => 'John Doe',
            'voicemail_enabled' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class, ['extensionId' => $extension->id])
            ->assertOk()
            ->assertSee('Edit Extension')
            ->assertSee('A logical dialable endpoint and identity on the PBX')
            ->assertSet('extensionNumber', '101')
            ->assertSet('displayName', 'John Doe')
            ->assertSet('voicemailEnabled', true);
    });

    it('renders the edit form when visited through the panel route', function () {
        $admin = grantAdminPermissions($this->admin, ['extensions.edit']);
        $extension = Extension::factory()->create([
            'extension_number' => '303',
            'display_name' => 'Route Loaded Extension',
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('panel.extensions.edit', $extension->id))
            ->assertOk()
            ->assertSee('Edit Extension')
            ->assertSee('303')
            ->assertSee('Route Loaded Extension');
    });

    it('creates a new extension', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('extensionNumber', '201')
            ->set('displayName', 'Jane Doe')
            ->call('save')
            ->assertRedirect(route('panel.extensions.index'));

        $this->assertDatabaseHas('extensions', [
            'extension_number' => '201',
            'display_name' => 'Jane Doe',
        ]);
    });

    it('updates an existing extension', function () {
        $extension = Extension::factory()->create([
            'extension_number' => '101',
            'display_name' => 'Old',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class, ['extensionId' => $extension->id])
            ->set('extensionNumber', '102')
            ->set('displayName', 'Updated')
            ->call('save')
            ->assertRedirect(route('panel.extensions.index'));

        $this->assertDatabaseHas('extensions', [
            'id' => $extension->id,
            'extension_number' => '102',
            'display_name' => 'Updated',
        ]);
    });

    it('validates extension number is required', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class)
            ->set('extensionNumber', '')
            ->call('save')
            ->assertHasErrors(['extensionNumber' => 'required']);
    });

    it('validates extension number uniqueness per tenant', function () {
        Extension::factory()->create([
            'tenant_id' => $this->tenant->id,
            'extension_number' => '101',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('extensionNumber', '101')
            ->set('displayName', 'Duplicate')
            ->call('save')
            ->assertHasErrors(['extensionNumber' => 'unique']);
    });

    it('creates an extension with sip password and caller id information', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('extensionNumber', '1001')
            ->set('displayName', 'First Extension')
            ->set('password', 'SecretSIPPass123!')
            ->set('effectiveCallerIdName', 'John Doe')
            ->set('effectiveCallerIdNumber', '1001')
            ->set('outboundCallerIdName', 'Acme Corp')
            ->set('outboundCallerIdNumber', '15551234567')
            ->set('voicemailEnabled', true)
            ->call('save')
            ->assertRedirect(route('panel.extensions.index'));

        $extension = Extension::withoutGlobalScope('tenant')->where('extension_number', '1001')->first();
        expect($extension)->not->toBeNull()
            ->and($extension->display_name)->toBe('First Extension')
            ->and($extension->effective_caller_id_name)->toBe('John Doe')
            ->and($extension->effective_caller_id_number)->toBe('1001')
            ->and($extension->outbound_caller_id_name)->toBe('Acme Corp')
            ->and($extension->outbound_caller_id_number)->toBe('15551234567')
            ->and($extension->voicemail_enabled)->toBeTrue();

        $sipAccount = SipAccount::withoutGlobalScope('tenant')->where('extension_id', $extension->id)->first();
        expect($sipAccount)->not->toBeNull()
            ->and($sipAccount->auth_username)->toBe('1001')
            ->and($sipAccount->auth_password)->toBe('SecretSIPPass123!')
            ->and($sipAccount->user_context)->toBe("tenant_{$this->tenant->id}_internal");

        $voicemail = Voicemail::withoutGlobalScope('tenant')->where('mailbox', '1001')->first();
        expect($voicemail)->not->toBeNull()
            ->and($voicemail->name)->toBe('First Extension Voicemail');
    });

    it('updates existing extension and sip account password', function () {
        $extension = Extension::factory()->forTenant($this->tenant->id)->create([
            'extension_number' => '1001',
            'display_name' => 'Original Name',
        ]);
        $sipAccount = SipAccount::factory()->forTenant($this->tenant->id)->create([
            'extension_id' => $extension->id,
            'auth_username' => '1001',
            'auth_password' => 'OriginalPass',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class, ['extensionId' => $extension->id])
            ->set('password', 'NewSecretPass456!')
            ->set('effectiveCallerIdName', 'Updated Name')
            ->call('save')
            ->assertRedirect(route('panel.extensions.index'));

        expect($sipAccount->fresh()->auth_password)->toBe('NewSecretPass456!');
        expect($extension->fresh()->effective_caller_id_name)->toBe('Updated Name');
    });

    it('preserves existing sip account password when password field is left blank on update', function () {
        $extension = Extension::factory()->forTenant($this->tenant->id)->create([
            'extension_number' => '1001',
            'display_name' => 'Original Name',
        ]);
        $sipAccount = SipAccount::factory()->forTenant($this->tenant->id)->create([
            'extension_id' => $extension->id,
            'auth_username' => '1001',
            'auth_password' => 'KeepThisPass',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class, ['extensionId' => $extension->id])
            ->set('password', '')
            ->set('displayName', 'Renamed Extension')
            ->call('save')
            ->assertRedirect(route('panel.extensions.index'));

        expect($sipAccount->fresh()->auth_password)->toBe('KeepThisPass');
        expect($extension->fresh()->display_name)->toBe('Renamed Extension');
    });

    it('creates extension with custom voicemail password', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class)
            ->set('tenantId', $this->tenant->id)
            ->set('extensionNumber', '1002')
            ->set('displayName', 'Second Extension')
            ->set('voicemailEnabled', true)
            ->set('voicemailPassword', '4321')
            ->call('save')
            ->assertRedirect(route('panel.extensions.index'));

        $voicemail = Voicemail::withoutGlobalScope('tenant')->where('mailbox', '1002')->first();
        expect($voicemail)->not->toBeNull()
            ->and($voicemail->password)->toBe('4321')
            ->and($voicemail->enabled)->toBeTrue();
    });

    it('pre-populates existing voicemail password and updates it', function () {
        $extension = Extension::factory()->forTenant($this->tenant->id)->create([
            'extension_number' => '1003',
            'voicemail_enabled' => true,
        ]);
        $voicemail = Voicemail::factory()->forTenant($this->tenant->id)->create([
            'voicemail_id' => '1003',
            'mailbox' => '1003',
            'password' => '9876',
            'enabled' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class, ['extensionId' => $extension->id])
            ->assertSet('voicemailPassword', '9876')
            ->set('voicemailPassword', '5555')
            ->call('save')
            ->assertRedirect(route('panel.extensions.index'));

        expect($voicemail->fresh()->password)->toBe('5555');
    });

    it('disables voicemail mailbox when voicemailEnabled is toggled off', function () {
        $extension = Extension::factory()->forTenant($this->tenant->id)->create([
            'extension_number' => '1004',
            'voicemail_enabled' => true,
        ]);
        $voicemail = Voicemail::factory()->forTenant($this->tenant->id)->create([
            'voicemail_id' => '1004',
            'mailbox' => '1004',
            'password' => '1234',
            'enabled' => true,
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsEdit::class, ['extensionId' => $extension->id])
            ->set('voicemailEnabled', false)
            ->call('save')
            ->assertRedirect(route('panel.extensions.index'));

        expect($extension->fresh()->voicemail_enabled)->toBeFalse();
        expect($voicemail->fresh()->enabled)->toBeFalse();
    });
});

describe('Bulk Create Component', function () {
    beforeEach(function () {
        $this->admin = Admin::factory()->create(['enabled' => true]);
        $this->tenant = Tenant::factory()->create();
    });

    it('renders the create multiple extensions page through the panel route', function () {
        $admin = grantAdminPermissions($this->admin, ['extensions.create']);

        $this->actingAs($admin, 'admin')
            ->get(route('panel.extensions.create-multiple'))
            ->assertOk()
            ->assertSee('Create Multiple Extensions')
            ->assertSee('A logical dialable endpoint and identity on the PBX')
            ->assertSee('Start Extension')
            ->assertSee('End Extension');
    });

    it('shows the create multiple extensions button on the list', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsList::class)
            ->assertOk()
            ->assertSee('Create Multiple Extensions');
    });

    it('creates a range of extensions', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsBulkCreate::class)
            ->set('tenantId', $this->tenant->id)
            ->set('startExtension', '1200')
            ->set('endExtension', '1202')
            ->set('increment', 1)
            ->set('displayNameTemplate', 'Desk {number}')
            ->set('voicemailEnabled', true)
            ->call('save')
            ->assertRedirect(route('panel.extensions.index'));

        expect(Extension::withoutGlobalScope('tenant')->where('tenant_id', $this->tenant->id)->count())->toBe(3);

        $this->assertDatabaseHas('extensions', [
            'tenant_id' => $this->tenant->id,
            'extension_number' => '1200',
            'display_name' => 'Desk 1200',
            'voicemail_enabled' => true,
        ]);

        $this->assertDatabaseHas('extensions', [
            'tenant_id' => $this->tenant->id,
            'extension_number' => '1202',
            'display_name' => 'Desk 1202',
            'voicemail_enabled' => true,
        ]);
    });

    it('blocks a range that contains an existing extension number', function () {
        Extension::factory()->create([
            'tenant_id' => $this->tenant->id,
            'extension_number' => '1301',
        ]);

        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsBulkCreate::class)
            ->set('tenantId', $this->tenant->id)
            ->set('startExtension', '1300')
            ->set('endExtension', '1302')
            ->call('save')
            ->assertHasErrors('startExtension');

        expect(Extension::withoutGlobalScope('tenant')
            ->where('tenant_id', $this->tenant->id)
            ->whereIn('extension_number', ['1300', '1302'])
            ->exists())->toBeFalse();
    });

    it('limits a single batch to one thousand extensions', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsBulkCreate::class)
            ->set('tenantId', $this->tenant->id)
            ->set('startExtension', '2000')
            ->set('endExtension', '3000')
            ->call('save')
            ->assertHasErrors('endExtension');
    });

    it('shows a loading state on the save button while extensions are created', function () {
        Livewire::actingAs($this->admin, 'admin')
            ->test(ExtensionsBulkCreate::class)
            ->assertSee('wire:target="save"', false)
            ->assertSee('loading loading-spinner', false);
    });
});

describe('Permission Gates', function () {
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
});
