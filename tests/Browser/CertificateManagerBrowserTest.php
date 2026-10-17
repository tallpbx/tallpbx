<?php

declare(strict_types=1);

namespace Tests\Browser;

use Modules\Certificates\Models\Certificate;
use Modules\Certificates\Models\CertificateDnsCredential;
use Tests\Browser\Concerns\SeedsSmokeAdmin;

uses(SeedsSmokeAdmin::class);

beforeEach(function (): void {
    $this->setUpSmokeAdmin();
});

it('renders the certificate manager dashboard with active services and inventory', function (): void {
    Certificate::create([
        'name' => 'Primary Web Certificate',
        'type' => 'lets_encrypt',
        'common_name' => 'pbx.example.com',
        'storage_identifier' => 'browser_test_web',
        'valid_from' => now()->subDays(10),
        'valid_to' => now()->addDays(80),
        'is_default_web' => true,
        'is_default_telephony' => false,
        'auto_renew' => true,
    ]);

    Certificate::create([
        'name' => 'Internal SIP TLS Certificate',
        'type' => 'self_signed',
        'common_name' => 'sip.internal.lan',
        'storage_identifier' => 'browser_test_telephony',
        'valid_from' => now()->subDays(30),
        'valid_to' => now()->addDays(335),
        'is_default_web' => false,
        'is_default_telephony' => true,
        'auto_renew' => false,
    ]);

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/certificates')->resize(1920, 1080);

    $page->assertSee('Web Portal')
        ->assertSee('Telephony (SIP')
        ->assertSee('Primary Web Certificate')
        ->assertSee('pbx.example.com')
        ->assertSee('Internal SIP TLS Certificate')
        ->assertSee('sip.internal.lan');

    if (getenv('TALLPBX_CAPTURE_DOCS') === '1') {
        // 1. Light Theme Capture
        $page->script(<<<'JS'
            (() => {
                localStorage.setItem('theme', 'light');
                document.documentElement.setAttribute('data-theme', 'light');
            })()
        JS);
        $page->wait(0.6);

        $lightImg = 'certificates-light.png';
        $page->screenshot(filename: $lightImg);
        $srcLight = base_path("tests/Browser/Screenshots/{$lightImg}");
        $destLight = base_path("docs/images/{$lightImg}");
        if (file_exists($srcLight)) {
            copy($srcLight, $destLight);
        }

        // 2. Dark Theme Capture
        $page->script(<<<'JS'
            (() => {
                localStorage.setItem('theme', 'dark');
                document.documentElement.setAttribute('data-theme', 'dark');
            })()
        JS);
        $page->wait(0.6);

        $darkImg = 'certificates-dark.png';
        $page->screenshot(filename: $darkImg);
        $srcDark = base_path("tests/Browser/Screenshots/{$darkImg}");
        $destDark = base_path("docs/images/{$darkImg}");
        if (file_exists($srcDark)) {
            copy($srcDark, $destDark);
        }

        // Restore light theme
        $page->script(<<<'JS'
            (() => {
                localStorage.setItem('theme', 'light');
                document.documentElement.setAttribute('data-theme', 'light');
            })()
        JS);
    }
});

it('navigates across all certificate manager tabs seamlessly', function (): void {
    CertificateDnsCredential::create([
        'name' => 'Cloudflare Production Vault',
        'provider' => 'cloudflare',
        'credentials' => ['api_token' => 'CF-SECRET-TOKEN-12345'],
    ]);

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/certificates')->resize(1920, 1080);

    // Default tab: Inventory
    $page->assertSee('Inventory')
        ->assertSee('NAME / DOMAIN');

    // Switch to Let's Encrypt tab
    $page->click('#tab-letsencrypt')
        ->assertSee('Issue Let\'s Encrypt Certificate')
        ->assertSee('HTTP-01')
        ->assertSee('DNS-01');

    // Switch to Custom Import tab
    $page->click('#tab-import')
        ->assertSee('Import Custom SSL/TLS Certificate')
        ->assertSee('Private Key');

    // Switch to Self-Signed tab
    $page->click('#tab-selfsigned')
        ->assertSee('Generate Self-Signed Certificate')
        ->assertSee('Validity Duration');

    // Switch to DNS Vault tab
    $page->click('#tab-dnsvault')
        ->assertSee('DNS Provider Credentials')
        ->assertSee('Cloudflare Production Vault');

    // Switch to Audit Logs tab
    $page->click('#tab-logs')
        ->assertSee('Timestamp')
        ->assertSee('Administrator');
});

it('opens service assignment modal for a certificate', function (): void {
    $cert = Certificate::create([
        'name' => 'Assignable Cert',
        'type' => 'custom',
        'common_name' => 'assign.example.com',
        'storage_identifier' => 'browser_assign_test',
        'valid_from' => now(),
        'valid_to' => now()->addDays(90),
    ]);

    $this->loginAs($this->admin, 'admin');

    $page = visit('/panel/certificates')->resize(1920, 1080);
    $page->assertSee('Assignable Cert');

    // Click service assignment trigger
    $page->click('#assign-cert-'.$cert->id)
        ->assertSee('Deploy Certificate to Services')
        ->assertSee('Web Portal (Nginx HTTPS :443)')
        ->assertSee('FreeSWITCH Telephony (SIP TLS & WebRTC)');
});
