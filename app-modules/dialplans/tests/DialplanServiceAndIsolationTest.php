<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\Dialplans\Livewire\DialplansEdit;
use Modules\Dialplans\Livewire\DialplansList;
use Modules\Dialplans\Models\Dialplan;
use Modules\Dialplans\Services\DialplanServiceInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;

describe('Service Operations', function () {
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
});

describe('Tenant Isolation & Boundaries', function () {
    beforeEach(function () {
        $this->tenantA = Tenant::factory()->create(['name' => 'Tenant A']);
        $this->tenantB = Tenant::factory()->create(['name' => 'Tenant B']);
    });

    afterEach(function () {
        app(TenantManager::class)->setTenantId(null);
    });

    it('denies tenant user access to another tenant dialplan on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.view', 'dialplans.edit']);
        $foreignDialplan = Dialplan::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Tenant B Dialplan',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(DialplansEdit::class, ['dialplanId' => $foreignDialplan->id])
            ->assertForbidden();
    });

    it('allows tenant user access to their own tenant dialplan on edit form', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.view', 'dialplans.edit']);
        $ownDialplan = Dialplan::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Tenant A Dialplan',
            'context' => 'default',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(DialplansEdit::class, ['dialplanId' => $ownDialplan->id])
            ->assertOk()
            ->assertSet('name', 'Tenant A Dialplan')
            ->assertSet('dialplanId', $ownDialplan->id);
    });

    it('prevents cross-tenant creation by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.create']);

        $this->actingAs($userA, 'web');

        expect(function () {
            Dialplan::create([
                'tenant_id' => $this->tenantB->id,
                'name' => 'Spoofed Dialplan',
                'context' => 'default',
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect(Dialplan::withoutGlobalScope('tenant')->where('name', 'Spoofed Dialplan')->exists())->toBeFalse();
    });

    it('prevents cross-tenant updates by tenant users via TenantMutationGuard', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.edit']);
        $foreignDialplan = Dialplan::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Original Dialplan',
            'context' => 'default',
        ]);

        $this->actingAs($userA, 'web');

        expect(function () use ($foreignDialplan) {
            $foreignDialplan->update([
                'name' => 'Hacked Dialplan',
            ]);
        })->toThrow(HttpException::class, 'Cross-tenant access denied.');

        expect($foreignDialplan->fresh()->name)->toBe('Original Dialplan');
    });

    it('prevents cross-tenant deletion by tenant users via TenantMutationGuard in list action', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.view', 'dialplans.delete']);
        $foreignDialplan = Dialplan::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Foreign Dialplan',
            'context' => 'default',
        ]);

        Livewire::actingAs($userA, 'web')
            ->test(DialplansList::class)
            ->call('deleteDialplan', $foreignDialplan->id)
            ->assertForbidden();

        expect(Dialplan::withoutGlobalScope('tenant')->where('id', $foreignDialplan->id)->exists())->toBeTrue();
    });

    it('denies tenant users the deletion confirmation for another tenant dialplan', function () {
        $userA = grantTenantUserPermissions($this->tenantA, ['dialplans.view', 'dialplans.delete']);
        $foreignDialplan = Dialplan::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Hidden Library',
            'context' => 'default',
        ]);

        // Opening the confirmation modal must not disclose another tenant's
        // record: the fetch happens unscoped, so the handler must assert tenant
        // access before storing any of the record's details for display.
        Livewire::actingAs($userA, 'web')
            ->test(DialplansList::class)
            ->call('confirmDialplanDeletion', $foreignDialplan->id)
            ->assertForbidden();
    });

    it('allows two tenants to have dialplans with the same name without collision', function () {
        $dpA = Dialplan::factory()->create([
            'tenant_id' => $this->tenantA->id,
            'name' => 'Local Extension Routing',
            'context' => 'default',
        ]);

        $dpB = Dialplan::factory()->create([
            'tenant_id' => $this->tenantB->id,
            'name' => 'Local Extension Routing',
            'context' => 'default',
        ]);

        expect($dpA->id)->not->toBe($dpB->id)
            ->and($dpA->name)->toBe($dpB->name)
            ->and($dpA->tenant_id)->toBe($this->tenantA->id)
            ->and($dpB->tenant_id)->toBe($this->tenantB->id);
    });
});
