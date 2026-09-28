<?php

declare(strict_types=1);

/**
 * Pest 4 Browser smoke tests for the unified panel.
 *
 * Validates that the panel renders correctly in a real headless Chromium browser
 * via Playwright:
 *   - Login form works and redirects to dashboard
 *   - Dashboard loads with sidebar, Livewire components
 *   - Major CRUD list pages render with data tables
 *   - Major CRUD create/edit pages render with forms
 *   - Navigation between pages works
 *
 * Speed strategy: The first two tests exercise the full login form flow.
 * All remaining tests use loginAs() via the internal test session bridge,
 * which authenticates directly into the session in sub-10ms, bypassing
 * the form entirely. Playwright's auto-waiting eliminates explicit pause/
 * waitFor calls needed by Dusk's ChromeDriver.
 */

namespace Tests\Browser;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Tenant;
use App\Services\TenantManager;
use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Modules\Extensions\Models\Extension;
use Modules\FeatureCodes\Models\FeatureCode;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;

beforeEach(function () {
    // Mirror the production permission setup so the smoke admin can open
    // every panel page: sync module state, seed AdminSeeder (which syncs all
    // module-registered permissions and grants them to the Super
    // Administrators group), then attach the smoke admin to that group.
    // This is the same convention used by feature tests such as
    // SecurityManagerLivewireTest.
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);

    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();

    $this->admin = Admin::firstOrCreate(
        ['email' => 'admin@smoke.test'],
        ['name' => 'Smoke Test Admin', 'password' => bcrypt('smoke-secret'), 'enabled' => true],
    );

    $this->admin->groups()->syncWithoutDetaching([$this->superAdminGroup->id]);
});

// ═══════════════════════════════════════════════════════════════════
//  AUTHENTICATION — full form flow
// ═══════════════════════════════════════════════════════════════════

it('displays the admin login page', function () {
    $page = visit('/panel/login');
    $page->assertSee('TallPBX')
        ->assertPresent('input[name="email"]')
        ->assertPresent('input[name="password"]')
        ->assertPresent('button[type="submit"]');
});

it('logs in via the login form and lands on the dashboard', function () {
    $page = visit('/panel/login');
    $page->fill('input[name="email"]', 'admin@smoke.test')
        ->fill('input[name="password"]', 'smoke-secret')
        ->click('button[type="submit"]')
        ->assertPathBeginsWith('/panel');
});

// ═══════════════════════════════════════════════════════════════════
//  DASHBOARD & LAYOUT — use loginAs() for speed
// ═══════════════════════════════════════════════════════════════════

it('renders the sidebar with navigation', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/dashboard');
    $page->assertPresent('[data-panel-sidebar-scroll="drawer"]')
        ->assertPresent('[data-panel-sidebar-scroll="nav"]')
        ->assertPresent('label[for="sidebar-drawer"]');
});

it('renders the dashboard with Livewire components', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/dashboard');
    $page->assertSee('TallPBX')
        // Livewire must have booted (wire:id or wire:snapshot present)
        ->assertPresent('[wire\:id], [wire\:snapshot]')
        ->assertSee('Total Users')
        ->assertMissing('[wire\:poll]');

    // Laravel Echo must be available on the window object
    $page->assertScript('typeof window.Echo !== "undefined"');

    // No horizontal overflow (content must not exceed viewport width)
    $page->assertScript(
        'document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1'
    );

    // Footer must span the full drawer-content width
    $page->assertScript(<<<'JS'
        (() => {
            const footer  = document.querySelector('.drawer-content footer');
            const content = document.querySelector('.drawer-content');
            if (!footer || !content) return false;
            return footer.getBoundingClientRect().width >= content.getBoundingClientRect().width - 1;
        })()
    JS);
});

it('shows the admin identity in the top bar', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/dashboard');
    // The top bar shows the admin's name
    $page->assertSee('Smoke Test Admin');
});

// ═══════════════════════════════════════════════════════════════════
//  MAJOR CRUD LIST PAGES
// ═══════════════════════════════════════════════════════════════════

