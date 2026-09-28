<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\Tenant;
use Livewire\Livewire;
use Modules\Devices\Models\Device;
use Modules\Extensions\Models\Extension;
use Modules\Provision\Livewire\TemplateEdit;
use Modules\Provision\Livewire\TemplateList;
use Modules\Provision\Models\ProvisionTemplate;
use Modules\SipAccounts\Models\SipAccount;

use function Pest\Laravel\get;

beforeEach(function () {
    $this->admin = Admin::factory()->create(['enabled' => true]);
    // The endpoint is disabled by default (FusionPBX parity); these tests
    // exercise the rendering path, so the switch is turned on here.
    config(['provisioning.enabled' => true]);
});

// ─── Admin CRUD tests ──────────────────────────────────────────────

it('renders the template list component', function () {
    ProvisionTemplate::factory()->count(2)->create();

    Livewire::actingAs($this->admin, 'admin')
        ->test(TemplateList::class)
        ->assertOk()
        ->assertSee('Provisioning Templates')
        ->assertViewHas('templates', fn ($t) => $t->count() === 2);
});

it('renders the create form', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TemplateEdit::class)->assertOk()->assertSee('Create');
});

it('creates a template', function () {
    $tenant = Tenant::factory()->create();
    Livewire::actingAs($this->admin, 'admin')
        ->test(TemplateEdit::class)
        ->set('tenantId', $tenant->id)->set('name', 'Grandstream Default')
        ->set('vendor', 'grandstream')->set('filePath', 'grandstream/default.blade.php')
        ->call('save')->assertRedirect(route('panel.provision.templates.index'));
    $this->assertDatabaseHas('provision_templates', ['name' => 'Grandstream Default']);
});

it('updates a template', function () {
    $template = ProvisionTemplate::factory()->create(['name' => 'Old Template']);
    Livewire::actingAs($this->admin, 'admin')
        ->test(TemplateEdit::class, ['templateId' => $template->id])
        ->set('name', 'Updated Template')->call('save')
        ->assertRedirect(route('panel.provision.templates.index'));
    $this->assertDatabaseHas('provision_templates', ['id' => $template->id, 'name' => 'Updated Template']);
});

it('deletes a template', function () {
    $template = ProvisionTemplate::factory()->create();
    Livewire::actingAs($this->admin, 'admin')
        ->test(TemplateList::class)
        ->call('confirmTemplateDeletion', $template->id)
        ->assertSet('pendingDeletionId', $template->id)
        ->call('deleteTemplate')
        ->assertSet('operationalMessage', 'Template deleted.')
        ->assertDispatched('template-deleted');
    $this->assertModelMissing($template);
});

it('opens the shared confirmation modal before deleting a template', function (): void {
    $template = ProvisionTemplate::factory()->create(['name' => 'Yealink T46S']);

    Livewire::actingAs($this->admin, 'admin')
        ->test(TemplateList::class)
        ->call('confirmTemplateDeletion', $template->id)
        ->assertSet('pendingDeletionId', $template->id)
        ->assertSet('pendingDeletionName', 'Yealink T46S')
        ->assertSee('Delete Template?');
});

it('validates name required', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TemplateEdit::class)->set('name', '')->call('save')
        ->assertHasErrors(['name' => 'required']);
});

it('shows empty state', function () {
    Livewire::actingAs($this->admin, 'admin')
        ->test(TemplateList::class)->assertSee('No templates found');
});

// ─── Public provisioning endpoint tests ────────────────────────────

it('returns 404 for unknown MAC address', function () {
    get('/provision/00:11:22:33:44:55')
        ->assertNotFound();
});

it('serves provisioning config for a valid device', function () {
    $tenant = Tenant::factory()->create();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'grandstream',
        'model' => 'gxp2170',
        'mac_address' => '00:11:22:33:44:55',
        'template' => 'default',
        'enabled' => true,
    ]);
    ProvisionTemplate::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'grandstream',
        'model' => 'gxp2170',
        'file_path' => 'grandstream/gxp2170/default.blade.php',
        'enabled' => true,
    ]);

    get('/provision/00:11:22:33:44:55')
        ->assertOk()
        ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
});

// ─── Vendor template rendering tests ──────────────────────────────

it('renders grandstream template for a device with settings', function () {
    $tenant = Tenant::factory()->create();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'grandstream',
        'mac_address' => 'AA:BB:CC:DD:EE:01',
        'settings' => ['sip_username' => '1001', 'sip_password' => 'secret'],
        'enabled' => true,
    ]);

    get('/provision/AA:BB:CC:DD:EE:01')
        ->assertOk()->assertSee('sip_user_id = 1001')->assertSee('sip_password = secret');
});

