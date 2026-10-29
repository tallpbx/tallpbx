<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\TenantDomain;
use App\Services\TenantDomainServiceInterface;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Livewire;
use Modules\Admin\Livewire\TenantDomainsEdit;
use Modules\Admin\Livewire\TenantDomainsList;

beforeEach(function () {
    $this->admin = grantAdminPermissions(Admin::factory()->create(['enabled' => true]), [
        'panel.dashboard.view',
        'admin.tenant-domains.view',
        'admin.tenant-domains.create',
        'admin.tenant-domains.update',
        'admin.tenant-domains.delete',
    ]);
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

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsEdit::class)
        ->assertOk()
        ->assertSee('Create Tenant Domain')
        ->assertSet('domain', '')
        ->assertSet('purpose', 'sip_realm');
});

it('renders the edit form with existing domain data', function () {
    $domain = TenantDomain::factory()->create([
        'domain' => 'acme.example.com',
        'purpose' => 'sip_realm',
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsEdit::class, ['domainId' => $domain->id])
        ->assertOk()
        ->assertSee('Edit Tenant Domain')
        ->assertSet('domain', 'acme.example.com')
        ->assertSet('purpose', 'sip_realm');
});

it('creates a new tenant domain', function () {
    $tenant = Tenant::factory()->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsEdit::class)
        ->set('domain', 'new.example.com')
        ->set('tenantId', $tenant->id)
        ->set('purpose', 'sip_realm')
        ->call('save')
        ->assertRedirect(route('panel.tenant-domains.index'));

    $this->assertDatabaseHas('tenant_domains', [
        'domain' => 'new.example.com',
        'tenant_id' => $tenant->id,
        'purpose' => 'sip_realm',
    ]);
});

it('updates an existing tenant domain', function () {
    $domain = TenantDomain::factory()->create([
        'domain' => 'old.example.com',
        'purpose' => 'sip_realm',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsEdit::class, ['domainId' => $domain->id])
        ->set('domain', 'updated.example.com')
        ->set('purpose', 'web')
        ->call('save')
        ->assertRedirect(route('panel.tenant-domains.index'));

    $this->assertDatabaseHas('tenant_domains', [
        'id' => $domain->id,
        'domain' => 'updated.example.com',
        'purpose' => 'web',
    ]);
});

it('validates domain and tenant are required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsEdit::class)
        ->set('domain', '')
        ->set('tenantId', null)
        ->call('save')
        ->assertHasErrors(['domain', 'tenantId']);
});

it('validates domain is unique', function () {
    TenantDomain::factory()->create(['domain' => 'taken.example.com']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsEdit::class)
        ->set('domain', 'taken.example.com')
        ->set('tenantId', Tenant::factory()->create()->id)
        ->call('save')
        ->assertHasErrors(['domain']);
});

it('allows same domain when editing the same record', function () {
    $domain = TenantDomain::factory()->create(['domain' => 'keep.example.com']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TenantDomainsEdit::class, ['domainId' => $domain->id])
        ->set('domain', 'keep.example.com')
        ->set('tenantId', $domain->tenant_id)
        ->call('save')
        ->assertRedirect(route('panel.tenant-domains.index'));
});
