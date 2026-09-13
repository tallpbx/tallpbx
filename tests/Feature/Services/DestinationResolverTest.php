<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\DestinationResolver;
use Modules\Bridges\Models\Bridge;
use Modules\Extensions\Models\Extension;

beforeEach(function () {
    $this->resolver = app(DestinationResolver::class);
    $this->tenant = Tenant::factory()->create();
});

// ─── Extension ──────────────────────────────────────────────────

it('resolves an extension destination to bridge user/number', function () {
    $ext = Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '101',
        'enabled' => true,
    ]);

    $result = $this->resolver->resolve(DestinationResolver::KIND_EXTENSION, '101', $this->tenant->id);

    expect($result)->toBe([
        'application' => 'bridge',
        'data' => 'user/101',
    ]);
});

it('rejects unknown extension number', function () {
    $this->resolver->resolve(DestinationResolver::KIND_EXTENSION, '999', $this->tenant->id);
})->throws(InvalidArgumentException::class, 'Extension not found');

it('rejects disabled extension', function () {
    Extension::factory()->create([
        'tenant_id' => $this->tenant->id,
        'extension_number' => '102',
        'enabled' => false,
    ]);

    $this->resolver->resolve(DestinationResolver::KIND_EXTENSION, '102', $this->tenant->id);
})->throws(InvalidArgumentException::class, 'Extension not found');

// ─── External ───────────────────────────────────────────────────

it('resolves an external number to bridge sofia/external/number', function () {
    $result = $this->resolver->resolve(DestinationResolver::KIND_EXTERNAL, '16175551234', $this->tenant->id);

    expect($result)->toBe([
        'application' => 'bridge',
        'data' => 'sofia/external/16175551234',
    ]);
});

// ─── Conference ─────────────────────────────────────────────────

it('resolves a conference bridge destination', function () {
    $bridge = Bridge::factory()->create([
        'tenant_id' => $this->tenant->id,
        'bridge_name' => 'sales-conf',
        'enabled' => true,
    ]);

    $result = $this->resolver->resolve(DestinationResolver::KIND_CONFERENCE, 'sales-conf', $this->tenant->id);

    expect($result['application'])->toBe('conference');
    expect($result['data'])->toContain('sales-conf@default');
});

// ─── Unknown Kind ───────────────────────────────────────────────

it('rejects unknown destination kind', function () {
    $this->resolver->resolve('bogus', 'anything', $this->tenant->id);
})->throws(InvalidArgumentException::class, 'Unknown destination kind');

// ─── Tenant Isolation ───────────────────────────────────────────

it('does not resolve destination from another tenant', function () {
    $otherTenant = Tenant::factory()->create();
    Extension::factory()->create([
        'tenant_id' => $otherTenant->id,
        'extension_number' => '201',
        'enabled' => true,
    ]);

    // Asking for extension 201 in TENANT 1 should fail — it belongs to tenant 2
    $this->resolver->resolve(DestinationResolver::KIND_EXTENSION, '201', $this->tenant->id);
})->throws(InvalidArgumentException::class, 'Extension not found');
