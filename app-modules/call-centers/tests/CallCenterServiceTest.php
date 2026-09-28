<?php

declare(strict_types=1);

use App\Models\Tenant;
use Modules\CallCenters\Models\Queue;
use Modules\CallCenters\Services\CallCenterServiceInterface;

beforeEach(function () {
    $this->service = app(CallCenterServiceInterface::class);
    $this->tenant = Tenant::factory()->create();
});

it('creates a queue via service', function () {
    $queue = $this->service->createQueue([
        'tenant_id' => $this->tenant->id,
        'name' => 'Support Desk',
        'strategy' => 'ring-all',
        'timeout' => 45,
        'music_on_hold' => 'default',
        'enabled' => true,
    ]);

    expect($queue)->toBeInstanceOf(Queue::class)
        ->name->toBe('Support Desk')
        ->strategy->toBe('ring-all')
        ->timeout->toBe(45)
        ->music_on_hold->toBe('default')
        ->enabled->toBeTrue();

    $this->assertDatabaseHas('call_center_queues', [
        'id' => $queue->id,
        'name' => 'Support Desk',
    ]);
});

it('updates a queue via service', function () {
    $queue = $this->service->createQueue([
        'tenant_id' => $this->tenant->id,
        'name' => 'Old Queue',
        'strategy' => 'ring-all',
        'timeout' => 30,
        'enabled' => true,
    ]);

    $updated = $this->service->updateQueue($queue, [
        'name' => 'New Queue',
        'strategy' => 'longest-idle-agent',
        'timeout' => 60,
    ]);

    expect($updated->name)->toBe('New Queue')
        ->and($updated->strategy)->toBe('longest-idle-agent')
        ->and($updated->timeout)->toBe(60);

    $this->assertDatabaseHas('call_center_queues', [
        'id' => $queue->id,
        'name' => 'New Queue',
    ]);
});

it('deletes a queue via service', function () {
    $queue = $this->service->createQueue([
        'tenant_id' => $this->tenant->id,
        'name' => 'To Delete',
        'strategy' => 'ring-all',
        'timeout' => 30,
        'enabled' => true,
    ]);

    $this->service->deleteQueue($queue);

    $this->assertModelMissing($queue);
});

it('returns dialplan priority of 70', function () {
    expect($this->service->getDialplanPriority())->toBe(70);
});

it('returns null for dialplan xml when no queues exist', function () {
    $xml = $this->service->generateDialplanXml($this->tenant->id, 'default', '1000');

    expect($xml)->toBeNull();
});

it('generates dialplan xml for enabled queues', function () {
    $this->service->createQueue([
        'tenant_id' => $this->tenant->id,
        'name' => 'SupportQueue',
        'strategy' => 'ring-all',
        'timeout' => 30,
        'music_on_hold' => 'moh_jazz',
        'enabled' => true,
    ]);

    $xml = $this->service->generateDialplanXml($this->tenant->id, 'default', 'SupportQueue');

    expect($xml)->not->toBeNull()
        ->and($xml)->toContain('extension name="callcenter_SupportQueue"')
        ->and($xml)->toContain('application="callcenter" data="SupportQueue@default"');
});
