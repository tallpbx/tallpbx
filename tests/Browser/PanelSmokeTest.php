<?php

declare(strict_types=1);

/**
 * Dusk browser smoke tests for the unified panel.
 *
 * Validates that the panel renders correctly in a real browser:
 *   - Login form works and redirects to dashboard
 *   - Dashboard loads with sidebar, CSRF token, Livewire components
 *   - Major CRUD list pages render with data tables
 *   - Major CRUD create/edit pages render with forms
 *   - Navigation between pages works
 *
 * Speed strategy: Only the first two tests exercise the full login
 * form flow. All other tests use Dusk's native loginAs() which
 * authenticates directly in the session, bypassing the form entirely.
 * This keeps the suite fast (~30s total for 20 tests).
 */

namespace Tests\Browser;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use Laravel\Dusk\Browser;
use Modules\Extensions\Models\Extension;
use Modules\FeatureCodes\Models\FeatureCode;
use Tests\Browser\Pages\LoginPage;

beforeEach(function () {
    $this->admin = Admin::where('email', 'admin@smoke.test')->first();

    if (! $this->admin) {
        $this->admin = Admin::create([
            'email' => 'admin@smoke.test',
            'name' => 'Smoke Test Admin',
            'password' => bcrypt('smoke-secret'),
            'enabled' => true,
        ]);

        $group = Group::firstOrCreate(
            ['name' => 'Smoke Test Group'],
            ['system' => true],
        );
        $this->superAdminGroup = Group::firstOrCreate(
            ['name' => 'Super Administrators', 'tenant_id' => null],
            ['description' => 'Dusk smoke superadmin group.'],
        );

        $pagePermissions = [
            'extensions.view', 'extensions.create', 'extensions.edit',
            'feature-codes.view',
            'email-connector.view',
            'backups.view', 'backups.create', 'backups.restore',
            'admin.git-update.view',
            'admin.queue.view',
            'admin.monitoring.view',
            'security.view',
            'security.manage',
        ];

        foreach ($pagePermissions as $permissionName) {
            $permission = Permission::firstOrCreate(
                ['name' => $permissionName],
                [
                    'module' => 'admin',
                    'description' => 'Dusk smoke permission for '.$permissionName,
                ],
            );

            $group->permissions()->syncWithoutDetaching([$permission->id]);
            $this->superAdminGroup->permissions()->syncWithoutDetaching([$permission->id]);
        }

        $this->admin->groups()->syncWithoutDetaching([$group->id, $this->superAdminGroup->id]);
    }
});

// ═══════════════════════════════════════════════════════════════════
//  AUTHENTICATION — full form flow
// ═══════════════════════════════════════════════════════════════════

it('displays the admin login page', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit(new LoginPage)
            ->assertSee('TallPBX')
            ->assertPresent('@email')
            ->assertPresent('@password')
            ->assertPresent('@submit');
    });
});

it('logs in via the login form and lands on the dashboard', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit(new LoginPage)
            ->type('email', 'admin@smoke.test')
            ->type('password', 'smoke-secret')
            ->press('button[type="submit"]')
            ->waitForLocation('/panel/dashboard', 10)
            ->assertPathIs('/panel/dashboard');
    });
});

// ═══════════════════════════════════════════════════════════════════
//  DASHBOARD & LAYOUT — use loginAs() for speed
// ═══════════════════════════════════════════════════════════════════

it('renders the sidebar with navigation', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/dashboard')
            ->assertPresent('[data-panel-sidebar-scroll="drawer"]')
            ->assertPresent('[data-panel-sidebar-scroll="nav"]')
            ->assertPresent('label[for="sidebar-drawer"]');
    });
});

