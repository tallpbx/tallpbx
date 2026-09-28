<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Voicemails\Livewire\VoicemailsEdit;
use Modules\Voicemails\Livewire\VoicemailsList;
use Modules\Voicemails\Models\Voicemail;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
