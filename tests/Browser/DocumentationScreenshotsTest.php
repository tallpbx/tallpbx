<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Modules\Devices\Models\Device;
use Modules\Extensions\Models\Extension;
use Pest\Browser\Api\PendingAwaitablePage;

/**
 * Captures clean, high-resolution UI screenshots for documentation.
 *
 * Covers:
 *   - Landing page (Light and Dark theme)
 *   - Dashboard (Full sidebar, compressed mini-rail, horizontal topbar)
 *   - Representative PBX sections (Tenants, Devices, Extensions)
 *
 * Freshness convention: captures are written to the git-ignored
 * tests/Browser/Screenshots directory and copied into docs/images only when
 * their content actually changed, so unchanged pages never produce churn.
 */
beforeEach(function (): void {
    // Documentation screenshots are refreshed on demand only: normal browser
    // runs must never rewrite the tracked images under docs/images.
    if (getenv('TALLPBX_CAPTURE_DOCS') !== '1') {
        $this->markTestSkipped('Set TALLPBX_CAPTURE_DOCS=1 to refresh documentation screenshots.');
    }

    // Populate the module registry and seed every registered permission into
    // the freshly refreshed test database, then grant the capture admin the
    // Super Administrators group so every documented page renders its real
    // content instead of a 403 (in-memory databases are never pre-seeded).
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);

    $this->admin = Admin::firstOrCreate(
        ['email' => 'admin@tallpbx.org'],
        [
            'name' => 'System Administrator',
            'password' => bcrypt('tallpbx-secret'),
            'enabled' => true,
            'theme' => 'light',
            'layout_mode' => 'sidebar',
            'sidebar_collapsed' => false,
        ],
    );

    $superAdminGroup = Group::where('name', 'Super Administrators')->first();
    if ($superAdminGroup) {
        $this->admin->groups()->syncWithoutDetaching([$superAdminGroup->id]);
    }

    // Clean any auto-generated test records for clean documentation tables
    Extension::withoutGlobalScope('tenant')->where('extension_number', 'like', '24%')->delete();

    // Seed realistic sample tenants
    $defaultTenant = Tenant::firstOrCreate(
        ['slug' => 'default'],
        ['name' => 'Default', 'purpose' => Tenant::PURPOSE_DEFAULT, 'enabled' => true],
    );

    Tenant::firstOrCreate(
        ['slug' => 'acme'],
        ['name' => 'Acme Corporation', 'purpose' => Tenant::PURPOSE_CUSTOMER, 'enabled' => true],
    );

    Tenant::firstOrCreate(
        ['slug' => 'global-logistics'],
        ['name' => 'Global Logistics Inc.', 'purpose' => Tenant::PURPOSE_CUSTOMER, 'enabled' => true],
    );

    Tenant::firstOrCreate(
        ['slug' => 'apex-financial'],
        ['name' => 'Apex Financial Group', 'purpose' => Tenant::PURPOSE_CUSTOMER, 'enabled' => true],
    );

    Tenant::firstOrCreate(
        ['slug' => 'pacific-health'],
        ['name' => 'Pacific Health Services', 'purpose' => Tenant::PURPOSE_CUSTOMER, 'enabled' => true],
    );

    // Seed realistic sample user for impersonation tour
    $acmeTenant = Tenant::where('slug', 'acme')->first();
    if ($acmeTenant) {
        $sampleUser = User::firstOrCreate(
            ['email' => 'john.doe@acme.test'],
            [
                'name' => 'John Doe',
                'password' => bcrypt('secret123'),
            ],
        );
        $acmeTenant->users()->syncWithoutDetaching([
            $sampleUser->id => ['role' => 'admin'],
        ]);
    }

    // Seed realistic sample extensions
    Extension::withoutGlobalScope('tenant')->firstOrCreate(
        ['tenant_id' => $defaultTenant->id, 'extension_number' => '1001'],
        [
            'display_name' => 'Executive Office',
            'directory_first_name' => 'John',
            'directory_last_name' => 'Doe',
            'effective_caller_id_name' => 'John Doe',
            'effective_caller_id_number' => '1001',
            'voicemail_enabled' => true,
            'enabled' => true,
        ],
    );

    Extension::withoutGlobalScope('tenant')->firstOrCreate(
        ['tenant_id' => $defaultTenant->id, 'extension_number' => '1002'],
        [
            'display_name' => 'Operations Manager',
            'directory_first_name' => 'Jane',
            'directory_last_name' => 'Smith',
            'effective_caller_id_name' => 'Jane Smith',
            'effective_caller_id_number' => '1002',
            'voicemail_enabled' => true,
            'enabled' => true,
        ],
    );

    Extension::withoutGlobalScope('tenant')->firstOrCreate(
        ['tenant_id' => $defaultTenant->id, 'extension_number' => '1003'],
        [
            'display_name' => 'Reception & Front Desk',
            'directory_first_name' => 'Front',
            'directory_last_name' => 'Desk',
            'effective_caller_id_name' => 'Front Desk',
            'effective_caller_id_number' => '1003',
            'voicemail_enabled' => false,
            'enabled' => true,
        ],
    );

    Extension::withoutGlobalScope('tenant')->firstOrCreate(
        ['tenant_id' => $defaultTenant->id, 'extension_number' => '1004'],
        [
            'display_name' => 'Main Conference Room',
            'directory_first_name' => 'Conference',
            'directory_last_name' => 'Room',
            'effective_caller_id_name' => 'Conf Room 1',
            'effective_caller_id_number' => '1004',
            'voicemail_enabled' => false,
            'enabled' => true,
        ],
    );

    Extension::withoutGlobalScope('tenant')->firstOrCreate(
        ['tenant_id' => $defaultTenant->id, 'extension_number' => '1005'],
        [
            'display_name' => 'Customer Support Queue',
            'directory_first_name' => 'Support',
            'directory_last_name' => 'Tier 1',
            'effective_caller_id_name' => 'Customer Support',
            'effective_caller_id_number' => '1005',
            'voicemail_enabled' => true,
            'enabled' => true,
        ],
    );

    // Seed realistic sample devices
    Device::withoutGlobalScope('tenant')->firstOrCreate(
        ['mac_address' => '00:15:65:A1:B2:C3'],
        [
            'tenant_id' => $defaultTenant->id,
            'vendor' => 'Yealink',
            'model' => 'SIP-T46U',
            'template' => 'yealink/t46u',
            'enabled' => true,
        ],
    );

    Device::withoutGlobalScope('tenant')->firstOrCreate(
        ['mac_address' => '64:16:7F:11:22:33'],
        [
            'tenant_id' => $defaultTenant->id,
            'vendor' => 'Polycom',
            'model' => 'VVX 450',
            'template' => 'polycom/vvx450',
            'enabled' => true,
        ],
    );

    Device::withoutGlobalScope('tenant')->firstOrCreate(
        ['mac_address' => '00:0B:82:44:55:66'],
        [
            'tenant_id' => $defaultTenant->id,
            'vendor' => 'Grandstream',
            'model' => 'GRP2614',
            'template' => 'grandstream/grp2614',
            'enabled' => true,
        ],
    );

    Device::withoutGlobalScope('tenant')->firstOrCreate(
        ['mac_address' => '00:1E:13:77:88:99'],
        [
            'tenant_id' => $defaultTenant->id,
            'vendor' => 'Cisco',
            'model' => 'CP-8845',
            'template' => 'cisco/cp8845',
            'enabled' => true,
        ],
    );
});