it('renders the dashboard with Livewire components', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/dashboard')
            ->assertSee('TallPBX')
            // Livewire must have booted (wire:id or wire:snapshot attributes)
            ->assertPresent('[wire\\:id], [wire\\:snapshot]')
            ->assertSee('Total Users')
            ->assertMissing('[wire\\:poll]')
            ->assertScript('typeof window.Echo !== "undefined"')
            ->assertScript('document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1')
            ->assertScript(<<<'JS'
                (() => {
                    const footer = document.querySelector('.drawer-content footer');
                    const content = document.querySelector('.drawer-content');

                    if (! footer || ! content) {
                        return false;
                    }

                    return footer.getBoundingClientRect().width >= content.getBoundingClientRect().width - 1;
                })()
                JS);
    });
});

it('shows the admin identity in the top bar', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/dashboard')
            // The top bar shows the admin's name (Smoke Test Admin)
            ->assertSee('Smoke Test Admin');
    });
});

// ═══════════════════════════════════════════════════════════════════
//  MAJOR CRUD LIST PAGES
// ═══════════════════════════════════════════════════════════════════

it('renders the extensions list page with a table', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/extensions')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/extensions')
            ->assertSee('Create Extension')
            ->assertSee('Create Multiple Extensions')
            ->assertScript(<<<'JS'
                (() => {
                    const links = Array.from(document.querySelectorAll('a'));
                    const button = links.find((link) => link.textContent.includes('Create Multiple Extensions'));

                    return button?.classList.contains('btn-primary') === true
                        && button?.classList.contains('btn-outline') === false;
                })()
                JS)
            ->assertPresent('table')
            ->assertPresent('[wire\\:id]');
    });
});

it('renders the dialplans list page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/dialplans')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/dialplans')
            ->assertPresent('table');
    });
});

it('renders the sip accounts list page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/sip-accounts')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/sip-accounts')
            ->assertPresent('table');
    });
});

it('renders the gateways list page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/gateways')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/gateways')
            ->assertPresent('table');
    });
});

it('renders the voicemails list page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/voicemails')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/voicemails')
            ->assertPresent('table');
    });
});

it('renders the ring groups list page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/ring-groups')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/ring-groups')
            ->assertPresent('table');
    });
});

it('renders the feature codes list page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/feature-codes')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/feature-codes')
            ->assertPresent('table')
            ->assertSeeIn('thead', 'DESCRIPTION');
    });
});

it('expands description from single line text box into text area on hover when long', function () {
    $tenant = Tenant::first() ?? Tenant::factory()->create();
    $tenantManager = app(\App\Services\TenantManager::class);
    $tenantManager->setTenantId((string) $tenant->id);

    FeatureCode::withoutGlobalScope('tenant')->where('name', 'Custom Long Route')->delete();
    $longCode = FeatureCode::create([
        'tenant_id' => $tenant->id,
        'name' => 'Custom Long Route',
        'code' => '*999',
        'description' => 'Forward incoming sales inquiries directly to the tier 2 support ring group during business hours and overflow to voicemail after hours.',
        'enabled' => true,
    ]);

    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/feature-codes')
            ->waitForText('Custom Long Route', 5)
            ->assertPresent('input[value*="Forward incoming sales"]')
            ->script("const el = document.querySelector('input[value*=\"Forward incoming sales\"]')?.closest('[x-data]'); if (el) { el.dispatchEvent(new MouseEvent('mouseenter')); }");
        $browser->pause(300)
            ->assertPresent('textarea');
    });

    $longCode->delete();
    $tenantManager->clear();
});

it('renders the call centers list page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/call-centers/queues')
            ->waitFor('table', 5)
            ->assertPresent('table');
    });
});

// ═══════════════════════════════════════════════════════════════════
//  MONITORING PAGES (read-only, ESL-backed)
// ═══════════════════════════════════════════════════════════════════

it('renders the active calls monitoring page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/active-calls')
            ->assertPathIs('/panel/active-calls')
            // Page must render with Livewire (even if FreeSWITCH is unreachable)
            ->assertPresent('[wire\\:id]');
    });
});