it('renders snom template as XML', function () {
    $tenant = Tenant::factory()->create();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'snom',
        'mac_address' => 'AA:BB:CC:DD:EE:02',
        'settings' => ['sip_username' => '1002'],
        'enabled' => true,
    ]);

    get('/provision/AA:BB:CC:DD:EE:02')
        ->assertOk()->assertSee('<sip_user prime="0">1002</sip_user>', false);
});

it('renders yealink template with SIP credentials', function () {
    $tenant = Tenant::factory()->create();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'yealink',
        'mac_address' => 'AA:BB:CC:DD:EE:03',
        'settings' => ['sip_username' => '1003', 'display_name' => 'John Doe'],
        'enabled' => true,
    ]);

    get('/provision/AA:BB:CC:DD:EE:03')
        ->assertOk()->assertSee('account.1.user_name = 1003')->assertSee('account.1.display_name = John Doe');
});

it('renders cisco template with flat-profile XML', function () {
    $tenant = Tenant::factory()->create();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'cisco',
        'mac_address' => 'AA:BB:CC:DD:EE:04',
        'settings' => ['sip_username' => '1004'],
        'enabled' => true,
    ]);

    get('/provision/AA:BB:CC:DD:EE:04')
        ->assertOk()->assertSee('<User_ID1_>1004</User_ID1_>', false);
});

it('renders polycom template with XML config', function () {
    $tenant = Tenant::factory()->create();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'polycom',
        'mac_address' => 'AA:BB:CC:DD:EE:05',
        'settings' => ['sip_username' => '1005', 'display_name' => 'Jane'],
        'enabled' => true,
    ]);

    get('/provision/AA:BB:CC:DD:EE:05')
        ->assertOk()->assertSee('<reg.1.address>1005</reg.1.address>', false);
});

it('renders fanvil template with ini-style config', function () {
    $tenant = Tenant::factory()->create();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'fanvil',
        'mac_address' => 'AA:BB:CC:DD:EE:06',
        'settings' => ['sip_username' => '1006'],
        'enabled' => true,
    ]);

    get('/provision/AA:BB:CC:DD:EE:06')
        ->assertOk()->assertSee('username = 1006');
});

it('uses view-fallback for vendor default when no model-specific view exists', function () {
    $tenant = Tenant::factory()->create();
    $device = Device::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'aastra',
        'mac_address' => 'AA:BB:CC:DD:EE:07',
        'settings' => ['sip_username' => '1007'],
        'enabled' => true,
    ]);

    get('/provision/AA:BB:CC:DD:EE:07')
        ->assertOk()->assertSee('sip user name: 1007');
});

// ─── Real credential rendering ──────────────────────────────────

it('renders the linked sip account credentials for a device', function () {
    $tenant = Tenant::factory()->create();
    $extension = Extension::factory()->create([
        'tenant_id' => $tenant->id,
        'display_name' => 'Jane Doe',
    ]);
    $sipAccount = SipAccount::factory()->create([
        'tenant_id' => $tenant->id,
        'extension_id' => $extension->id,
        'auth_username' => 'line1001',
        'auth_password' => 'real-secret-password',
    ]);
    Device::factory()->create([
        'tenant_id' => $tenant->id,
        'vendor' => 'grandstream',
        'mac_address' => 'AA:BB:CC:DD:EE:0A',
        'sip_account_id' => $sipAccount->id,
        'enabled' => true,
    ]);

    get('/provision/AA:BB:CC:DD:EE:0A')
        ->assertOk()
        ->assertSee('sip_user_id = line1001')
        ->assertSee('sip_password = real-secret-password')
        ->assertSee('name = Jane Doe');
});

it('never renders credentials from a mismatched tenant sip account', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $sipAccountB = SipAccount::factory()->create([
        'tenant_id' => $tenantB->id,
        'auth_username' => 'b-line',
        'auth_password' => 'b-secret',
    ]);
    Device::factory()->create([
        'tenant_id' => $tenantA->id,
        'vendor' => 'grandstream',
        'mac_address' => 'AA:BB:CC:DD:EE:0B',
        'sip_account_id' => $sipAccountB->id,
        'enabled' => true,
    ]);

    get('/provision/AA:BB:CC:DD:EE:0B')
        ->assertOk()
        ->assertDontSee('b-secret')
        ->assertDontSee('b-line');
});
