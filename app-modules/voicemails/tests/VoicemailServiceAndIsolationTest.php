<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Database\Seeders\LocalMediaFileStoreSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\FileStores\Enums\MediaCategory;
use Modules\FileStores\Services\MediaStorageServiceInterface;
use Modules\Voicemails\Livewire\VoicemailsEdit;
use Modules\Voicemails\Livewire\VoicemailsList;
use Modules\Voicemails\Models\Voicemail;
use Modules\Voicemails\Services\VoicemailService;
use Modules\Voicemails\Services\VoicemailServiceInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('Service Operations', function () {
    beforeEach(function (): void {
        $this->root = storage_path('framework/testing/voicemail-mailbox-dirs');
        config(['media-storage.store_root' => $this->root.'/store']);
        $this->service = app(VoicemailServiceInterface::class);
        $this->tenant = Tenant::factory()->create();
    });

    afterEach(function (): void {
        File::deleteDirectory($this->root);
    });

    it('creates a voicemail', function () {
        $voicemail = $this->service->create([
            'tenant_id' => $this->tenant->id,
            'voicemail_id' => '1000',
            'mailbox' => '1000',
            'name' => 'John Doe\'s Voicemail',
            'password' => '1234',
            'email' => 'john@example.com',
            'require_password' => true,
            'enabled' => true,
        ]);

        expect($voicemail)
            ->toBeInstanceOf(Voicemail::class)
            ->voicemail_id->toBe('1000')
            ->mailbox->toBe('1000')
            ->name->toBe('John Doe\'s Voicemail')
            ->enabled->toBeTrue();
    });

    it('updates a voicemail', function () {
        $voicemail = Voicemail::factory()->create([
            'tenant_id' => $this->tenant->id,
            'voicemail_id' => '1000',
            'name' => 'Old Name',
        ]);

        $updated = $this->service->update($voicemail, [
            'voicemail_id' => '1001',
            'name' => 'Updated Name',
        ]);

        expect($updated->voicemail_id)->toBe('1001')
            ->and($updated->name)->toBe('Updated Name');
    });

    it('deletes a voicemail', function () {
        $voicemail = Voicemail::factory()->create([
            'tenant_id' => $this->tenant->id,
        ]);

        $this->service->delete($voicemail);

        $this->assertModelMissing($voicemail);
    });

    it('enforces unique voicemail_id per tenant', function () {
        $this->service->create([
            'tenant_id' => $this->tenant->id,
            'voicemail_id' => '1000',
            'mailbox' => '1000',
            'name' => 'First',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'tenant_id' => $this->tenant->id,
            'voicemail_id' => '1000',
            'mailbox' => '1000',
            'name' => 'Second',
        ]);
    });

    it('allows same voicemail_id in different tenants', function () {
        $tenant2 = Tenant::factory()->create();

        $vm1 = $this->service->create([
            'tenant_id' => $this->tenant->id,
            'voicemail_id' => '1000',
            'mailbox' => '1000',
            'name' => 'Tenant 1 VM',
        ]);

        $vm2 = $this->service->create([
            'tenant_id' => $tenant2->id,
            'voicemail_id' => '1000',
            'mailbox' => '1000',
            'name' => 'Tenant 2 VM',
        ]);

        expect($vm1->id)->not->toBe($vm2->id);
    });

    it('finds a voicemail by mailbox number', function () {
        Voicemail::factory()->create([
            'tenant_id' => $this->tenant->id,
            'voicemail_id' => '1000',
            'mailbox' => '2000',
        ]);

        $found = $this->service->findByMailbox($this->tenant->id, '2000');

        expect($found)->not->toBeNull()
            ->and($found->voicemail_id)->toBe('1000');
    });

    it('returns null when mailbox not found', function () {
        $found = $this->service->findByMailbox($this->tenant->id, '9999');

        expect($found)->toBeNull();
    });

    it('provisions a group-writable mailbox directory when a mailbox is created', function (): void {
        $mailbox = app(VoicemailService::class)->create([
            'tenant_id' => $this->tenant->id,
            'voicemail_id' => '2002',
            'mailbox' => '2002',
            'name' => 'Group-writable mailbox',
            'require_password' => false,
            'enabled' => true,
        ]);

        $directory = rtrim((string) config('media-storage.store_root'), '/')
            .'/runtime/'.$this->tenant->id.'/voicemail-message/'.$mailbox->mailbox;

        expect(is_dir($directory))->toBeTrue()
            ->and(fileperms($directory) & 07777)->toBe(02775);
    });

    it('keeps existing mailbox records untouched when provisioning is skipped', function (): void {
        $mailbox = Voicemail::factory()->create(['tenant_id' => $this->tenant->id, 'mailbox' => '3001']);

        app(VoicemailService::class)->update($mailbox, ['name' => 'Renamed']);

        expect($mailbox->fresh()->name)->toBe('Renamed');
    });
});