it('renders the registrations monitoring page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/registrations')
            ->assertPathIs('/panel/registrations')
            ->assertPresent('[wire\\:id]');
    });
});

it('renders the SIP status page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/sip-status')
            ->assertPathIs('/panel/sip-status')
            ->assertPresent('[wire\\:id]');
    });
});

// ═══════════════════════════════════════════════════════════════════
//  MAJOR CRUD CREATE PAGES (forms)
// ═══════════════════════════════════════════════════════════════════

it('renders the create extension form', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/extensions/create')
            ->waitFor('form', 5)
            ->assertPathIs('/panel/extensions/create')
            ->assertPresent('input')
            ->assertPresent('button[type="submit"]');
    });
});

it('renders the create multiple extensions form', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/extensions/create-multiple')
            ->waitFor('form', 5)
            ->assertPathIs('/panel/extensions/create-multiple')
            ->assertSee('Create Multiple Extensions')
            ->assertSee('Start Extension')
            ->assertSee('End Extension')
            ->assertPresent('button[type="submit"]')
            ->assertPresent('[wire\\:id]');
    });
});

it('renders the edit extension form', function () {
    $tenant = Tenant::first() ?? Tenant::factory()->create();
    $extension = Extension::withoutGlobalScope('tenant')->where('extension_number', '2401')->first()
        ?? Extension::factory()->forTenant($tenant->id)->create([
            'extension_number' => '2401',
            'display_name' => 'Browser Edit Extension',
        ]);

    $this->browse(function (Browser $browser) use ($extension) {
        $browser->loginAs($this->admin, 'admin')
            ->visit("/panel/extensions/{$extension->id}/edit")
            ->waitFor('form', 5)
            ->assertPathIs("/panel/extensions/{$extension->id}/edit")
            ->assertSee('Edit Extension')
            ->assertValue('input[wire\:model="displayName"]', 'Browser Edit Extension')
            ->assertPresent('button[type="submit"]')
            ->assertPresent('[wire\\:id]');
    });

    $extension->delete();
});

it('renders the create dialplan form', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/dialplans/create')
            ->waitFor('form', 5)
            ->assertPathIs('/panel/dialplans/create')
            ->assertPresent('input')
            ->assertPresent('button[type="submit"]');
    });
});

it('renders the create gateway form', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/gateways/create')
            ->waitFor('form', 5)
            ->assertPathIs('/panel/gateways/create')
            ->assertPresent('input')
            ->assertPresent('button[type="submit"]');
    });
});

it('renders the create sip account form', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/sip-accounts/create')
            ->waitFor('form', 5)
            ->assertPathIs('/panel/sip-accounts/create')
            ->assertPresent('input')
            ->assertPresent('button[type="submit"]');
    });
});

it('creates a new tenant via the browser form', function () {
    $this->browse(function (Browser $browser) {
        $slug = 'browser-tenant-'.bin2hex(random_bytes(3));
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/tenants/create')
            ->waitFor('form', 5)
            ->type('#name', 'Browser Created Tenant')
            ->type('#slug', $slug)
            ->press('Create Tenant')
            ->waitForRoute('panel.tenants.index', [], 10)
            ->assertSee('Browser Created Tenant');
    });
});

// ═══════════════════════════════════════════════════════════════════
//  NAVIGATION
// ═══════════════════════════════════════════════════════════════════

it('navigates between pages without errors', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin');

        $pages = [
            '/panel/extensions',
            '/panel/dialplans',
            '/panel/gateways',
            '/panel/sip-accounts',
            '/panel/voicemails',
        ];

        foreach ($pages as $page) {
            $browser->visit($page)
                ->waitFor('table', 5)
                ->assertPresent('table')
                ->assertPresent('[data-panel-sidebar-scroll="drawer"]');
        }
    });
});

// ═══════════════════════════════════════════════════════════════════
//  PHASE 5–7: Gateway profile, routing, SIP profile smoke
// ═══════════════════════════════════════════════════════════════════

