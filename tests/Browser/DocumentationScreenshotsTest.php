<?php

declare(strict_types=1);

namespace Tests\Browser;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Tenant;
use App\Models\User;
use Laravel\Dusk\Browser;
use Modules\Devices\Models\Device;
use Modules\Extensions\Models\Extension;
use Tests\DuskTestCase;

/**
 * Captures clean, high-resolution UI screenshots for documentation.
 *
 * Covers:
 *   - Landing page (Light and Dark theme)
 *   - Dashboard (Full sidebar, compressed mini-rail, horizontal topbar)
 *   - Representative PBX sections (Tenants, Devices, Extensions)
 */
class DocumentationScreenshotsTest extends DuskTestCase
{
    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function testCaptureDocumentationScreenshots(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->resize(1440, 900);

            // 1. Landing page — Light Theme
            $browser->visit('/en')
                ->waitForText('FreeSWITCH Telephone Platform', 10)
                ->script([
                    "localStorage.setItem('theme', 'light');",
                    "document.documentElement.setAttribute('data-theme', 'light');",
                ]);
            $browser->pause(1200)
                ->screenshot('landing-light');

            // 2. Landing page — Dark Theme
            $browser->script([
                "localStorage.setItem('theme', 'dark');",
                "document.documentElement.setAttribute('data-theme', 'dark');",
            ]);
            $browser->pause(1200)
                ->screenshot('landing-dark');

            // 3. Unified Panel Dashboard — Full Sidebar
            $this->admin->update([
                'theme' => 'light',
                'layout_mode' => 'sidebar',
                'sidebar_collapsed' => false,
            ]);
            $browser->loginAs($this->admin, 'admin')
                ->visit('/panel/dashboard')
                ->waitForText('Dashboard', 10)
                ->pause(1500)
                ->screenshot('dashboard-full-sidebar');

            // 4. Unified Panel Dashboard — Compressed Sidebar (Mini Icon Rail)
            $this->admin->update([
                'layout_mode' => 'sidebar',
                'sidebar_collapsed' => true,
            ]);
            $browser->visit('/panel/dashboard')
                ->waitForText('Dashboard', 10)
                ->pause(1500)
                ->screenshot('dashboard-compressed-sidebar');

            // 5. Unified Panel Dashboard — Horizontal Topbar Menu
            $this->admin->update([
                'layout_mode' => 'horizontal',
                'sidebar_collapsed' => false,
            ]);
            $browser->visit('/panel/dashboard')
                ->waitForText('Dashboard', 10)
                ->pause(1500)
                ->screenshot('dashboard-horizontal-menu');

            // Reset layout back to standard full sidebar for remaining section pages
            $this->admin->update([
                'layout_mode' => 'sidebar',
                'sidebar_collapsed' => false,
            ]);

            // 6. PBX Tenants
            $browser->visit('/panel/tenants')
                ->waitForText('Tenants', 10)
                ->pause(1200)
                ->screenshot('pbx-tenants');

            // 7. PBX Devices
            $browser->visit('/panel/devices')
                ->waitForText('Devices', 10)
                ->pause(1200)
                ->screenshot('pbx-devices');

            // 8. PBX Extensions
            $browser->visit('/panel/extensions')
                ->waitForText('Extensions', 10)
                ->pause(1200)
                ->screenshot('pbx-extensions');

            // 9. User Impersonation & Support View
            $browser->visit('/panel/users')
                ->waitForText('Users', 10)
                ->waitForText('john.doe@acme.test', 10)
                ->click('form[action*="impersonate"] button')
                ->waitForText('Stop Impersonating', 10)
                ->pause(1200)
                ->screenshot('pbx-impersonation');

            // 10. Multi-Language Switcher
            $browser->press('Stop Impersonating')
                ->waitForText('Dashboard', 10)
                ->click('div[x-data*="locales"] button')
                ->pause(600)
                ->screenshot('pbx-multi-language');
        });

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
            $src = base_path("tests/Browser/screenshots/{$img}");
            $dest = base_path("docs/images/{$img}");
            if (file_exists($src)) {
                copy($src, $dest);
            }
        }
    }
}
