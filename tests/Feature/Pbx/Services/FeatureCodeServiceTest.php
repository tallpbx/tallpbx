<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\FeatureCodes\Services\FeatureCodeServiceInterface;

beforeEach(function () {
    $this->service = app(FeatureCodeServiceInterface::class);
});

it('creates a feature code', function () {
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

it('updates a feature code', function () {
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

it('deletes a feature code', function () {
    $code = FeatureCode::factory()->create();

    $this->service->delete($code);

    $this->assertModelMissing($code);
});

it('enforces unique code per tenant', function () {
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

it('returns feature codes scoped by tenant', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    FeatureCode::factory()->forTenant($tenant1->id)->count(2)->create();
    FeatureCode::factory()->forTenant($tenant2->id)->count(3)->create();

    expect($this->service->getByTenant($tenant1->id))->toHaveCount(2);
});
