<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Services\DialplanServiceInterface;

beforeEach(function () {
    $this->service = app(DialplanServiceInterface::class);
});

it('creates a dialplan', function () {
    $tenant = Tenant::factory()->create();

    $dialplan = $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main Dialplan',
        'description' => 'Default routing',
        'context' => 'default',
        'order' => 100,
        'enabled' => true,
    ]);

    expect($dialplan)
        ->toBeInstanceOf(Dialplan::class)
        ->name->toBe('Main Dialplan')
        ->context->toBe('default')
        ->order->toBe(100);
});

it('creates a dialplan with details', function () {
    $tenant = Tenant::factory()->create();

    $dialplan = $this->service->create([
        'tenant_id' => $tenant->id,
        'name' => 'Main Dialplan',
        'context' => 'default',
        'details' => [
            [
                'tag' => 'condition',
                'field' => 'destination_number',
                'expression' => '^101$',
                'action' => 'bridge',
                'data' => 'user/101',
                'order' => 10,
            ],
        ],
    ]);

    expect($dialplan->details)->toHaveCount(1)
        ->and($dialplan->details->first()->expression)->toBe('^101$');
});

it('updates a dialplan', function () {
    $dialplan = Dialplan::factory()->create(['name' => 'Old Dialplan']);

    $updated = $this->service->update($dialplan, [
        'name' => 'Updated Dialplan',
        'order' => 200,
    ]);

    expect($updated->name)->toBe('Updated Dialplan')
        ->and($updated->order)->toBe(200);
});

it('deletes a dialplan with its details', function () {
    $dialplan = Dialplan::factory()->hasDetails(3)->create();

    $this->service->delete($dialplan);

    $this->assertModelMissing($dialplan);
    $this->assertDatabaseMissing('dialplan_details', [
        'dialplan_id' => $dialplan->id,
    ]);
});

it('returns dialplans scoped by tenant', function () {
    $tenant1 = Tenant::factory()->create();
    $tenant2 = Tenant::factory()->create();

    Dialplan::factory()->forTenant($tenant1->id)->count(2)->create();
    Dialplan::factory()->forTenant($tenant2->id)->count(3)->create();

    expect($this->service->getByTenant($tenant1->id))->toHaveCount(2);
});

it('generates xml config for a dialplan', function () {
    $dialplan = Dialplan::factory()->hasDetails(1)->create([
        'name' => 'default',
        'context' => 'default',
    ]);
    $dialplan->details->first()->update([
        'tag' => 'condition',
        'field' => 'destination_number',
        'expression' => '^101$',
        'action' => 'bridge',
        'data' => 'user/101',
    ]);

    $xml = $this->service->generateConfig($dialplan);

    expect($xml)->toContain('destination_number')
        ->toContain('^101$')
        ->toContain('bridge');
});
