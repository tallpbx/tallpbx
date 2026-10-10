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
 * their visible content actually changed, so unchanged pages never produce
 * churn. Gallery images are viewport crops (1440x900 at the documentation
 * viewport), never full-page captures, so their geometry stays stable
 * between runs.
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
    settleDocumentationPage($page);

    // script() returns the evaluated JavaScript value, so void theme-switching
    // snippets are executed as standalone statements instead of being chained.
    $page->script(<<<'JS'
        (() => {
            localStorage.setItem('theme', 'light');
            document.documentElement.setAttribute('data-theme', 'light');
        })()
        JS);
    // Every gallery capture is a viewport crop (fullPage: false) so the image
    // geometry stays a stable 1440x900 between runs; see the class docblock.
    $page->wait(1.2)->screenshot(fullPage: false, filename: 'landing-light');

    // 2. Landing page — Dark Theme
    $page->script(<<<'JS'
        (() => {
            localStorage.setItem('theme', 'dark');
            document.documentElement.setAttribute('data-theme', 'dark');
        })()
        JS);
    settleDocumentationPage($page);
    $page->wait(1.2)->screenshot(fullPage: false, filename: 'landing-dark');

    // 3. Unified Panel Dashboard — Full Sidebar
    $this->admin->update([
        'theme' => 'light',
        'layout_mode' => 'sidebar',
        'sidebar_collapsed' => false,
    ]);
    $this->loginAs($this->admin, 'admin');

    $page = visitDocumentationPage($this, '/panel/dashboard');
    $page->waitForText('Dashboard')
        ->wait(1.5);
    applyDocumentationLayout($page, 'sidebar', false);
    $page->wait(0.5);
    settleDocumentationPage($page);
    expect($page->script("localStorage.getItem('tallpbx:layout_mode')"))->toBe('sidebar')
        ->and($page->script("localStorage.getItem('tallpbx:sidebar_collapsed')"))->toBe('false')
        ->and($page->script('document.documentElement.getAttribute("data-layout-mode")'))->toBe('sidebar')
        ->and($page->script('document.documentElement.getAttribute("data-sidebar-collapsed")'))->toBe('false');
    $page->screenshot(fullPage: false, filename: 'dashboard-full-sidebar');

    // 4. Unified Panel Dashboard — Compressed Sidebar (Mini Icon Rail)
    $this->admin->update([
        'layout_mode' => 'sidebar',
        'sidebar_collapsed' => true,
    ]);
    $page = visitDocumentationPage($this, '/panel/dashboard');
    $page->waitForText('Dashboard')
        ->wait(1.5);
    applyDocumentationLayout($page, 'sidebar', true);
    $page->wait(0.5);
    settleDocumentationPage($page);
    expect($page->script("localStorage.getItem('tallpbx:sidebar_collapsed')"))->toBe('true')
        ->and($page->script('document.documentElement.getAttribute("data-sidebar-collapsed")'))->toBe('true');
    $page->screenshot(fullPage: false, filename: 'dashboard-compressed-sidebar');

    // 5. Unified Panel Dashboard — Horizontal Topbar Menu
    $this->admin->update([
        'layout_mode' => 'horizontal',
        'sidebar_collapsed' => false,
    ]);
    $page = visitDocumentationPage($this, '/panel/dashboard');
    $page->waitForText('Dashboard')
        ->wait(1.5);
    applyDocumentationLayout($page, 'horizontal', false);
    $page->wait(0.5);
    settleDocumentationPage($page);
    expect($page->script('document.documentElement.getAttribute("data-layout-mode")'))->toBe('horizontal');
    $page->screenshot(fullPage: false, filename: 'dashboard-horizontal-menu');

    // Reset layout back to standard full sidebar for remaining section pages
    $this->admin->update([
        'layout_mode' => 'sidebar',
        'sidebar_collapsed' => false,
    ]);

    // 6. PBX Tenants
    $page = visitDocumentationPage($this, '/panel/tenants');
    $page->waitForText('Tenants')
        ->wait(1.2);
    settleDocumentationPage($page);
    $page->screenshot(fullPage: false, filename: 'pbx-tenants');

    // 7. PBX Devices
    $page = visitDocumentationPage($this, '/panel/devices');
    $page->waitForText('Devices')
        ->wait(1.2);
    settleDocumentationPage($page);
    $page->screenshot(fullPage: false, filename: 'pbx-devices');

    // 8. PBX Extensions
    $page = visitDocumentationPage($this, '/panel/extensions');
    $page->waitForText('Extensions')
        ->wait(1.2);
    settleDocumentationPage($page);
    $page->screenshot(fullPage: false, filename: 'pbx-extensions');

    // 9. User Impersonation & Support View
    $page = visitDocumentationPage($this, '/panel/users');
    $page->waitForText('Users')
        ->waitForText('john.doe@acme.test')
        ->click('form[action*="impersonate"] button')
        ->waitForText('Stop Impersonating')
        ->wait(1.2);
    settleDocumentationPage($page);
    $page->screenshot(fullPage: false, filename: 'pbx-impersonation');

    // 10. Multi-Language Switcher
    $page->press('Stop Impersonating')
        ->waitForText('Dashboard')
        ->click('div[x-data*="locales"] button')
        ->wait(0.6);
    settleDocumentationPage($page);
    $page->screenshot(fullPage: false, filename: 'pbx-multi-language');

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

        // Guard the gallery geometry: every documentation image must be a
        // 1440x900 viewport crop at the documented capture viewport, never a
        // full-page capture — full-page shots include off-viewport overflow and
        // would rewrite the whole gallery whenever page length changes.
        $size = getimagesize($src);
        expect($size === false ? null : [$size[0], $size[1]])->toBe([1440, 900]);

        // Copy only captures whose visible content actually changed, so
        // unchanged pages never produce documentation image churn.
        if (file_exists($src) && captureVisiblyChanged($src, $dest)) {
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

/**
 * Force one documented panel layout state deterministically.
 *
 * The panel layout is client-side state: the admin preference is stored in
 * the database, but rendering is driven by the tallpbx:layout_mode and
 * tallpbx:sidebar_collapsed localStorage keys, the matching root attributes,
 * and the Alpine state that DisplaySettings re-dispatches after Livewire
 * mounts. Capturing straight after an admin preference update races those
 * boot stages, so the capture applies the preference through every channel
 * the panel itself uses — localStorage, the root attributes, and the Livewire
 * events — once the page has settled. All sources then agree and the
 * rendered layout is stable.
 *
 * @param  object  $page  The visited documentation page
 * @param  string  $mode  Layout mode to force: 'sidebar' or 'horizontal'
 * @param  bool  $collapsed  Whether the sidebar should render collapsed
 */
function applyDocumentationLayout(object $page, string $mode, bool $collapsed): void
{
    $expected = $collapsed ? 'true' : 'false';

    // The returned read-back proves the preference really landed.
    $written = $page->script(sprintf(
        '(() => {'
        ."localStorage.setItem('tallpbx:layout_mode', %s); "
        ."localStorage.setItem('tallpbx:sidebar_collapsed', %s); "
        ."document.documentElement.setAttribute('data-layout-mode', %s); "
        ."document.documentElement.setAttribute('data-sidebar-collapsed', %s); "
        .'if (window.Livewire) { '
        ."Livewire.dispatch('layout-changed', { mode: %s }); "
        ."Livewire.dispatch('sidebar-collapse-changed', { collapsed: %s }); "
        .'} '
        ."return localStorage.getItem('tallpbx:sidebar_collapsed'); "
        .'})()',
        json_encode($mode),
        json_encode($expected),
        json_encode($mode),
        json_encode($expected),
        json_encode($mode),
        $collapsed ? 'true' : 'false',
    ));
    expect($written)->toBe($expected);
}

/**
 * Settle every timing-dependent page behaviour before a capture.
 *
 * Screenshot determinism has three enemies, all of them timing races:
 *
 *   1. CSS transitions and animations still in flight (for example the
 *      sidebar drawer's open transform) rasterize at fractional pixel
 *      offsets, so two captures of the same state differ glyph-by-glyph.
 *   2. Web fonts finishing late: every visit() gets a fresh browser
 *      context with a cold cache, so text captured before the web font
 *      arrives renders in the fallback face and reflows the layout.
 *   3. Sidebar scroll restoration, which the panel replays from
 *      sessionStorage (pbx:sidebar-scroll and pbx:sidebar-target) after
 *      navigation and which would make captures depend on earlier visits.
 *
 * This helper removes all three: it freezes transitions and animations,
 * waits for document.fonts.ready, then pins every scroll container to its
 * top and reads the position back so the capture cannot race a restore.
 *
 * @param  object  $page  The visited documentation page
 */
function settleDocumentationPage(object $page): void
{
    // Freeze transitions and animations once per page so no capture can
    // land mid-motion; the frozen end state is exactly what the user sees.
    $page->script(<<<'JS'
        (() => {
            if (!document.getElementById('documentation-capture-freeze')) {
                const freeze = document.createElement('style');
                freeze.id = 'documentation-capture-freeze';
                freeze.textContent = '*, *::before, *::after { transition: none !important; animation: none !important; } html { scroll-behavior: auto !important; }';
                document.head.appendChild(freeze);
            }
        })()
        JS);

    // Wait until every font used by the page has finished loading.
    $page->script('document.fonts.ready.then(() => "fonts-ready")');

    // Clear the saved scroll positions, pin the scroll containers to their
    // top after two animation frames (so late restore callbacks have lost
    // the race), and read the result back as proof.
    $scrollTop = $page->script(<<<'JS'
        (() => {
            sessionStorage.removeItem('pbx:sidebar-scroll');
            sessionStorage.removeItem('pbx:sidebar-target');
            const containers = Array.from(document.querySelectorAll('[data-panel-sidebar-scroll]'));
            return new Promise((resolve) => {
                requestAnimationFrame(() => requestAnimationFrame(() => {
                    containers.forEach((el) => { el.scrollTop = 0; });
                    resolve(containers.every((el) => el.scrollTop === 0) ? 'top' : 'drifted');
                }));
            });
        })()
        JS);
    expect($scrollTop)->toBe('top');
}

/**
 * Decide whether a fresh capture visibly differs from the stored image.
 *
 * Two separate browser processes never rasterize glyphs byte-identically:
 * Skia's glyph cache warm-up varies per process, so unchanged text renders
 * a fraction of a pixel thicker or thinner and millions of pixels differ by
 * tiny amounts. Measured on this gallery, that cross-process jitter never
 * moves a 4x4 pixel block's average brightness by more than about 20 levels,
 * while even a single changed table label moves it by over 110. The gate
 * therefore compares block averages and copies only when some block moves
 * by more than 40 brightness levels — twice the jitter ceiling and well
 * under any real change.
 *
 * @param  string  $src  The fresh capture in tests/Browser/Screenshots
 * @param  string  $dest  The stored image in docs/images
 * @return bool True when the visible content changed and the copy should run
 */
function captureVisiblyChanged(string $src, string $dest): bool
{
    // A missing stored image is always a change.
    if (! file_exists($dest)) {
        return true;
    }

    $fresh = @imagecreatefrompng($src);
    $stored = @imagecreatefrompng($dest);
    if ($fresh === false || $stored === false) {
        return true;
    }

    // Different geometry is always a change.
    if (imagesx($fresh) !== imagesx($stored) || imagesy($fresh) !== imagesy($stored)) {
        return true;
    }

    $width = imagesx($fresh);
    $height = imagesy($fresh);
    $block = 4;

    // Average each 4x4 block in both images (weighted luma) and compare the
    // averages; the loop stops at the first visibly different block.
    for ($y = 0; $y + $block <= $height; $y += $block) {
        for ($x = 0; $x + $block <= $width; $x += $block) {
            $freshLuma = 0;
            $storedLuma = 0;
            for ($dy = 0; $dy < $block; $dy++) {
                for ($dx = 0; $dx < $block; $dx++) {
                    $freshPixel = imagecolorat($fresh, $x + $dx, $y + $dy);
                    $storedPixel = imagecolorat($stored, $x + $dx, $y + $dy);
                    $freshLuma += (($freshPixel >> 16) & 0xFF) * 3 + (($freshPixel >> 8) & 0xFF) * 6 + ($freshPixel & 0xFF);
                    $storedLuma += (($storedPixel >> 16) & 0xFF) * 3 + (($storedPixel >> 8) & 0xFF) * 6 + ($storedPixel & 0xFF);
                }
            }

            if (abs($freshLuma - $storedLuma) / ($block * $block * 10) > 40) {
                imagedestroy($fresh);
                imagedestroy($stored);

                return true;
            }
        }
    }

    imagedestroy($fresh);
    imagedestroy($stored);

    return false;
}