it('captures documentation screenshots', function (): void {
    // 1. Landing page — Light Theme
    $page = visitDocumentationPage($this, '/en');
    $page->waitForText('FreeSWITCH Telephone Platform');

    // script() returns the evaluated JavaScript value, so void theme-switching
    // snippets are executed as standalone statements instead of being chained.
    $page->script(<<<'JS'
        (() => {
            localStorage.setItem('theme', 'light');
            document.documentElement.setAttribute('data-theme', 'light');
        })()
        JS);
    $page->wait(1.2)->screenshot(filename: 'landing-light');

    // 2. Landing page — Dark Theme
    $page->script(<<<'JS'
        (() => {
            localStorage.setItem('theme', 'dark');
            document.documentElement.setAttribute('data-theme', 'dark');
        })()
        JS);
    $page->wait(1.2)->screenshot(filename: 'landing-dark');

    // 3. Unified Panel Dashboard — Full Sidebar
    $this->admin->update([
        'theme' => 'light',
        'layout_mode' => 'sidebar',
        'sidebar_collapsed' => false,
    ]);
    $this->loginAs($this->admin, 'admin');

    $page = visitDocumentationPage($this, '/panel/dashboard');
    $page->waitForText('Dashboard')
        ->wait(1.5)
        ->screenshot(filename: 'dashboard-full-sidebar');

    // 4. Unified Panel Dashboard — Compressed Sidebar (Mini Icon Rail)
    $this->admin->update([
        'layout_mode' => 'sidebar',
        'sidebar_collapsed' => true,
    ]);
    $page = visitDocumentationPage($this, '/panel/dashboard');
    $page->waitForText('Dashboard')
        ->wait(1.5)
        ->screenshot(filename: 'dashboard-compressed-sidebar');

    // 5. Unified Panel Dashboard — Horizontal Topbar Menu
    $this->admin->update([
        'layout_mode' => 'horizontal',
        'sidebar_collapsed' => false,
    ]);
    $page = visitDocumentationPage($this, '/panel/dashboard');
    $page->waitForText('Dashboard')
        ->wait(1.5)
        ->screenshot(filename: 'dashboard-horizontal-menu');

    // Reset layout back to standard full sidebar for remaining section pages
    $this->admin->update([
        'layout_mode' => 'sidebar',
        'sidebar_collapsed' => false,
    ]);

    // 6. PBX Tenants
    $page = visitDocumentationPage($this, '/panel/tenants');
    $page->waitForText('Tenants')
        ->wait(1.2)
        ->screenshot(filename: 'pbx-tenants');

    // 7. PBX Devices
    $page = visitDocumentationPage($this, '/panel/devices');
    $page->waitForText('Devices')
        ->wait(1.2)
        ->screenshot(filename: 'pbx-devices');

    // 8. PBX Extensions
    $page = visitDocumentationPage($this, '/panel/extensions');
    $page->waitForText('Extensions')
        ->wait(1.2)
        ->screenshot(filename: 'pbx-extensions');

    // 9. User Impersonation & Support View
    $page = visitDocumentationPage($this, '/panel/users');
    $page->waitForText('Users')
        ->waitForText('john.doe@acme.test')
        ->click('form[action*="impersonate"] button')
        ->waitForText('Stop Impersonating')
        ->wait(1.2)
        ->screenshot(filename: 'pbx-impersonation');

    // 10. Multi-Language Switcher
    $page->press('Stop Impersonating')
        ->waitForText('Dashboard')
        ->click('div[x-data*="locales"] button')
        ->wait(0.6)
        ->screenshot(filename: 'pbx-multi-language');

    // Copy captured screenshots into docs/images/
    $images = [
        'landing-light.png',
        'landing-dark.png',
        'dashboard-full-sidebar.png',
        'dashboard-compressed-sidebar.png',
        'dashboard-horizontal-menu.png',
        'pbx-tenants.png',
        'pbx-devices.png',
        'pbx-extensions.png',
        'pbx-impersonation.png',
        'pbx-multi-language.png',
    ];

    foreach ($images as $img) {
        $src = base_path("tests/Browser/Screenshots/{$img}");
        $dest = base_path("docs/images/{$img}");

        // Copy only captures whose content actually changed, so unchanged
        // pages never produce documentation image churn.
        if (file_exists($src) && (! file_exists($dest) || md5_file($src) !== md5_file($dest))) {
            copy($src, $dest);
            fwrite(STDOUT, "Updated documentation image: docs/images/{$img}".PHP_EOL);
        }
    }
});

/**
 * Visit one documentation page at the fixed documentation capture viewport.
 *
 * Dusk resized the browser once per suite; Pest creates a fresh browser
 * context for every visit(), so the 1440x900 documentation viewport is passed
 * as a context option on each visit instead.
 *
 * @param  object  $test  The current Pest test case providing visit()
 * @param  string  $path  The panel or landing page path to open
 * @return PendingAwaitablePage The visited page for fluent interactions and screenshots
 */
function visitDocumentationPage(object $test, string $path): PendingAwaitablePage
{
    return $test->visit($path, [
        'viewport' => ['width' => 1440, 'height' => 900],
    ]);
}