describe('Greeting Storage', function () {
    beforeEach(function (): void {
        $this->mediaRoot = storage_path('framework/testing/voicemail-greeting-storage');
        config()->set('media-storage.store_root', $this->mediaRoot.'/store');
        app(LocalMediaFileStoreSeeder::class)->run();
        $this->tenant = Tenant::factory()->create();
        app(TenantManager::class)->setTenantId((string) $this->tenant->id);
    });

    afterEach(function (): void {
        app(TenantManager::class)->clear();
        File::deleteDirectory($this->mediaRoot);
    });

    it('stores voicemail greetings in the local managed store', function (): void {
        $source = tempnam(sys_get_temp_dir(), 'voicemail-greeting-');
        file_put_contents($source, 'voicemail greeting');
        $owner = Voicemail::factory()->create(['tenant_id' => $this->tenant->id]);

        $asset = app(MediaStorageServiceInterface::class)->storeLocal($owner, MediaCategory::VoicemailGreeting, $source, 'greeting.wav', 'audio/wav');

        expect($asset->category)->toBe(MediaCategory::VoicemailGreeting)
            ->and($owner->mediaAsset->id)->toBe($asset->id)
            ->and(app(MediaStorageServiceInterface::class)->resolveLocalPath($asset->id))->toBeFile();

        unlink($source);
    });
});

describe('Tenant Isolation & Boundaries', function () {
    beforeEach(function () {
        $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
    });

    afterEach(function () {
        app(TenantManager::class)->setTenantId(null);
    });

    it('denies tenant user access to another tenant voicemail on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['voicemails.view', 'voicemails.edit']);
        $foreignVm = Voicemail::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'voicemail_id' => '1001',
            'name' => 'Tenant B Voicemail',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(VoicemailsEdit::class, ['voicemailUuid' => $foreignVm->id])
            ->assertForbidden();
    });

    it('allows tenant user access to their own tenant voicemail on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['voicemails.view', 'voicemails.edit']);
        $ownVm = Voicemail::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'voicemail_id' => '1001',
            'name' => 'Tenant A Voicemail',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(VoicemailsEdit::class, ['voicemailUuid' => $ownVm->id])
            ->assertOk()
            ->assertSet('name', 'Tenant A Voicemail')
            ->assertSet('voicemailUuid', $ownVm->id);
    });

    it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['voicemails.create']);

        $this->actingAs($userA, 'web');

        expect(function () {
            Voicemail::create([
                'tenant_id' => $this->tenantB->id,
                'voicemail_id' => '9999',
                'mailbox' => '9999',
                'name' => 'Spoofed Voicemail',
                'require_password' => false,
                'enabled' => true,
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect(Voicemail::withoutGlobalScope('tenant')->where('voicemail_id', '9999')->exists())->toBeFalse();
    });

    it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['voicemails.edit']);
        $foreignVm = Voicemail::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'voicemail_id' => '1002',
            'name' => 'Original VM',
        ]);

        $this->actingAs($userA, 'web');

        expect(function () use ($foreignVm) {
            $foreignVm->update([
                'name' => 'Hacked VM',
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect($foreignVm->fresh()->name)->toBe('Original VM');
    });

    it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['voicemails.view', 'voicemails.delete']);
        $foreignVm = Voicemail::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'voicemail_id' => '1003',
            'name' => 'Foreign VM',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(VoicemailsList::class)
            ->call('deleteVoicemail', $foreignVm->id)
            ->assertForbidden();

        expect(Voicemail::withoutGlobalScope('tenant')->where('id', $foreignVm->id)->exists())->toBeTrue();
    });

    it('allows tenant user to create voicemail for their own tenant', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['voicemails.view', 'voicemails.create']);

        Livewire::actingAs($userA, 'web')
            ->test(VoicemailsEdit::class)
            ->set('voicemailId', '2005')
            ->set('mailbox', '2005')
            ->set('name', 'Support VM')
            ->set('password', '1234')
            ->call('save')
            ->assertRedirect(route('panel.voicemails.index'));

        $vm = Voicemail::withoutGlobalScope('tenant')->where('voicemail_id', '2005')->first();
        expect($vm)->not->toBeNull()
            ->and($vm->tenant_id)->toBe($this->tenantA->id);
    });

    it('scopes voicemail list to active tenant for tenant users', function () {
        Voicemail::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'voicemail_id' => '1010',
            'name' => 'Tenant A Visible VM',
        ]);

        Voicemail::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'voicemail_id' => '1020',
            'name' => 'Tenant B Hidden VM',
        ]);

        $userA = grantTenantUserPermissions($this->tenantA, ['voicemails.view']);

        Livewire::actingAs($userA, 'web')
            ->test(VoicemailsList::class)
            ->assertSee('Tenant A Visible VM')
            ->assertDontSee('Tenant B Hidden VM');
    });

    it('allows two tenants to have voicemails with the same voicemail_id without collision', function () {
        $vmA = Voicemail::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'voicemail_id' => '1000',
            'mailbox' => '1000',
            'name' => 'Tenant A 1000',
        ]);

        $vmB = Voicemail::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'voicemail_id' => '1000',
            'mailbox' => '1000',
            'name' => 'Tenant B 1000',
        ]);

        expect($vmA->id)->not->toBe($vmB->id)
            ->and($vmA->voicemail_id)->toBe($vmB->voicemail_id)
            ->and($vmA->tenant_id)->toBe($this->tenantA->id)
            ->and($vmB->tenant_id)->toBe($this->tenantB->id);
    });
});