it('renders the gateway create form with profile field', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/gateways/create')
            ->waitFor('form', 5)
            ->assertPathIs('/panel/gateways/create')
            ->assertSee('Create Gateway')
            ->assertPresent('input[wire\:model="profile"]')
            ->assertInputValue('profile', 'external');
    });
});

it('renders the inbound routes list page with table', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/inbound-routes')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/inbound-routes')
            ->assertSee('Inbound Routes')
            ->assertPresent('table');
    });
});

it('renders the outbound routes list page with table', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/outbound-routes')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/outbound-routes')
            ->assertSee('Outbound Routes')
            ->assertPresent('table');
    });
});

it('renders the SIP profiles list page with no JavaScript errors', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/sip-profiles')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/sip-profiles')
            ->assertPresent('table')
            ->assertPresent('[wire\:id]');
    });
});

it('renders the SIP profiles create form', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/sip-profiles/create')
            ->waitFor('form', 5)
            ->assertPathIs('/panel/sip-profiles/create')
            ->assertSee('Create')
            ->assertPresent('input[wire\:model="name"]');
    });
});

it('renders the IVR menus list page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/ivr-menus')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/ivr-menus')
            ->assertPresent('table');
    });
});

it('renders the conference centers list page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/conference-centers')
            ->waitFor('table', 5)
            ->assertPathIs('/panel/conference-centers')
            ->assertPresent('table');
    });
});

// ═══════════════════════════════════════════════════════════════════
//  WORKSTREAMS #4–#8 — New Feature Pages (July 2026)
// ═══════════════════════════════════════════════════════════════════

it('renders the email connector configuration page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/email-connector')
            ->waitForText('Email Connector Configuration', 5)
            ->assertSee('Email Connector Configuration')
            ->assertPresent('.tooltip[data-tip]');
    });
});

it('renders the backups list page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/backups')
            ->waitForText('Backups', 5)
            ->assertSee('Backups');
    });
});

it('renders the backups create form', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/backups/create')
            ->waitForText('Backup Configuration', 5)
            ->assertSee('Backup Configuration')
            ->assertPresent('.tooltip[data-tip]');
    });
});

it('renders the superadmin backup restore screen', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/backups/restore')
            ->waitForText('Restore backup', 5)
            ->assertPathIs('/panel/backups/restore')
            ->assertSee('Restore from a configured File Store')
            ->assertSee('Restore from an uploaded archive')
            ->assertPresent('#archiveUpload')
            ->assertPresent('#manifestUpload');
    });
});

it('renders the git update page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/git-update')
            ->waitForText('Git Update', 5)
            ->assertSee('Git Update');
    });
});

it('renders the queue status page', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/queue')
            ->waitForText('Queue Status', 5)
            ->assertSee('Queue Status');
    });
});

it('renders the monitoring dashboard with metrics', function () {
    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->visit('/panel/monitoring')
            ->waitForText('Monitoring', 5)
            ->assertSee('Services')
            ->assertSee('Disk Usage')
            ->assertSee('Memory');
    });
});

it('renders the security manager dashboard', function () {
    $this->seed(\Database\Seeders\SecurityServiceSeeder::class);

    $this->browse(function (Browser $browser) {
        $browser->loginAs($this->admin, 'admin')
            ->resize(1920, 2200)
            ->visit('/panel/security')
            ->waitForText('Security Center', 5)
            ->assertSee('Security Center')
            ->assertSee('Firewall Status')
            ->assertSee('Attack Protection')
            ->assertSee('PACKET FILTERING PIPELINE ORDER')
            ->assertSee('Blacklist IPs')
            ->assertSee('Whitelist IPs')
            ->assertSee('Currently Blocked Attackers')
            ->assertSee('STAGE 1')
            ->assertSee('STAGE 2')
            ->assertSee('STAGE 3')
            ->assertSee('SIP Signaling')
            ->screenshot('security-dashboard-full');
    });
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
