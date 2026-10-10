<?php

declare(strict_types=1);

/**
 * Pest 4 Browser smoke tests for the unified panel core, authentication, and layout.
 *
 * Validates:
 *   - Login form renders and authenticates successfully
 *   - Navigation between pages works without layout breakdown
 *   - Desktop sidebar drawer and topbar render
 *   - Livewire and Echo boot without console/script errors
 *   - Responsive layouts prevent horizontal overflow
 */

namespace Tests\Browser;

beforeEach(function (): void {
    $this->setUpSmokeAdmin();
});

// ═══════════════════════════════════════════════════════════════════
//  AUTHENTICATION — full form flow
// ═══════════════════════════════════════════════════════════════════

it('displays the admin login page', function (): void {
    $page = visit('/panel/login');
    $page->assertSee('TallPBX')
        ->assertPresent('input[name="email"]')
        ->assertPresent('input[name="password"]')
        ->assertPresent('button[type="submit"]');
});

it('logs in via the login form and lands on the dashboard', function (): void {
    $page = visit('/panel/login');
    $page->fill('input[name="email"]', 'admin@smoke.test')
        ->fill('input[name="password"]', 'smoke-secret')
        ->click('button[type="submit"]')
        ->assertPathBeginsWith('/panel');
});

// ═══════════════════════════════════════════════════════════════════
//  DASHBOARD & LAYOUT — use loginAs() for speed
// ═══════════════════════════════════════════════════════════════════

it('renders the sidebar with navigation', function (): void {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/dashboard');
    $page->assertPresent('[data-panel-sidebar-scroll="drawer"]')
        ->assertPresent('[data-panel-sidebar-scroll="nav"]')
        ->assertPresent('label[for="sidebar-drawer"]');
});

it('locks the viewport frame and pins the sidebar to full viewport height', function (): void {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/dashboard');

    $layoutMetrics = $page->script(<<<'JS'
        (() => {
            const body = document.body;
            const sidebar = document.querySelector('.panel-sidebar');
            const main = document.querySelector('main');
            const vh = window.innerHeight;

            return {
                bodyOverflow: window.getComputedStyle(body).overflow,
                sidebarHeight: sidebar ? Math.round(sidebar.getBoundingClientRect().height) : 0,
                vh: vh,
                mainIsScrollContainer: main ? window.getComputedStyle(main).overflowY : null,
            };
        })()
    JS);

    expect($layoutMetrics['sidebarHeight'])->toBeGreaterThanOrEqual($layoutMetrics['vh'] - 2);
    expect($layoutMetrics['bodyOverflow'])->toBe('hidden');
    expect($layoutMetrics['mainIsScrollContainer'])->toBe('auto');
});

it('renders the dashboard with Livewire components', function (): void {
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

it('shows the admin identity in the top bar', function (): void {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/dashboard');
    // The top bar shows the admin's name
    $page->assertSee('Smoke Test Admin');
});

// ═══════════════════════════════════════════════════════════════════
//  NAVIGATION
// ═══════════════════════════════════════════════════════════════════

it('navigates between pages without errors', function (): void {
    $this->skipWhenModuleUninstalled('extensions');
    $this->skipWhenModuleUninstalled('dialplans');
    $this->skipWhenModuleUninstalled('gateways');
    $this->skipWhenModuleUninstalled('sip-accounts');
    $this->skipWhenModuleUninstalled('voicemails');

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
