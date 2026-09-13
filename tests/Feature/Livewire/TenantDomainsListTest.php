<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\TenantDomainServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Modules\Admin\Livewire\TenantDomainsList;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

it('renders the tenant domains list component', function () {
    TenantDomain::factory()->count(3)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsList::class)
        ->assertOk()
        ->assertSee('Tenant Domains')
        ->assertViewHas('domains', function ($domains) {
            return $domains->count() === 3;
        });
});

it('deletes a tenant domain after modal confirmation', function () {
    $domain = TenantDomain::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsList::class)
        ->call('confirmDomainDeletion', $domain->id)
        ->assertSet('pendingDeletionId', $domain->id)
        ->call('deleteDomain')
        ->assertSet('operationalMessage', 'Domain deleted.')
        ->assertDispatched('domain-deleted');

    $this->assertModelMissing($domain);
});

it('opens the shared confirmation modal before deleting a tenant domain', function (): void {
    $domain = TenantDomain::factory()->create(['domain' => 'pbx.example.com']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsList::class)
        ->call('confirmDomainDeletion', $domain->id)
        ->assertSet('pendingDeletionId', $domain->id)
        ->assertSet('pendingDeletionName', 'pbx.example.com')
        ->assertSee('Delete Domain?');
});

it('keeps the modal open with a safe error when domain deletion fails', function (): void {
    $domain = TenantDomain::factory()->create();

    $service = Mockery::mock(TenantDomainServiceInterface::class);
    $service->shouldReceive('all')->andReturn(new Collection([$domain]));
    $service->shouldReceive('delete')->once()->andThrow(new RuntimeException('domain still routes tenant logins'));
    app()->instance(TenantDomainServiceInterface::class, $service);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsList::class)
        ->call('confirmDomainDeletion', $domain->id)
        ->call('deleteDomain')
        ->assertSet('deleteError', 'Domain could not be deleted. domain still routes tenant logins');

    $this->assertModelExists($domain);
});

it('shows domain with tenant name', function () {
    $tenant = Tenant::factory()->create(['name' => 'Acme Corp']);
    TenantDomain::factory()->create([
        'domain' => 'acme.example.com',
        'tenant_id' => $tenant->id,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsList::class)
        ->assertSee('acme.example.com')
        ->assertSee('Acme Corp');
});

it('shows domain purpose', function () {
    TenantDomain::factory()->sipRealm()->create([
        'domain' => 'sip.example.com',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsList::class)
        ->assertSee('sip.example.com')
        ->assertSee('sip_realm');
});
