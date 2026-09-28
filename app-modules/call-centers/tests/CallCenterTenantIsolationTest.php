<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\CallCenters\Livewire\QueueEdit;
use Modules\CallCenters\Livewire\QueueList;
use Modules\CallCenters\Models\Queue;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
    $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
});

afterEach(function () {
    app(TenantManager::class)->setTenantId(null);
});

it('denies tenant user access to another tenant queue on edit form', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['call-centers.view', 'call-centers.edit']);
    $foreignQueue = Queue::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Tenant B Queue',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(QueueEdit::class, ['queueId' => $foreignQueue->id])
        ->assertForbidden();
});

it('allows tenant user access to their own tenant queue on edit form', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['call-centers.view', 'call-centers.edit']);
    $ownQueue = Queue::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Tenant A Queue',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(QueueEdit::class, ['queueId' => $ownQueue->id])
        ->assertOk()
        ->assertSet('name', 'Tenant A Queue')
        ->assertSet('queueId', $ownQueue->id);
});

it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['call-centers.create']);

    $this->actingAs($userA, 'web');

    expect(function () {
        Queue::create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Spoofed Queue',
            'strategy' => 'ring-all',
        ]);
    })->toThrow(HttpException::class, 'Cross-tenant access denied.');

    expect(Queue::withoutGlobalScope('tenant')->where('name', 'Spoofed Queue')->exists())->toBeFalse();
});

it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['call-centers.edit']);
    $foreignQueue = Queue::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Original Queue',
        'strategy' => 'ring-all',
    ]);

    $this->actingAs($userA, 'web');

    expect(function () use ($foreignQueue) {
        $foreignQueue->update([
            'name' => 'Hacked Queue',
        ]);
    })->toThrow(HttpException::class, 'Cross-tenant access denied.');

    expect($foreignQueue->fresh()->name)->toBe('Original Queue');
});

it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['call-centers.view', 'call-centers.delete']);
    $foreignQueue = Queue::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Foreign Queue',
        'strategy' => 'ring-all',
    ]);

    Livewire::actingAs($userA, 'web')
        ->test(QueueList::class)
        ->call('deleteQueue', $foreignQueue->id)
        ->assertForbidden();

    expect(Queue::withoutGlobalScope('tenant')->where('id', $foreignQueue->id)->exists())->toBeTrue();
});

it('allows tenant user to create queue for their own tenant', function () {
    $userA = grantTenantUserPermissions($this->tenantA, ['call-centers.view', 'call-centers.create']);

    Livewire::actingAs($userA, 'web')
        ->test(QueueEdit::class)
        ->set('name', 'Support Queue')
        ->set('strategy', 'ring-all')
        ->set('timeout', 30)
        ->call('save')
        ->assertRedirect(route('panel.call-centers.queues.index'));

    $queue = Queue::withoutGlobalScope('tenant')->where('name', 'Support Queue')->first();
    expect($queue)->not->toBeNull()
        ->and($queue->tenant_id)->toBe($this->tenantA->id);
});

it('scopes queue list to active tenant for tenant users', function () {
    Queue::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Tenant A Visible Queue',
    ]);

    Queue::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Tenant B Hidden Queue',
    ]);

    $userA = grantTenantUserPermissions($this->tenantA, ['call-centers.view']);

    Livewire::actingAs($userA, 'web')
        ->test(QueueList::class)
        ->assertSee('Tenant A Visible Queue')
        ->assertDontSee('Tenant B Hidden Queue');
});

it('allows two tenants to have queues with the same name without collision', function () {
    $queueA = Queue::factory()->create([
        'tenant_id' => $this->tenantA->id,
        'name' => 'Support Desk',
        'strategy' => 'ring-all',
    ]);

    $queueB = Queue::factory()->create([
        'tenant_id' => $this->tenantB->id,
        'name' => 'Support Desk',
        'strategy' => 'ring-all',
    ]);

    expect($queueA->id)->not->toBe($queueB->id)
        ->and($queueA->name)->toBe($queueB->name)
        ->and($queueA->tenant_id)->toBe($this->tenantA->id)
        ->and($queueB->tenant_id)->toBe($this->tenantB->id);
});
