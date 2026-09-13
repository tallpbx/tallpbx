<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\Destinations\Models\Destination;
use Modules\Destinations\Services\DestinationServiceInterface;

beforeEach(function () {
    $this->service = app(DestinationServiceInterface::class);
});

it('creates a destination', function () {
    $tenant = Tenant::factory()->create();

    $destination = $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Conference Room',
        'type' => 'conference',
        'dial_string' => '3000',
        'description' => 'Main conference room',
        'enabled' => true,
    ]);

    expect($destination)
        ->toBeInstanceOf(Destination::class)
        ->name->toBe('Conference Room')
        ->type->toBe('conference')
        ->dial_string->toBe('3000');
});

it('updates a destination', function () {
    $destination = Destination::factory()->create(['name' => 'Old Dest']);

    $updated = $this->service->update($destination, [
        'name' => 'New Destination',
        'dial_string' => '4000',
    ]);

    expect($updated->name)->toBe('New Destination')
        ->and($updated->dial_string)->toBe('4000');
});

it('deletes a destination', function () {
    $destination = Destination::factory()->create();

    $this->service->delete($destination);

    $this->assertModelMissing($destination);
});

it('returns destinations scoped by tenant', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    Destination::factory()->forTenant($tenant1->id)->count(2)->create();
    Destination::factory()->forTenant($tenant2->id)->count(3)->create();

    expect($this->service->getByTenant($tenant1->id))->toHaveCount(2);
});

it('filters destinations by type', function () {
    $tenant = Tenant::factory()->create();

    Destination::factory()->forTenant($tenant->id)->create(['type' => 'conference']);
    Destination::factory()->forTenant($tenant->id)->create(['type' => 'ivr']);
    Destination::factory()->forTenant($tenant->id)->create(['type' => 'voicemail']);

    $results = $this->service->getByType($tenant->id, 'conference');

    expect($results)->toHaveCount(1)
        ->and($results->first()->type)->toBe('conference');
});
