<?php

declare(strict_types=1);

namespace Tests\Browser;

use Tests\Browser\Concerns\SeedsSmokeAdmin;

uses(SeedsSmokeAdmin::class);

beforeEach(function (): void {
    $this->setUpSmokeAdmin();
});

it('intercepts HTTP 419 response and opens session expired modal without native window.confirm', function (): void {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/git-update');
    $page->assertSee('Git Update');

    // Spy on window.confirm
    $page->script(<<<'JS'
        window.__confirmCalled = false;
        window.confirm = function (msg) {
            window.__confirmCalled = true;
            return false;
        };

        // Mock next Livewire request to return HTTP 419 (CSRF expiration)
        const originalFetch = window.fetch;
        let mockedOnce = false;
        window.fetch = function (url, options) {
            if (!mockedOnce && typeof url === 'string' && url.includes('/livewire')) {
                mockedOnce = true;
                return Promise.resolve(new Response('CSRF token mismatch.', {
                    status: 419,
                    statusText: 'unknown status',
                    headers: { 'Content-Type': 'text/html' }
                }));
            }
            return originalFetch.apply(this, arguments);
        };
    JS);

    // Click the Fetch Latest button
    $page->click('button[wire\\:click="fetch"]');

    // Wait for the Session Expired modal to become visible
    $page->waitForText('Session Expired');
    $page->assertSee('Session Expired');
    $page->assertVisible('#session-expired-modal-title');

    // Assert that native window.confirm was NOT called
    $confirmCalled = (bool) $page->script('window.__confirmCalled');
    expect($confirmCalled)->toBeFalse();
});

it('suppresses native confirm and does not trigger session expired modal on transient empty 200 response', function (): void {
    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/git-update');
    $page->assertSee('Git Update');

    // Spy on window.confirm and mock window.fetch for the next /livewire/update request
    $page->script(<<<'JS'
        window.__confirmCalled = false;
        window.confirm = function (msg) {
            window.__confirmCalled = true;
            return false;
        };

        const originalFetch = window.fetch;
        let mockedOnce = false;
        window.fetch = function (url, options) {
            if (!mockedOnce && typeof url === 'string' && url.includes('/livewire')) {
                mockedOnce = true;
                return Promise.resolve(new Response('', {
                    status: 200,
                    statusText: 'OK',
                    headers: { 'Content-Type': 'text/html' }
                }));
            }
            return originalFetch.apply(this, arguments);
        };
    JS);

    // Click the Fetch Latest button
    $page->click('button[wire\\:click="fetch"]');

    // Pause briefly to allow response processing
    usleep(500000);

    // Assert window.confirm was not called
    $confirmCalled = (bool) $page->script('window.__confirmCalled');
    expect($confirmCalled)->toBeFalse();

    // Verify modal title is not visible
    $isModalVisible = (bool) $page->script(<<<'JS'
        (() => {
            const el = document.getElementById('session-expired-modal-title');
            if (!el) return false;
            const style = window.getComputedStyle(el.closest('[role="dialog"]') || el);
            return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
        })()
    JS);
    expect($isModalVisible)->toBeFalse();
});
