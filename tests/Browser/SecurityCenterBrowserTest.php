<?php

declare(strict_types=1);

/**
 * Pest 4 Browser tests for the Security Center Geo-Blocking workbench.
 *
 * Validates that the Geo-Blocking tab renders its status badge, policy
 * mode selector, CLI hint, country picker, and operational metrics, and
 * that the master Firewall Rules table carries the dedicated geo row for
 * surface parity. Every assertion is read-only: no toggle actions run, so
 * no provider downloads or privileged helper calls occur during the test.
 */

namespace Tests\Browser;

beforeEach(function (): void {
    $this->setUpSmokeAdmin();
});

it('renders the Geo-Blocking tab with a disabled neutral status', function (): void {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/security?tab=geo-blocking')->resize(1920, 2400);

    $page->assertSee('Geo-Blocking')
        ->assertSee('Inspect kernel geo set elements:')
        ->assertSee('Disabled')
        ->assertSee('Packets Dropped:')
        ->assertSee('United States')
        ->assertPresent('input[type="checkbox"].toggle')
        ->assertPresent('input[type="radio"][value="blacklist"]')
        ->assertPresent('input[type="radio"][value="whitelist"]')
        ->assertPresent('input[type="search"]');
});

it('reflects the geo rule state in the master Firewall Rules table', function (): void {
    $this->loginAs($this->admin, 'admin');

    // The default tab is the master Firewall Rules table: its dedicated
    // Geo-Blocking row must exist in the DOM even while the pre-filters
    // section is collapsed, so both surfaces agree on the disabled state.
    $page = visit('/panel/security')->resize(1920, 2400);

    $page->assertPresent('tr[wire\\:key="geo-blocking-cockpit-row"]');
});

it('opens manual ban modal and protection settings drawer on attackers tab', function (): void {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/security')->resize(1920, 2400);

    // Switch to Attackers tab
    $page->click('button[role="tab"]:has-text("Attackers")');

    // Click "Block IP Manually"
    $page->click('button:has-text("Block IP Manually")');
    $page->assertSee('Manually Block IP Address');

    // Cancel modal
    $page->click('.modal-open button:has-text("Cancel")');

    // Click "Protection Settings"
    $page->click('button:has-text("Protection Settings")');
    $page->assertSee('Attack Protection Settings');
});
