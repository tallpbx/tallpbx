<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use App\Services\TenantManager;
use App\Support\LivewireActionPermissions;
use Livewire\Livewire;
use Modules\CallDetailRecords\Livewire\CdrList;
use Modules\CallDetailRecords\Models\Cdr;
use Modules\CallDetailRecords\Providers\ModuleServiceProvider;

/*
 * The call detail list can be exported as a CSV file so an administrator can
 * analyse the records or hand them to another tool. Exporting is a separate
 * permission from viewing: a CSV leaves the panel and lands as a file on the
 * operator's disk, outside the tenant's own access controls, so it has to be
 * a deliberate grant rather than a side effect of being able to see the list.
 */

/**
 * Read the CSV body that Livewire captured for the last download effect.
 *
 * Livewire base64-encodes the streamed file so it can travel inside the
 * component update response; the raw bytes are recovered here.
 */
function cdrExportContent(object $component): string
{
    return base64_decode((string) data_get($component->effects, 'download.content'));
}

beforeEach(function () {
    $this->tenant = Tenant::factory()->create();
    $this->admin = Admin::factory()->create(['enabled' => true]);
});

afterEach(function () {
    app(TenantManager::class)->clear();
});

it('registers an export permission for the module', function () {
    $provider = app()->getProvider(ModuleServiceProvider::class);

    $permissions = (new ReflectionMethod($provider, 'permissions'))->invoke($provider);

    expect($permissions)->toHaveKey('call-detail-records.export');
});

it('resolves the export ability for the exportCdrs action', function () {
    $abilities = app(LivewireActionPermissions::class)->abilitiesFor(CdrList::class, 'exportCdrs');

    expect($abilities)->toBe([['call-detail-records.export']]);
});

it('downloads the visible call detail records as a CSV file', function () {
    Cdr::factory()->create([
        'tenant_id' => $this->tenant->id,
        'caller_id' => '1001',
        'caller_id_name' => 'Alice',
        'destination' => '2002',
        'leg' => 'a',
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->call('exportCdrs')
        ->assertFileDownloaded('call-detail-records-'.now()->format('Y-m-d').'.csv');

    $content = cdrExportContent($component);

    expect($content)
        ->toContain('Caller ID')
        ->toContain('Caller Name')
        ->toContain('1001')
        ->toContain('Alice')
        ->toContain('2002');
});

it('keeps another tenant\'s records out of the export', function () {
    $otherTenant = Tenant::factory()->create();

    Cdr::factory()->create(['tenant_id' => $this->tenant->id, 'destination' => 'own-destination']);
    Cdr::factory()->create(['tenant_id' => $otherTenant->id, 'destination' => 'foreign-destination']);

    $user = grantTenantUserPermissions($this->tenant, ['call-detail-records.view', 'call-detail-records.export']);

    $component = Livewire::actingAs($user, 'web')
        ->test(CdrList::class)
        ->call('exportCdrs');

    $content = cdrExportContent($component);

    expect($content)
        ->toContain('own-destination')
        ->not->toContain('foreign-destination');
});

it('honours the leg filter when exporting', function () {
    Cdr::factory()->create(['tenant_id' => $this->tenant->id, 'leg' => 'a', 'destination' => 'a-leg-destination']);
    Cdr::factory()->create(['tenant_id' => $this->tenant->id, 'leg' => 'b', 'destination' => 'b-leg-destination']);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->set('legFilter', 'b')
        ->call('exportCdrs');

    $content = cdrExportContent($component);

    expect($content)
        ->toContain('b-leg-destination')
        ->not->toContain('a-leg-destination');
});

it('neutralizes spreadsheet formula characters in exported values', function () {
    // A caller display name is attacker-controlled, so it can carry a
    // formula that a spreadsheet would otherwise execute on open.
    Cdr::factory()->create([
        'tenant_id' => $this->tenant->id,
        'caller_id_name' => '=SUM(A1:A9)',
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(CdrList::class)
        ->call('exportCdrs');

    $content = cdrExportContent($component);

    // The cell carries a leading apostrophe, so the spreadsheet reads it as
    // text. The raw "=SUM" form must not survive as a cell of its own.
    expect($content)
        ->toContain(",'=SUM(A1:A9)")
        ->not->toContain(',=SUM(A1:A9)');
});

it('shows the export button only to a viewer who also holds the export permission', function () {
    $viewer = grantTenantUserPermissions($this->tenant, ['call-detail-records.view']);

    Livewire::actingAs($viewer, 'web')
        ->test(CdrList::class)
        ->assertDontSee(__('admin.cdr_export'));

    $exporter = grantTenantUserPermissions($this->tenant, ['call-detail-records.view', 'call-detail-records.export']);

    Livewire::actingAs($exporter, 'web')
        ->test(CdrList::class)
        ->assertSee(__('admin.cdr_export'));
});
