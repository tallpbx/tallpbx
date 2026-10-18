<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx\Services;

use App\Models\Tenant;
use App\Services\DestinationResolver;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Modules\Bridges\Models\Bridge;
use Modules\Destinations\Models\Destination;
use Modules\Destinations\Services\DestinationServiceInterface;
use Modules\Extensions\Models\Extension;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\FeatureCodes\Services\FeatureCodeServiceInterface;

/*
|--------------------------------------------------------------------------
| Destination Service Tests
|--------------------------------------------------------------------------
*/

describe('destination service', function (): void {
    beforeEach(function (): void {
        $this->service = app(DestinationServiceInterface::class);
    });

    it('creates a destination', function (): void {
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

    it('updates a destination', function (): void {
        $destination = Destination::factory()->create(['name' => 'Old Dest']);

        $updated = $this->service->update($destination, [
            'name' => 'New Destination',
            'dial_string' => '4000',
        ]);

        expect($updated->name)->toBe('New Destination')
            ->and($updated->dial_string)->toBe('4000');
    });

    it('deletes a destination', function (): void {
        $destination = Destination::factory()->create();

        $this->service->delete($destination);

        $this->assertModelMissing($destination);
    });

    it('returns destinations scoped by tenant', function (): void {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        Destination::factory()->forTenant($tenant1->id)->count(2)->create();
        Destination::factory()->forTenant($tenant2->id)->count(3)->create();

        expect($this->service->getByTenant($tenant1->id))->toHaveCount(2);
    });

    it('filters destinations by type', function (): void {
        $tenant = Tenant::factory()->create();

        Destination::factory()->forTenant($tenant->id)->create(['type' => 'conference']);
        Destination::factory()->forTenant($tenant->id)->create(['type' => 'ivr']);
        Destination::factory()->forTenant($tenant->id)->create(['type' => 'voicemail']);

        $results = $this->service->getByType($tenant->id, 'conference');

        expect($results)->toHaveCount(1)
            ->and($results->first()->type)->toBe('conference');
    });
});

/*
|--------------------------------------------------------------------------
| Feature Code Service Tests
|--------------------------------------------------------------------------
*/

describe('feature code service', function (): void {
    beforeEach(function (): void {
        $this->service = app(FeatureCodeServiceInterface::class);
    });

    it('creates a feature code', function (): void {
        $tenant = Tenant::factory()->create();

        $code = $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Voicemail',
            'code' => '*97',
            'description' => 'Access voicemail',
            'enabled' => true,
        ]);

        expect($code)
            ->toBeInstanceOf(FeatureCode::class)
            ->name->toBe('Voicemail')
            ->code->toBe('*97')
            ->enabled->toBeTrue();
    });

    it('updates a feature code', function (): void {
        $code = FeatureCode::factory()->create([
            'name' => 'Old Feature',
            'code' => '*98',
        ]);

        $updated = $this->service->update($code, [
            'name' => 'New Feature',
            'code' => '*99',
        ]);

        expect($updated->name)->toBe('New Feature')
            ->and($updated->code)->toBe('*99');
    });

    it('deletes a feature code', function (): void {
        $code = FeatureCode::factory()->create();

        $this->service->delete($code);

        $this->assertModelMissing($code);
    });

    it('enforces unique code per tenant', function (): void {
        $tenant = Tenant::factory()->create();

        $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Voicemail',
            'code' => '*97',
        ]);

        $this->expectException(ValidationException::class);

        $this->service->create([
            'tenant_id' => $tenant->id,
            'name' => 'Call Forward',
            'code' => '*97',
        ]);
    });

    it('returns feature codes scoped by tenant', function (): void {
        $tenant1 = Tenant::factory()->create();
        $tenant2 = Tenant::factory()->create();

        FeatureCode::factory()->forTenant($tenant1->id)->count(2)->create();
        FeatureCode::factory()->forTenant($tenant2->id)->count(3)->create();

        expect($this->service->getByTenant($tenant1->id))->toHaveCount(2);
    });
});

/*
|--------------------------------------------------------------------------
| Destination Resolver Tests
|--------------------------------------------------------------------------
*/

describe('destination resolver', function (): void {
    beforeEach(function (): void {
        $this->resolver = app(DestinationResolver::class);
        $this->tenant = Tenant::factory()->create();
    });

    it('resolves an extension destination to bridge user/number', function (): void {
        Extension::factory()->create([
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

    it('rejects unknown extension number', function (): void {
        $this->resolver->resolve(DestinationResolver::KIND_EXTENSION, '999', $this->tenant->id);
    })->throws(InvalidArgumentException::class, 'Extension not found');

    it('rejects disabled extension', function (): void {
        Extension::factory()->create([
            'tenant_id' => $this->tenant->id,
            'extension_number' => '102',
            'enabled' => false,
        ]);

        $this->resolver->resolve(DestinationResolver::KIND_EXTENSION, '102', $this->tenant->id);
    })->throws(InvalidArgumentException::class, 'Extension not found');

    it('resolves an external number to bridge sofia/external/number', function (): void {
        $result = $this->resolver->resolve(DestinationResolver::KIND_EXTERNAL, '16175551234', $this->tenant->id);

        expect($result)->toBe([
            'application' => 'bridge',
            'data' => 'sofia/external/16175551234',
        ]);
    });

    it('resolves a conference bridge destination', function (): void {
        Bridge::factory()->create([
            'tenant_id' => $this->tenant->id,
            'bridge_name' => 'sales-conf',
            'enabled' => true,
        ]);

        $result = $this->resolver->resolve(DestinationResolver::KIND_CONFERENCE, 'sales-conf', $this->tenant->id);

        expect($result['application'])->toBe('conference');
        expect($result['data'])->toContain('sales-conf@default');
    });

    it('rejects unknown destination kind', function (): void {
        $this->resolver->resolve('bogus', 'anything', $this->tenant->id);
    })->throws(InvalidArgumentException::class, 'Unknown destination kind');

    it('does not resolve destination from another tenant', function (): void {
        $otherTenant = Tenant::factory()->create();
        Extension::factory()->create([
            'tenant_id' => $otherTenant->id,
            'extension_number' => '201',
            'enabled' => true,
        ]);

        $this->resolver->resolve(DestinationResolver::KIND_EXTENSION, '201', $this->tenant->id);
    })->throws(InvalidArgumentException::class, 'Extension not found');
});