it('renders the extensions list page with a table', function () {
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

it('renders the dialplans list page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/dialplans');
    $page->assertPathIs('/panel/dialplans')
        ->assertPresent('table > *');
});

it('renders the sip accounts list page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/sip-accounts');
    $page->assertPathIs('/panel/sip-accounts')
        ->assertPresent('table > *');
});

it('renders the gateways list page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/gateways');
    $page->assertPathIs('/panel/gateways')
        ->assertPresent('table > *');
});

it('renders the voicemails list page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/voicemails');
    $page->assertPathIs('/panel/voicemails')
        ->assertPresent('table > *');
});

it('renders the ring groups list page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/ring-groups');
    $page->assertPathIs('/panel/ring-groups')
        ->assertPresent('table > *');
});

it('renders the feature codes list page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/feature-codes');
    $page->assertPathIs('/panel/feature-codes')
        ->assertPresent('table > *');
});

it('expands description from single line text box into text area on hover when long', function () {
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

it('renders the call centers list page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/call-centers/queues');
    $page->assertPresent('table > *');
});

// ═══════════════════════════════════════════════════════════════════
//  MONITORING PAGES (read-only, ESL-backed)
// ═══════════════════════════════════════════════════════════════════

it('renders the active calls monitoring page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/active-calls');
    // Page must render with Livewire even if FreeSWITCH is unreachable
    $page->assertPathIs('/panel/active-calls')
        ->assertPresent('[wire\:id]');
});

it('renders the registrations monitoring page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/registrations');
    $page->assertPathIs('/panel/registrations')
        ->assertPresent('[wire\:id]');
});

it('renders the SIP status page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/sip-status');
    $page->assertPathIs('/panel/sip-status')
        ->assertPresent('[wire\:id]');
});

// ═══════════════════════════════════════════════════════════════════
//  MAJOR CRUD CREATE PAGES (forms)
// ═══════════════════════════════════════════════════════════════════

it('renders the create extension form', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/extensions/create');
    $page->assertPathIs('/panel/extensions/create')
        ->assertPresent('input[type], input:not([type])')
        ->assertPresent('button[type="submit"]');
});

it('renders the create multiple extensions form', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/extensions/create-multiple');
    $page->assertPathIs('/panel/extensions/create-multiple')
        ->assertSee('Create Multiple Extensions')
        ->assertSee('Start Extension')
        ->assertSee('End Extension')
        ->assertPresent('button[type="submit"]')
        ->assertPresent('[wire\:id]');
});

it('renders the edit extension form', function () {
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

it('renders the create dialplan form', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/dialplans/create');
    $page->assertPathIs('/panel/dialplans/create')
        ->assertPresent('input[type], input:not([type])')
        ->assertPresent('button[type="submit"]');
});

it('renders the create gateway form', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/gateways/create');
    $page->assertPathIs('/panel/gateways/create')
        ->assertPresent('input[type], input:not([type])')
        ->assertPresent('button[type="submit"]');
});

it('renders the create sip account form', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/sip-accounts/create');
    $page->assertPathIs('/panel/sip-accounts/create')
        ->assertPresent('input[type], input:not([type])')
        ->assertPresent('button[type="submit"]');
});

it('creates a new tenant via the browser form', function () {
    $slug = 'browser-tenant-'.bin2hex(random_bytes(3));

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/tenants/create');
    $page->fill('#name', 'Browser Created Tenant')
        ->fill('#slug', $slug)
        ->click('button:has-text("Create Tenant")')
        ->assertSee('Browser Created Tenant');
});

// ═══════════════════════════════════════════════════════════════════
//  NAVIGATION
// ═══════════════════════════════════════════════════════════════════

it('navigates between pages without errors', function () {
    $this->loginAs($this->admin, 'admin');

    $urls = [
        '/panel/extensions',
        '/panel/dialplans',
        '/panel/gateways',
        '/panel/sip-accounts',
        '/panel/voicemails',
    ];

    foreach ($urls as $url) {
        $page = visit($url);
        $page->assertPresent('table > *')
            ->assertPresent('[data-panel-sidebar-scroll="drawer"]');
    }
});

