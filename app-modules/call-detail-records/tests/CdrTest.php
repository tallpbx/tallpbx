<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use Livewire\Livewire;
use Modules\XmlCdr\Livewire\CdrDetail;
use Modules\XmlCdr\Livewire\CdrList;
use Modules\XmlCdr\Models\Cdr;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

// Clear the tenant context so route tests never leak it into later tests.
afterEach(function () {
    app(TenantManager::class)->clear();
});

it('renders the CDR list component', function () {
    Cdr::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->assertOk()
        ->assertSee('Call Detail Records')
        ->assertViewHas('records', function ($records) {
            return $records->count() === 3;
        });
});

it('renders the CDR detail component', function () {
    $cdr = Cdr::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrDetail::class, ['cdrId' => $cdr->id])
        ->assertOk()
        ->assertSee($cdr->caller_id);
});

it('renders the CDR detail page through the panel route', function () {
    $cdr = Cdr::factory()->create();

    // The page route enforces the xml-cdr view permission, so grant it to
    // the admin before visiting the detail page.
    grantAdminPermissions($this->admin, ['xml-cdr.view']);

    $this->actingAs($this->admin, 'admin')
        ->get('/panel/cdr/'.$cdr->id)
        ->assertOk()
        ->assertSee($cdr->caller_id);
});

it('lets a tenant user open their own tenant\'s call detail record page', function () {
    $tenant = Tenant::factory()->create();
    $user = grantTenantUserPermissions($tenant, ['xml-cdr.view']);
    $cdr = Cdr::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user, 'web')
        ->get('/panel/cdr/'.$cdr->id)
        ->assertOk()
        ->assertSee($cdr->destination);
});

it('hides another tenant\'s call detail record from a tenant user', function () {
    $tenant = Tenant::factory()->create();
    $otherTenant = Tenant::factory()->create();
    $user = grantTenantUserPermissions($tenant, ['xml-cdr.view']);
    $foreignCdr = Cdr::factory()->create(['tenant_id' => $otherTenant->id]);

    // A foreign record must be invisible: the page answers "not found"
    // instead of disclosing that another tenant owns this record id.
    $this->actingAs($user, 'web')
        ->get('/panel/cdr/'.$foreignCdr->id)
        ->assertNotFound();
});

it('deletes a CDR record', function () {
    $cdr = Cdr::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->call('confirmCdrDeletion', $cdr->id)
        ->assertSet('pendingDeletionId', $cdr->id)
        ->call('deleteCdr')
        ->assertSet('operationalMessage', 'Call detail record deleted.')
        ->assertDispatched('cdr-deleted');

    $this->assertModelMissing($cdr);
});

it('opens the shared confirmation modal before deleting a call detail record', function (): void {
    $cdr = Cdr::factory()->create(['call_uuid' => 'abc-123', 'destination' => '15551234567']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->call('confirmCdrDeletion', $cdr->id)
        ->assertSet('pendingDeletionId', $cdr->id)
        ->assertSet('pendingDeletionName', 'abc-123')
        ->assertSee('Delete Call Detail Record?');
});

it('opens the confirmation modal safely when a CDR has no call UUID or destination', function (): void {
    $cdr = Cdr::factory()->create(['call_uuid' => null, 'destination' => null]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->call('confirmCdrDeletion', $cdr->id)
        ->assertSet('pendingDeletionId', $cdr->id)
        ->assertSet('pendingDeletionName', '')
        ->assertSee('Delete Call Detail Record?');
});

it('shows empty state when no CDRs exist', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->assertSee('No CDR records found');
});
