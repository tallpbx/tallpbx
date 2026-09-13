<?php

declare(strict_types=1);

namespace Tests\Browser\Pages;

use Laravel\Dusk\Browser;

/**
 * Page object for the admin login page at /panel/login.
 *
 * Provides helpers for filling the login form and submitting it,
 * so browser tests stay focused on assertions rather than DOM
 * selectors.
 */
class LoginPage extends Page
{
    /**
     * Get the URL for the admin login page.
     */
    public function url(): string
    {
        return '/panel/login';
    }

    /**
     * Assert that the browser is on the login page.
     */
    public function assert(Browser $browser): void
    {
        $browser->assertPathIs('/panel/login')
            ->assertSee('TallPBX')
            ->assertPresent('input[name="email"]')
            ->assertPresent('input[name="password"]')
            ->assertPresent('button[type="submit"]');
    }

    /**
     * Fill the login form with credentials and submit.
     */
    public function loginAs(Browser $browser, string $email, string $password): void
    {
        $browser->type('email', $email)
            ->type('password', $password)
            ->press('button[type="submit"]');
    }

    /**
     * Dusk element shortcuts for this page.
     *
     * @return array<string, string>
     */
    public function elements(): array
    {
        return [
            '@email' => 'input[name="email"]',
            '@password' => 'input[name="password"]',
            '@submit' => 'button[type="submit"]',
            '@login-card' => '.card.bg-base-100',
        ];
    }
}
