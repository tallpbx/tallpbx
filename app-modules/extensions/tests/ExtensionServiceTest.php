<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Modules\Extensions\Models\Extension;
use Modules\Extensions\Services\ExtensionServiceInterface;

beforeEach(function () {
    $this->service = app(ExtensionServiceInterface::class);
});

it('creates an extension', function () {
    $tenant = Tenant::factory()->create();

    $extension = $this->service->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '101',
        'display_name' => 'John Doe',
        'enabled' => true,
    ]);

    expect($extension)
        ->toBeInstanceOf(Extension::class)
        ->extension_number->toBe('101')
        ->display_name->toBe('John Doe')
        ->enabled->toBeTrue();
});

it('updates an extension', function () {
    $extension = Extension::factory()->create([
        'extension_number' => '101',
        'display_name' => 'Old Name',
    ]);

    $updated = $this->service->update($extension, [
        'extension_number' => '102',
        'display_name' => 'New Name',
    ]);

    expect($updated->extension_number)->toBe('102')
        ->and($updated->display_name)->toBe('New Name');
});

it('deletes an extension', function () {
    $extension = Extension::factory()->create();

    $this->service->delete($extension);

    $this->assertModelMissing($extension);
});

it('enforces unique extension number per tenant', function () {
    $tenant = Tenant::factory()->create();

    $this->service->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '101',
        'display_name' => 'First',
    ]);

    $this->expectException(ValidationException::class);

    $this->service->create([
        'tenant_id' => $tenant->id,
        'extension_number' => '101',
        'display_name' => 'Second',
    ]);
});

it('allows same extension number in different tenants', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    $ext1 = $this->service->create([
        'tenant_id' => $tenant1->id,
        'extension_number' => '101',
        'display_name' => 'Tenant 1',
    ]);

    $ext2 = $this->service->create([
        'tenant_id' => $tenant2->id,
        'extension_number' => '101',
        'display_name' => 'Tenant 2',
    ]);

    expect($ext1->id)->not->toBe($ext2->id);
});

it('can toggle voicemail enabled', function () {
    $extension = Extension::factory()->create(['voicemail_enabled' => false]);

    $this->service->update($extension, ['voicemail_enabled' => true]);

    expect($extension->fresh()->voicemail_enabled)->toBeTrue();
});

it('returns extensions scoped by tenant', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    Extension::factory()->forTenant($tenant1->id)->count(2)->create();
    Extension::factory()->forTenant($tenant2->id)->count(3)->create();

    expect($this->service->getByTenant($tenant1->id))->toHaveCount(2);
});
