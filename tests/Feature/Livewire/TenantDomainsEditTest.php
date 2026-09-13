<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Models\TenantDomain;
use Livewire\Livewire;
use Modules\Admin\Livewire\TenantDomainsEdit;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
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
