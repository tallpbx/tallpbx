<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\CallDetailRecords\Livewire\CdrList;
use Modules\CallDetailRecords\Models\Cdr;

/*
 * FreeSWITCH stores one direction per channel, which cannot express a call
 * that leaves one tenant and arrives at another. The panel shows a
 * tenant-relative direction instead, derived from the paired leg.
 */

/**
 * Create the two legs of one bridged call.
 *
 * @return array{0: Cdr, 1: Cdr}
 */
function bridgedPair(Tenant $tenant, ?Tenant $peerTenant = null): array
{
    $callUuid = fake()->uuid();
    $peerUuid = fake()->uuid();

    $a = Cdr::factory()->create([
        'tenant_id' => $tenant->id,
        'call_uuid' => $callUuid,
        'b_leg_uuid' => $peerUuid,
        'leg' => 'a',
        'direction' => 'outbound',
    ]);

    $b = Cdr::factory()->create([
        'tenant_id' => ($peerTenant ?? $tenant)->id,
        'call_uuid' => $peerUuid,
        'b_leg_uuid' => $callUuid,
        'leg' => 'b',
        'direction' => 'inbound',
    ]);

    return [$a, $b];
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->otherTenant = Tenant::factory()->create();
    app(TenantManager::class)->setTenantId((string) $this->tenant->id);
});

afterEach(function (): void {
    app(TenantManager::class)->clear();
});

it('treats a same-tenant bridge as internal for both legs', function (): void {
    [$a, $b] = bridgedPair(tenant: $this->tenant);

    expect($a->tenantDirection())->toBe('internal')
        ->and($b->tenantDirection())->toBe('internal');
});

it('reports a cross-tenant bridge as outbound for the caller and inbound for the callee', function (): void {
    [$a, $b] = bridgedPair(tenant: $this->tenant, peerTenant: $this->otherTenant);

    expect($a->tenantDirection())->toBe('outbound')
        ->and($b->tenantDirection())->toBe('inbound');
});

it('falls back to the FreeSWITCH direction for a leg with no peer', function (): void {
    // A gateway call has no bridge partner; the stored direction decides.
    expect(Cdr::factory()->create(['tenant_id' => $this->tenant->id, 'direction' => 'inbound', 'b_leg_uuid' => null])->tenantDirection())->toBe('inbound')
        ->and(Cdr::factory()->create(['tenant_id' => $this->tenant->id, 'direction' => 'outbound', 'b_leg_uuid' => null])->tenantDirection())->toBe('outbound');
});

it('never exposes the peer record across tenants', function (): void {
    [$a] = bridgedPair(tenant: $this->tenant, peerTenant: $this->otherTenant);

    app(TenantManager::class)->setTenantId((string) $this->tenant->id);

    expect(Cdr::query()->where('call_uuid', $a->b_leg_uuid)->exists())->toBeFalse()
        ->and($a->tenantDirection())->toBe('outbound');
});

it('keeps peer lookup bounded after Livewire rehydrates the list', function (): void {
    $admin = Admin::factory()->create(['enabled' => true]);

    for ($i = 0; $i < 26; $i++) {
        bridgedPair(tenant: $this->tenant);
    }

    $component = Livewire::actingAs($admin, 'admin')->test(CdrList::class);

    // A second request: Livewire restores the public collection from model
    // IDs, so every private direction memo is gone and must be rebuilt.
    $peerLookups = 0;
    DB::listen(function ($query) use (&$peerLookups): void {
        if (str_contains($query->sql, 'where "call_uuid" in')) {
            $peerLookups++;
        }
    });

    $record = Cdr::query()->where('leg', 'a')->firstOrFail();
    $component->call('confirmCdrDeletion', $record->id);

    // One batched lookup for the rendered page, never one per record.
    expect($peerLookups)->toBe(1);
});
