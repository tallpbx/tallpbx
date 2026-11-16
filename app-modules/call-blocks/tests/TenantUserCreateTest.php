<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\CallBlocks\Livewire\CallBlocksEdit;

/*
 * Peer-form proof: the tenant derivation lives in BaseEditComponent, not in one
 * form. CallBlocksEdit is a second subclass that used to require a
 * client-supplied tenantId a tenant user can never see.
 */

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();

    $this->tenantUser = grantTenantUserPermissions($this->tenant, [
        'call-blocks.view',
        'call-blocks.create',
        'call-blocks.edit',
    ]);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
});

it('lets a tenant user create a call block without touching tenant state', function (): void {
    Livewire::actingAs($this->tenantUser, 'web')
        ->test(CallBlocksEdit::class)
        ->set('name', 'Scam callers')
        ->set('callerIdNumber', '+15550001111')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('call_blocks', [
        'name' => 'Scam callers',
        'tenant_id' => $this->tenant->id,
    ]);
});

it('ignores a forged tenant id from a tenant user', function (): void {
    Livewire::actingAs($this->tenantUser, 'web')
        ->test(CallBlocksEdit::class)
        ->set('tenantId', $this->otherTenant->id)
        ->set('name', 'Scam callers')
        ->set('callerIdNumber', '+15550002222')
        ->call('save')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('call_blocks', ['name' => 'Scam callers', 'tenant_id' => $this->tenant->id]);
    $this->assertDatabaseMissing('call_blocks', ['name' => 'Scam callers', 'tenant_id' => $this->otherTenant->id]);
});

it('neutralises a tampered tenant id on a Livewire update', function (): void {
    Livewire::actingAs($this->tenantUser, 'web')
        ->test(CallBlocksEdit::class)
        ->set('tenantId', $this->otherTenant->id)
        ->assertSet('tenantId', $this->tenant->id);
});
