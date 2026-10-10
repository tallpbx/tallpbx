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

    browserStep('Operations: Email Connector (/panel/email-connector)');
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/email-connector');
    $page->assertSee('Email Connector Configuration')
        ->assertPresent('.tooltip[data-tip]');
});

it('renders the backups list page', function (): void {
    $this->skipWhenModuleUninstalled('backups');

    browserStep('Operations: Backups list (/panel/backups)');
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/backups');
    $page->assertSee('Backups');
});

it('renders the backups create form', function (): void {
    $this->skipWhenModuleUninstalled('backups');

    browserStep('Operations: Backups create form (/panel/backups/create)');
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/backups/create');
    $page->assertSee('Backup Configuration')
        ->assertPresent('.tooltip[data-tip]');
});

it('renders the superadmin backup restore screen', function (): void {
    $this->skipWhenModuleUninstalled('backups');

    browserStep('Operations: Backup restore screen (/panel/backups/restore)');
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/backups/restore');
    $page->assertPathIs('/panel/backups/restore')
        ->assertSee('Restore from a configured File Store')
        ->assertSee('Restore from an uploaded archive')
        ->assertPresent('#archiveUpload')
        ->assertPresent('#manifestUpload');
});

it('renders the git update page', function (): void {
    browserStep('Operations: Git Update (/panel/git-update)');
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/git-update');
    $page->assertSee('Git Update');
});

it('renders the queue status page', function (): void {
    browserStep('Operations: Queue Status (/panel/queue)');
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/queue');
    $page->assertSee('Queue Status');
});

it('renders the monitoring dashboard with metrics', function (): void {
    browserStep('Operations: Monitoring dashboard (/panel/monitoring)');
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

    browserStep('Security: seeding threat & trust test data');
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
    browserStep('Security: Tab 1 (Allow & Block Lists)');
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
    browserStep('Security: Tab 2 (Attackers & Bans)');
    visit('/panel/security?tab=attackers')->resize(1920, 2400)
        ->assertSee('Blocked Attackers')
        ->assertSee('203.0.113.66')
        ->assertSee('2001:db8:1234::88')
        ->assertSee('Repeated failed SIP registrations')
        ->assertSee('SIP Bot & Scanner Signatures');

    // Tab 3 — Firewall Rules: the full kernel pipeline, the port catalog,
    // and the custom sequential rules.
    browserStep('Security: Tab 3 (Firewall Rules & Port Access)');
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
    browserStep('Security: verifying pre-filter tooltip hover title suppression');
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

    // At default desktop width 1164px with pre-filters collapsed, table fits without overflow and side arrows must be hidden
    browserStep('Security: verifying arrows hidden at 1164px desktop width');
    $page->resize(1164, 1200);
    $arrowsAt1164 = $page->script(<<<'JS'
        (() => {
            const leftEl = document.querySelector('#tableScrollSideLeftBtn')?.closest('[x-show]');
            const rightEl = document.querySelector('#tableScrollSideRightBtn')?.closest('[x-show]');
            const card = Alpine.$data(document.querySelector('.card[x-data*="canScrollLeft"]'));
            card.checkScroll();
            return {
                hasOverflow: card.hasOverflow,
                leftDisplay: leftEl ? window.getComputedStyle(leftEl).display : 'none',
                rightDisplay: rightEl ? window.getComputedStyle(rightEl).display : 'none',
            };
        })()
    JS);
    expect($arrowsAt1164['hasOverflow'])->toBeFalse();
    expect($arrowsAt1164['leftDisplay'])->toBe('none');
    expect($arrowsAt1164['rightDisplay'])->toBe('none');

    // Expand the collapsible Pre-Filters section so the full kernel pipeline is visible
    browserStep('Security: expanding pre-filters accordion');
    $page->click('tr[title*="expand or collapse"]')
        ->assertSee('Loopback Interface')
        ->assertSee('Stateful Connection Tracking');

    // Verify horizontal table scroller container and navigation elements are present
    browserStep('Security: verifying horizontal table scroller controls');
    $page->assertPresent('.group\\/scroller')
        ->assertPresent('[x-ref="tableContainer"]');

    // Resize to a width where horizontal overflow occurs
    $page->resize(750, 1200);
    $page->script("const card = Alpine.\$data(document.querySelector('.card[x-data*=\"canScrollLeft\"]')); card.checkScroll(); card.updateArrowPosition();");

    // Click companion header scroll right button
    browserStep('Clicking companion header scroll right button');
    $page->click('#tableScrollHeaderRightBtn');
    usleep(450000);
    $scrollPosHeaderRight = (int) $page->script("document.querySelector('[x-ref=\"tableContainer\"]').scrollLeft");
    browserStep('Scroll pos after header right button: '.$scrollPosHeaderRight);
    expect($scrollPosHeaderRight)->toBeGreaterThan(15);

    // Click companion header scroll left button
    browserStep('Clicking companion header scroll left button');
    $page->click('#tableScrollHeaderLeftBtn');
    usleep(450000);
    $scrollPosHeaderLeft = (int) $page->script("document.querySelector('[x-ref=\"tableContainer\"]').scrollLeft");
    browserStep('Scroll pos after header left button: '.$scrollPosHeaderLeft);
    expect($scrollPosHeaderLeft)->toBeLessThan($scrollPosHeaderRight);

    // Ensure table is vertically centered for side arrow interactions
    $page->script("document.querySelector('[x-ref=\"tableContainer\"]').scrollIntoView({ block: 'center' }); Alpine.\$data(document.querySelector('.card[x-data*=\"canScrollLeft\"]')).updateArrowPosition();");

    // Click side arrow scroll right button
    browserStep('Clicking side arrow scroll right button');
    $page->click('#tableScrollSideRightBtn');
    usleep(450000);
    $scrollPosSideRight = (int) $page->script("document.querySelector('[x-ref=\"tableContainer\"]').scrollLeft");
    browserStep('Scroll pos after side right button: '.$scrollPosSideRight);
    expect($scrollPosSideRight)->toBeGreaterThan(15);

    // Click side arrow scroll left button
    browserStep('Clicking side arrow scroll left button');
    $page->click('#tableScrollSideLeftBtn');
    usleep(450000);
    $scrollPosSideLeft = (int) $page->script("document.querySelector('[x-ref=\"tableContainer\"]').scrollLeft");
    browserStep('Scroll pos after side left button: '.$scrollPosSideLeft);
    // Verify vertical tracking: scrolling down to center of table adjusts arrowTop dynamically
    $scrollTrackingDebug = $page->script(<<<'JS'
        (() => {
            const card = Alpine.$data(document.querySelector('.card[x-data*="canScrollLeft"]'));
            const el = card.getContainer();
            const scroller = document.querySelector('main') || window;
            const initialTop = card.arrowTop;
            
            // Scroll so the table is centered in the viewport
            el.scrollIntoView({ block: 'center' });
            card.updateArrowPosition();
            const centeredTop = card.arrowTop;
            
            return {
                initialTop,
                centeredTop,
                height: el.clientHeight,
            };
        })()
JS);
    browserStep('Scroll tracking debug: '.json_encode($scrollTrackingDebug));
    expect($scrollTrackingDebug['centeredTop'])->toBeGreaterThan(50);

    // Grow the viewport to the full document height for documentation screenshots
    $expandedHeight = (int) ($page->script(
        "Math.max(document.body.scrollHeight, document.documentElement.scrollHeight, document.body.offsetHeight, document.querySelector('main')?.scrollHeight || 0)"
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
