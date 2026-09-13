<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Validation\ValidationException;
use Modules\AccessControls\Models\AccessControl;
use Modules\AccessControls\Services\AccessControlServiceInterface;

beforeEach(function () {
    $this->service = app(AccessControlServiceInterface::class);
});

it('creates an access control rule', function () {
    $tenant = Tenant::factory()->create();

    $rule = $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Allow LAN',
        'description' => 'Allow all LAN traffic',
        'action' => 'allow',
        'enabled' => true,
    ]);

    expect($rule)
        ->toBeInstanceOf(AccessControl::class)
        ->name->toBe('Allow LAN')
        ->action->toBe('allow')
        ->enabled->toBeTrue();
});

it('creates an access control with nodes', function () {
    $tenant = Tenant::factory()->create();

    $rule = $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Allow Office',
        'action' => 'allow',
        'nodes' => [
            ['type' => 'cidr', 'value' => '10.0.0.0/8'],
            ['type' => 'cidr', 'value' => '192.168.0.0/16'],
        ],
    ]);

    expect($rule->nodes)->toHaveCount(2)
        ->and($rule->nodes->first()->value)->toBe('10.0.0.0/8');
});

it('updates an access control', function () {
    $rule = AccessControl::factory()->create(['name' => 'Old']);

    $updated = $this->service->update($rule, [
        'name' => 'Updated Rule',
        'action' => 'deny',
    ]);

    expect($updated->name)->toBe('Updated Rule')
        ->and($updated->action)->toBe('deny');
});

it('deletes an access control with its nodes', function () {
    $rule = AccessControl::factory()->hasNodes(2)->create();

    $this->service->delete($rule);

    $this->assertModelMissing($rule);
    $this->assertDatabaseMissing('access_control_nodes', [
        'access_control_id' => $rule->id,
    ]);
});

it('enforces unique name per tenant', function () {
    $tenant = Tenant::factory()->create();

    $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Allow LAN',
        'action' => 'allow',
    ]);

    $this->expectException(ValidationException::class);

    $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Allow LAN',
        'action' => 'deny',
    ]);
});