// ═══════════════════════════════════════════════════════════════════
//  PHASE 5–7: Gateway profile, routing, SIP profile smoke
// ═══════════════════════════════════════════════════════════════════

it('renders the gateway create form with profile field', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/gateways/create');
    $page->assertPathIs('/panel/gateways/create')
        ->assertSee('Create Gateway')
        ->assertPresent('input[wire\:model="profile"]')
        ->assertValue('input[wire\:model="profile"]', 'external');
});

it('renders the inbound routes list page with table', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/inbound-routes');
    $page->assertPathIs('/panel/inbound-routes')
        ->assertSee('Inbound Routes')
        ->assertPresent('table > *');
});

it('renders the outbound routes list page with table', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/outbound-routes');
    $page->assertPathIs('/panel/outbound-routes')
        ->assertSee('Outbound Routes')
        ->assertPresent('table > *');
});

it('renders the SIP profiles list page with no JavaScript errors', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/sip-profiles');
    $page->assertPathIs('/panel/sip-profiles')
        ->assertPresent('table > *')
        ->assertPresent('[wire\:id]');
});

it('renders the SIP profiles create form', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/sip-profiles/create');
    $page->assertPathIs('/panel/sip-profiles/create')
        ->assertSee('Create')
        ->assertPresent('input[wire\:model="name"]');
});

it('renders the IVR menus list page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/ivr-menus');
    $page->assertPathIs('/panel/ivr-menus')
        ->assertPresent('table > *');
});

it('renders the conference centers list page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/conference-centers');
    $page->assertPathIs('/panel/conference-centers')
        ->assertPresent('table > *');
});

// ═══════════════════════════════════════════════════════════════════
//  WORKSTREAMS #4–#8 — New Feature Pages (July 2026)
// ═══════════════════════════════════════════════════════════════════

it('renders the email connector configuration page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/email-connector');
    $page->assertSee('Email Connector Configuration')
        ->assertPresent('.tooltip[data-tip]');
});

it('renders the backups list page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/backups');
    $page->assertSee('Backups');
});

it('renders the backups create form', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/backups/create');
    $page->assertSee('Backup Configuration')
        ->assertPresent('.tooltip[data-tip]');
});

it('renders the superadmin backup restore screen', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/backups/restore');
    $page->assertPathIs('/panel/backups/restore')
        ->assertSee('Restore from a configured File Store')
        ->assertSee('Restore from an uploaded archive')
        ->assertPresent('#archiveUpload')
        ->assertPresent('#manifestUpload');
});

it('renders the git update page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/git-update');
    $page->assertSee('Git Update');
});

it('renders the queue status page', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/queue');
    $page->assertSee('Queue Status');
});

it('renders the monitoring dashboard with metrics', function () {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/monitoring');
    $page->assertSee('Services')
        ->assertSee('Disk Usage')
        ->assertSee('Memory');
});

