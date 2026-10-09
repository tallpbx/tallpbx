<?php

declare(strict_types=1);

/**
 * Pest 4 Browser smoke tests for operations, administration, and security.
 *
 * Validates:
 *   - Operations tools (Email connector, Git update, Queue status)
 *   - System backups (list, create, superadmin restore)
 *   - System monitoring metrics (Services, Disk, Memory)
 *   - Security Command Center (all evaluation tabs, pre-filters accordion,
 *     rules, attacker bans, and documentation capture hook)
 */

namespace Tests\Browser;

use Database\Seeders\SecurityServiceSeeder;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;

beforeEach(function (): void {
    $this->setUpSmokeAdmin();
});

// ═══════════════════════════════════════════════════════════════════
//  OPERATIONS, SYSTEM MAINTENANCE & BACKUPS
// ═══════════════════════════════════════════════════════════════════

it('renders the email connector configuration page', function (): void {
    $this->skipWhenModuleUninstalled('email-connector');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/email-connector');
    $page->assertSee('Email Connector Configuration')
        ->assertPresent('.tooltip[data-tip]');
});

it('renders the backups list page', function (): void {
    $this->skipWhenModuleUninstalled('backups');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/backups');
    $page->assertSee('Backups');
});

it('renders the backups create form', function (): void {
    $this->skipWhenModuleUninstalled('backups');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/backups/create');
    $page->assertSee('Backup Configuration')
        ->assertPresent('.tooltip[data-tip]');
});

it('renders the superadmin backup restore screen', function (): void {
    $this->skipWhenModuleUninstalled('backups');

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/backups/restore');
    $page->assertPathIs('/panel/backups/restore')
        ->assertSee('Restore from a configured File Store')
        ->assertSee('Restore from an uploaded archive')
        ->assertPresent('#archiveUpload')
        ->assertPresent('#manifestUpload');
});

it('renders the git update page', function (): void {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/git-update');
    $page->assertSee('Git Update');
});

it('renders the queue status page', function (): void {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/queue');
    $page->assertSee('Queue Status');
});

it('renders the monitoring dashboard with metrics', function (): void {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/monitoring');
    $page->assertSee('Services')
        ->assertSee('Disk Usage')
        ->assertSee('Memory');
});

// ═══════════════════════════════════════════════════════════════════
//  SECURITY COMMAND CENTER
// ═══════════════════════════════════════════════════════════════════

it('renders the security manager dashboard', function (): void {
    $this->skipWhenModuleUninstalled('security');

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

    // Tab 1 — Allow & Block Lists (the default): the allow/block workbenches
    // under the page-global status strip.
    $page = visit('/panel/security')->resize(1920, 2400);
    $page->assertSee('Security Center')
        ->assertSee('Firewall Status')
        ->assertSee('Attack Protection')
        ->assertSee('Blacklist')
        ->assertSee('Whitelist')
        ->assertSee('198.51.100.23')
        ->assertSee('203.0.113.0/24')
        ->assertSee('2001:db8:bad::/48')
        ->assertSee('192.0.2.10')
        ->assertSee('2001:db8:cafe::/64');

    // Tab 2 — Attackers: the enforced bans with their vector and reason,
    // plus the SIP scanner signatures card.
    visit('/panel/security?tab=attackers')->resize(1920, 2400)
        ->assertSee('Blocked Attackers')
        ->assertSee('203.0.113.66')
        ->assertSee('2001:db8:1234::88')
        ->assertSee('Repeated failed SIP registrations')
        ->assertSee('SIP Bot & Scanner Signatures');

    // Tab 3 — Firewall Rules: the full kernel pipeline, the port catalog,
    // and the custom sequential rules.
    $page = visit('/panel/security?tab=firewall-rules')->resize(1920, 2400);
    $page->assertSee('Carrier SIP trunk')
        ->assertSee('Admin SSH from bastion')
        ->assertSee('Office PBX audio media')
        ->assertSee('Block RDP scanners')
        ->assertSee('Pre-Filters')
        ->assertSee('Standard Services')
        ->assertSee('Default Inbound Policy')
        ->assertSee('ICMP Ping Diagnostics')
        ->assertSee('SIP Signaling');

    // Verify pre-filter header title suppression when hovering over the tooltip icon
    expect($page->script("document.querySelector('tr[x-data*=\"suppressTitle\"]').getAttribute('title')"))
        ->toContain('collapse');

    // Hovering over the tooltip icon suppresses the tr's title attribute to prevent native browser tooltip overlap
    $page->hover('tr[x-data*="suppressTitle"] [data-tip] svg');
    expect($page->script("document.querySelector('tr[x-data*=\"suppressTitle\"]').getAttribute('title')"))
        ->toBeNull();

    // Moving away restores the header title (explicit selector 'thead > tr' prevents Pest text-matching fallback)
    $page->hover('thead > tr');
    expect($page->script("document.querySelector('tr[x-data*=\"suppressTitle\"]').getAttribute('title')"))
        ->toContain('collapse');

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
