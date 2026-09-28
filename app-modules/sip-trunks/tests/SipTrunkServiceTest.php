<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Modules\SipTrunks\Models\SipTrunk;
use Modules\SipTrunks\Services\SipTrunkServiceInterface;

beforeEach(function () {
    $this->service = app(SipTrunkServiceInterface::class);
    $this->tenant = Tenant::factory()->create();
});

it('creates a sip trunk via service', function () {
    $trunk = $this->service->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Primary Carrier',
        'host' => 'trunk.carrier.com',
        'port' => 5060,
        'username' => 'carrier_user',
        'password' => 'super_secret',
        'codecs' => 'PCMU,PCMA,G722',
        'enabled' => true,
    ]);

    expect($trunk)
        ->toBeInstanceOf(SipTrunk::class)
        ->name->toBe('Primary Carrier')
        ->host->toBe('trunk.carrier.com')
        ->port->toBe(5060)
        ->username->toBe('carrier_user')
        ->codecs->toBe('PCMU,PCMA,G722')
        ->enabled->toBeTrue();

    // Password should be encrypted in DB
    $this->assertDatabaseHas('sip_trunks', [
        'id' => $trunk->id,
        'name' => 'Primary Carrier',
        'host' => 'trunk.carrier.com',
    ]);
});

it('finds a sip trunk by id', function () {
    $trunk = SipTrunk::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Findable Trunk',
    ]);

    $found = $this->service->find($trunk->id);

    expect($found->id)->toBe($trunk->id)
        ->and($found->name)->toBe('Findable Trunk');
});

it('throws exception when trunk id not found', function () {
    expect(fn () => $this->service->find('non-existent-uuid'))
        ->toThrow(ModelNotFoundException::class);
});

it('updates an existing sip trunk via service', function () {
    $trunk = SipTrunk::factory()->create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Old Name',
        'port' => 5060,
    ]);

    $updated = $this->service->update($trunk, [
        'name' => 'New Name',
        'port' => 5080,
    ]);

    expect($updated->name)->toBe('New Name')
        ->and($updated->port)->toBe(5080);

    $this->assertDatabaseHas('sip_trunks', [
        'id' => $trunk->id,
        'name' => 'New Name',
        'port' => 5080,
    ]);
});

it('deletes a sip trunk via service', function () {
    $trunk = SipTrunk::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->service->delete($trunk);

    $this->assertModelMissing($trunk);
});

it('retrieves all sip trunks ordered by name', function () {
    SipTrunk::factory()->create(['name' => 'Zeta Carrier']);
    SipTrunk::factory()->create(['name' => 'Alpha Carrier']);
    SipTrunk::factory()->create(['name' => 'Beta Carrier']);

    $all = $this->service->all();

    expect($all->pluck('name')->toArray())
        ->toBe(['Alpha Carrier', 'Beta Carrier', 'Zeta Carrier']);
});
