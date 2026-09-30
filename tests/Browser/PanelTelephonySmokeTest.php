<?php

declare(strict_types=1);

/**
 * Pest 4 Browser smoke tests for core telephony CRUD and ESL monitoring pages.
 *
 * Validates:
 *   - Major telephony listing tables render (Extensions, Dialplans, SIP Accounts,
 *     Gateways, Voicemails, Ring Groups, Feature Codes, Call Centers)
 *   - Interactive table behaviors (hover description expansion on feature codes)
 *   - Read-only ESL-backed monitoring interfaces (Active Calls, Registrations, SIP Status)
 */

namespace Tests\Browser;

use App\Models\Tenant;
use App\Services\TenantManager;
use Modules\FeatureCodes\Models\FeatureCode;

beforeEach(function (): void {
    $this->setUpSmokeAdmin();
});

// ═══════════════════════════════════════════════════════════════════
//  MAJOR CRUD LIST PAGES
// ═══════════════════════════════════════════════════════════════════

it('renders the extensions list page with a table', function (): void {
    $this->skipWhenModuleUninstalled('extensions');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/extensions');
    $page->assertPathIs('/panel/extensions')
        ->assertSee('Create Extension')
        ->assertSee('Create Multiple Extensions')
        ->assertPresent('table > *')
        ->assertPresent('[wire\:id]');

    // "Create Multiple Extensions" link must carry btn-primary but not btn-outline
    $page->assertScript(<<<'JS'
        (() => {
            const links  = Array.from(document.querySelectorAll('a'));
            const button = links.find(l => l.textContent.includes('Create Multiple Extensions'));
            return button?.classList.contains('btn-primary') === true
                && button?.classList.contains('btn-outline') === false;
        })()
    JS);
});

it('renders the dialplans list page', function (): void {
    $this->skipWhenModuleUninstalled('dialplans');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/dialplans');
    $page->assertPathIs('/panel/dialplans')
        ->assertPresent('table > *');
});

it('renders the sip accounts list page', function (): void {
    $this->skipWhenModuleUninstalled('sip-accounts');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/sip-accounts');
    $page->assertPathIs('/panel/sip-accounts')
        ->assertPresent('table > *');
});

it('renders the gateways list page', function (): void {
    $this->skipWhenModuleUninstalled('gateways');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/gateways');
    $page->assertPathIs('/panel/gateways')
        ->assertPresent('table > *');
});

it('renders the voicemails list page', function (): void {
    $this->skipWhenModuleUninstalled('voicemails');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/voicemails');
    $page->assertPathIs('/panel/voicemails')
        ->assertPresent('table > *');
});

it('renders the ring groups list page', function (): void {
    $this->skipWhenModuleUninstalled('ring-groups');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/ring-groups');
    $page->assertPathIs('/panel/ring-groups')
        ->assertPresent('table > *');
});

it('renders the feature codes list page', function (): void {
    $this->skipWhenModuleUninstalled('feature-codes');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/feature-codes');
    $page->assertPathIs('/panel/feature-codes')
        ->assertPresent('table > *');
});

it('expands description from single line text box into text area on hover when long', function (): void {
    $this->skipWhenModuleUninstalled('feature-codes');

    $tenant = Tenant::first() ?? Tenant::factory()->create();
    $tenantManager = app(TenantManager::class);
    $tenantManager->setTenantId((string) $tenant->id);

    FeatureCode::withoutGlobalScope('tenant')->where('name', 'Custom Long Route')->delete();
    $longCode = FeatureCode::create([
        'tenant_id' => $tenant->id,
        'name' => 'Custom Long Route',
        'code' => '*999',
        'description' => 'Forward incoming sales inquiries directly to the tier 2 support ring group during business hours and overflow to voicemail after hours.',
        'enabled' => true,
    ]);

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/feature-codes');
    $page->assertSee('Custom Long Route')
        ->assertPresent('input[value*="Forward incoming sales"]');

    // Dispatch mouseenter on the Alpine component wrapping the truncated input
    $page->script(
        'const el = document.querySelector(\'input[value*="Forward incoming sales"]\')?'
        .'.closest(\'[x-data]\'); if (el) el.dispatchEvent(new MouseEvent("mouseenter"));'
    );

    // assertScript uses querySelector which correctly handles bare tag names
    $page->wait(0.5)
        ->assertScript('document.querySelectorAll("textarea").length > 0');

    $longCode->delete();
    $tenantManager->clear();
});

it('renders the call centers list page', function (): void {
    $this->skipWhenModuleUninstalled('call-centers');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/call-centers/queues');
    $page->assertPresent('table > *');
});

// ═══════════════════════════════════════════════════════════════════
//  MONITORING PAGES (read-only, ESL-backed)
// ═══════════════════════════════════════════════════════════════════

it('renders the active calls monitoring page', function (): void {
    $this->skipWhenModuleUninstalled('active-calls');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/active-calls');
    // Page must render with Livewire even if FreeSWITCH is unreachable
    $page->assertPathIs('/panel/active-calls')
        ->assertPresent('[wire\:id]');
});

it('renders the registrations monitoring page', function (): void {
    $this->skipWhenModuleUninstalled('registrations');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/registrations');
    $page->assertPathIs('/panel/registrations')
        ->assertPresent('[wire\:id]');
});

it('renders the SIP status page', function (): void {
    $this->skipWhenModuleUninstalled('sip-status');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/sip-status');
    $page->assertPathIs('/panel/sip-status')
        ->assertPresent('[wire\:id]');
});
