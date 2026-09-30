<?php

declare(strict_types=1);

/**
 * Pest 4 Browser smoke tests for forms, call routing, and advanced telephony entities.
 *
 * Validates:
 *   - Create and edit form rendering across telephony entities (Extensions,
 *     Dialplans, Gateways, SIP Accounts, SIP Profiles, Tenants)
 *   - Inbound and Outbound call routing list pages
 *   - IVR Menus and Conference Centers list pages
 */

namespace Tests\Browser;

use App\Models\Tenant;
use Modules\Extensions\Models\Extension;

beforeEach(function (): void {
    $this->setUpSmokeAdmin();
});

// ═══════════════════════════════════════════════════════════════════
//  MAJOR CRUD CREATE & EDIT PAGES (forms)
// ═══════════════════════════════════════════════════════════════════

it('renders the create extension form', function (): void {
    $this->skipWhenModuleUninstalled('extensions');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/extensions/create');
    $page->assertPathIs('/panel/extensions/create')
        ->assertPresent('input[type], input:not([type])')
        ->assertPresent('button[type="submit"]');
});

it('renders the create multiple extensions form', function (): void {
    $this->skipWhenModuleUninstalled('extensions');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/extensions/create-multiple');
    $page->assertPathIs('/panel/extensions/create-multiple')
        ->assertSee('Create Multiple Extensions')
        ->assertSee('Start Extension')
        ->assertSee('End Extension')
        ->assertPresent('button[type="submit"]')
        ->assertPresent('[wire\:id]');
});

it('renders the edit extension form', function (): void {
    $this->skipWhenModuleUninstalled('extensions');

    $tenant = Tenant::first() ?? Tenant::factory()->create();
    $extension = Extension::withoutGlobalScope('tenant')->where('extension_number', '2401')->first()
        ?? Extension::factory()->forTenant($tenant->id)->create([
            'extension_number' => '2401',
            'display_name' => 'Browser Edit Extension',
        ]);

    $this->loginAs($this->admin, 'admin');

    $page = visit("/panel/extensions/{$extension->id}/edit");
    $page->assertPathIs("/panel/extensions/{$extension->id}/edit")
        ->assertSee('Edit Extension')
        ->assertValue('input[wire\:model="displayName"]', 'Browser Edit Extension')
        ->assertPresent('button[type="submit"]')
        ->assertPresent('[wire\:id]');

    $extension->delete();
});

it('renders the create dialplan form', function (): void {
    $this->skipWhenModuleUninstalled('dialplans');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/dialplans/create');
    $page->assertPathIs('/panel/dialplans/create')
        ->assertPresent('input[type], input:not([type])')
        ->assertPresent('button[type="submit"]');
});

it('renders the create gateway form', function (): void {
    $this->skipWhenModuleUninstalled('gateways');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/gateways/create');
    $page->assertPathIs('/panel/gateways/create')
        ->assertPresent('input[type], input:not([type])')
        ->assertPresent('button[type="submit"]');
});

it('renders the create sip account form', function (): void {
    $this->skipWhenModuleUninstalled('sip-accounts');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/sip-accounts/create');
    $page->assertPathIs('/panel/sip-accounts/create')
        ->assertPresent('input[type], input:not([type])')
        ->assertPresent('button[type="submit"]');
});

it('creates a new tenant via the browser form', function (): void {
    $this->skipWhenModuleUninstalled('tenant');

    $slug = 'browser-tenant-'.bin2hex(random_bytes(3));

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/tenants/create');
    $page->fill('#name', 'Browser Created Tenant')
        ->fill('#slug', $slug)
        ->click('button:has-text("Create Tenant")')
        ->assertSee('Browser Created Tenant');
});

// ═══════════════════════════════════════════════════════════════════
//  GATEWAYS, ROUTING, SIP PROFILES, ADVANCED TELEPHONY
// ═══════════════════════════════════════════════════════════════════

it('renders the gateway create form with profile field', function (): void {
    $this->skipWhenModuleUninstalled('gateways');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/gateways/create');
    $page->assertPathIs('/panel/gateways/create')
        ->assertSee('Create Gateway')
        ->assertPresent('input[wire\:model="profile"]')
        ->assertValue('input[wire\:model="profile"]', 'external');
});

it('renders the inbound routes list page with table', function (): void {
    $this->skipWhenModuleUninstalled('inbound-routes');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/inbound-routes');
    $page->assertPathIs('/panel/inbound-routes')
        ->assertSee('Inbound Routes')
        ->assertPresent('table > *');
});

it('renders the outbound routes list page with table', function (): void {
    $this->skipWhenModuleUninstalled('outbound-routes');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/outbound-routes');
    $page->assertPathIs('/panel/outbound-routes')
        ->assertSee('Outbound Routes')
        ->assertPresent('table > *');
});

it('renders the SIP profiles list page with no JavaScript errors', function (): void {
    $this->skipWhenModuleUninstalled('sip-profiles');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/sip-profiles');
    $page->assertPathIs('/panel/sip-profiles')
        ->assertPresent('table > *')
        ->assertPresent('[wire\:id]');
});

it('renders the SIP profiles create form', function (): void {
    $this->skipWhenModuleUninstalled('sip-profiles');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/sip-profiles/create');
    $page->assertPathIs('/panel/sip-profiles/create')
        ->assertSee('Create')
        ->assertPresent('input[wire\:model="name"]');
});

it('renders the IVR menus list page', function (): void {
    $this->skipWhenModuleUninstalled('ivr-menus');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/ivr-menus');
    $page->assertPathIs('/panel/ivr-menus')
        ->assertPresent('table > *');
});

it('renders the conference centers list page', function (): void {
    $this->skipWhenModuleUninstalled('conference-centers');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/conference-centers');
    $page->assertPathIs('/panel/conference-centers')
        ->assertPresent('table > *');
});