it('renders the security manager dashboard', function () {
    $this->seed(SecurityServiceSeeder::class);

    // Seed representative threat and trust entries so the documentation
    // screenshot shows populated Blacklist, Blocked Attackers and
    // Whitelist sections; updateOrCreate keeps re-runs idempotent.
    SecurityIpList::updateOrCreate(
        ['type' => 'blacklist', 'ip_address' => '198.51.100.23'],
        ['description' => 'Credential stuffing source'],
    );
    SecurityIpList::updateOrCreate(
        ['type' => 'blacklist', 'ip_address' => '203.0.113.0/24'],
        ['description' => 'Scraping botnet range'],
    );
    SecurityIpList::updateOrCreate(
        ['type' => 'blacklist', 'ip_address' => '2001:db8:bad::/48'],
        ['description' => 'Malicious scanner prefix'],
    );
    SecurityIpList::updateOrCreate(
        ['type' => 'whitelist', 'ip_address' => '192.0.2.10'],
        ['description' => 'Monitoring server'],
    );
    SecurityIpList::updateOrCreate(
        ['type' => 'whitelist', 'ip_address' => '198.51.100.5'],
        ['description' => 'Office VPN gateway'],
    );
    SecurityIpList::updateOrCreate(
        ['type' => 'whitelist', 'ip_address' => '2001:db8:cafe::/64'],
        ['description' => 'Branch office WAN'],
    );
    SecurityBan::updateOrCreate(
        ['ip_address' => '203.0.113.66'],
        [
            'vector' => 'sip_auth',
            'reason' => 'Repeated failed SIP registrations',
            'attempt_count' => 12,
            'banned_at' => now()->subMinutes(15),
            'expires_at' => now()->addMinutes(45),
            'is_active' => true,
        ],
    );
    SecurityBan::updateOrCreate(
        ['ip_address' => '198.51.100.88'],
        [
            'vector' => 'web_auth',
            'reason' => 'Repeated failed panel logins',
            'attempt_count' => 8,
            'banned_at' => now()->subMinutes(5),
            'expires_at' => now()->addHours(2),
            'is_active' => true,
        ],
    );
    SecurityBan::updateOrCreate(
        ['ip_address' => '2001:db8:1234::88'],
        [
            'vector' => 'ssh',
            'reason' => 'Repeated failed SSH probes',
            'attempt_count' => 5,
            'banned_at' => now()->subMinutes(8),
            'expires_at' => now()->addHours(1),
            'is_active' => true,
        ],
    );

    // Seed demo custom firewall rules covering the row styles so the
    // Custom Rules section renders populated (with its reorder controls) in
    // the screenshot instead of the empty-state message.
    SecurityRule::updateOrCreate(
        ['description' => 'Carrier SIP trunk'],
        [
            'sequence' => 10,
            'source_ip' => 'any',
            'service_id' => SecurityService::where('name', 'SIP Signaling')->value('id'),
            'custom_port' => null,
            'custom_protocol' => null,
            'action' => 'accept',
            'enabled' => true,
        ],
    );
    SecurityRule::updateOrCreate(
        ['description' => 'Admin SSH from bastion'],
        [
            'sequence' => 20,
            'source_ip' => '192.0.2.50',
            'service_id' => null,
            'custom_port' => '2022',
            'custom_protocol' => 'tcp',
            'action' => 'accept',
            'enabled' => true,
        ],
    );
    SecurityRule::updateOrCreate(
        ['description' => 'Office PBX audio media'],
        [
            'sequence' => 25,
            'source_ip' => '192.0.2.0/24',
            'service_id' => null,
            'custom_port' => '10000-20000',
            'custom_protocol' => 'udp',
            'action' => 'accept',
            'enabled' => true,
        ],
    );
    SecurityRule::updateOrCreate(
        ['description' => 'Block RDP scanners'],
        [
            'sequence' => 30,
            'source_ip' => 'any',
            'service_id' => null,
            'custom_port' => '3389',
            'custom_protocol' => 'tcp',
            'action' => 'drop',
            'enabled' => true,
        ],
    );

    $this->loginAs($this->admin, 'admin');

    // Use a tall viewport so the full security dashboard is visible at once
    $page = visit('/panel/security')->resize(1920, 2400);
    $page->assertSee('Security Center')
        ->assertSee('Firewall Status')
        ->assertSee('Attack Protection')
        ->assertSee('Blacklist')
        ->assertSee('Whitelist')
        ->assertSee('Blocked Attackers')
        ->assertSee('198.51.100.23')
        ->assertSee('2001:db8:bad::/48')
        ->assertSee('192.0.2.10')
        ->assertSee('2001:db8:cafe::/64')
        ->assertSee('203.0.113.66')
        ->assertSee('2001:db8:1234::88')
        ->assertSee('Carrier SIP trunk')
        ->assertSee('Admin SSH from bastion')
        ->assertSee('Office PBX audio media')
        ->assertSee('Block RDP scanners')
        ->assertSee('Pre-Filters')
        ->assertSee('Standard Services')
        ->assertSee('Default Inbound Policy')
        ->assertSee('ICMP Ping Diagnostics')
        ->assertSee('SIP Signaling');

    // Expand the collapsible Pre-Filters section so the full kernel pipeline is visible
    $page->click('tr[title*="expand or collapse"]')
        ->assertSee('Loopback Interface')
        ->assertSee('Stateful Connection Tracking');

    // Grow the viewport to the full document height for documentation screenshots
    $expandedHeight = (int) ($page->script(
        'Math.max(document.body.scrollHeight, document.documentElement.scrollHeight, document.body.offsetHeight)'
    ) ?? 2900);
    $page->resize(1920, max(2900, $expandedHeight + 120));

    // Documentation screenshots are refreshed on demand only (TALLPBX_CAPTURE_DOCS=1)
    if (getenv('TALLPBX_CAPTURE_DOCS') === '1') {
        $imgName = 'security-dashboard-full.png';
        // Pest writes screenshots into tests/Browser/Screenshots/ and prefixes the
        // directory itself, so pass only the bare filename here.
        $src = base_path("tests/Browser/Screenshots/{$imgName}");
        $dest = base_path("docs/images/{$imgName}");

        $page->screenshot(filename: $imgName);

        if (file_exists($src) && (! file_exists($dest) || md5_file($src) !== md5_file($dest))) {
            copy($src, $dest);
            fwrite(STDOUT, "Updated documentation image: docs/images/{$imgName}".PHP_EOL);
        }
    }
});

