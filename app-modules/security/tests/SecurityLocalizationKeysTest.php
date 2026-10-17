<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\AdminSeeder;

// Guards the dictionary keys used by security module views: every
// user-facing key must resolve to a real translation in each supported
// locale instead of falling back to the raw "admin.key" string.

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
});

test('geo and service modal keys resolve in all locales', function (string $locale): void {
    app()->setLocale($locale);

    // Rate-limit burst label used by the edit-system-service modal.
    expect(__('admin.security_rate_burst_packets'))->not->toBe('admin.security_rate_burst_packets');

    // "Last Synced" fallback shown on fresh installs in the Geo-Blocking tab.
    expect(__('admin.never'))->not->toBe('admin.never');

    // The French burst label must match its historical dictionary value
    // verbatim (a misquote once shipped a Spanish word into the French UI).
    if ($locale === 'fr') {
        expect(__('admin.security_rate_burst_packets'))->toBe('Tolérance de rafale (paquets)');
    }
})->with(['en', 'es', 'fr']);