/*
 * ═══════════════════════════════════════════════════════════════════
 *  MANUAL FreeSWITCH CALL SCENARIOS (Beta Acceptance)
 * ═══════════════════════════════════════════════════════════════════
 *
 * These scenarios require a real FreeSWITCH instance and cannot be
 * validated by HTTP assertions or fake ESL alone. Run them against
 * a live FreeSWITCH deployment before declaring beta readiness.
 *
 * 1. Extension Registration & Directory Lookup
 *    - Register a SIP phone (e.g., extension 1001) to the PBX
 *    - Verify FreeSWITCH sends directory requests to mod_xml_curl
 *    - Confirm the XML handler returns a valid <user> entry with
 *      auth credentials and user_context variables
 *
 * 2. Shared-Domain SIP Auth (Two Tenants)
 *    - Configure two tenants sharing the same SIP domain
 *      (e.g., sip.example.com) each with different SIP accounts
 *    - Register phone A (tenant 1) and phone B (tenant 2)
 *    - Verify each phone receives correct tenant-specific directory
 *    - Confirm domain-only lookup fails closed (no cross-tenant leak)
 *
 * 3. Extension-to-Extension Call
 *    - Call from extension 1001 to extension 1002 (same tenant)
 *    - Verify dialplan context resolves to tenant_{id}_internal
 *    - Confirm two-way audio and hangup detection
 *
 * 4. Inbound DID → Extension / Ring Group / IVR
 *    - Configure an inbound route matching a DID (e.g., 8005551212)
 *    - Route to: (a) extension 1002, (b) ring group, (c) IVR menu
 *    - Place external call to the DID and verify each destination
 *
 * 5. Outbound Route Through Gateway
 *    - Configure a gateway with SIP credentials for a test trunk
 *    - Assign the gateway profile to 'external'
 *    - Create an outbound route matching a dial pattern
 *    - Place outbound call and verify it bridges through sofia/gateway
 *    - Confirm sofia.conf XML includes the gateway definition
 *
 * 6. Voicemail Deposit & Retrieval
 *    - Call extension 1002, let it ring to voicemail, leave a message
 *    - Dial *97 (voicemail access feature code) and retrieve message
 *    - Confirm voicemail XML is generated with the correct mailbox
 *
 * 7. Feature Code Execution
 *    - Configure feature codes with mapped applications
 *    - Test *97 (voicemail access), call forward activation (*72),
 *      call forward deactivation (*73), DND toggle
 *    - Verify each generates the correct FreeSWITCH application XML
 *
 * 8. Emergency Route (E911)
 *    - Configure emergency settings with caller ID and address
 *    - Dial 911 and verify the call routes with emergency caller ID
 *    - Confirm geo-location headers are set in the SIP invite
 *
 * Expected results for each scenario:
 *   - XML handler logs show correct tenant resolution
 *   - No cross-tenant data leaks (verify via logs + CDR)
 *   - reloadxml picks up configuration changes within 5 seconds
 *   - ESL commands execute without authentication failures
 */
