<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use App\Models\Group;
use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Livewire\Livewire;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Livewire\SecurityManager;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Feature tests for the hardened TFTP defense profile in the Security Center.
 *
 * The Firewall Rules tab renders the shield badge, the on/off toggle, and
 * the expandable per-rule counters on the TFTP Provisioning row; the toggle
 * persists the setting, records the audit entry, and re-applies the ruleset.
 */
beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);

    $this->seed(SecurityServiceSeeder::class);

    $this->statusOutput = '';

    $this->executor = Mockery::mock(SecurityExecutorInterface::class);
    $this->executor->shouldReceive('ban')->andReturn(true);
    $this->executor->shouldReceive('unban')->andReturn(true);
    $this->executor->shouldReceive('apply')->andReturn(true);
    $this->executor->shouldReceive('status')->andReturnUsing(fn (): string => $this->statusOutput);
    $this->executor->shouldReceive('flushConntrack')->andReturn(true);
    $this->app->instance(SecurityExecutorInterface::class, $this->executor);

    $generator = Mockery::mock(SecurityConfigGenerator::class)->makePartial();
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);
});

it('renders the hardened tftp shield badge with live per-rule counters', function (): void {
    $this->statusOutput = "table inet tallpbx_filter {\n"
        ."\tchain input {\n"
        ."\t\tudp dport 69 @th,64,16 0x0002 counter packets 4137 bytes 165480 drop\n"
        ."\t\tudp dport 69 @th,64,16 0x0001 @th,80,24 0x2e2e2f counter packets 7357 bytes 294280 drop\n"
        ."\t\tudp dport 69 @th,64,16 0x0001 @th,80,16 0x2f78 counter packets 3131 bytes 125240 drop\n"
        ."\t\tudp dport 69 update @tftp_flood4 { ip saddr limit rate over 10/minute burst 20 packets } counter packets 1971 bytes 78840 drop\n"
        ."\t}\n}";

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'firewall-rules')
        ->assertSet('tftpDefenseEnabled', true)
        ->assertSee(__('admin.security_tftp_defense_label'))
        ->assertSee(__('admin.security_tftp_defense_tooltip'))
        ->assertSeeHtml('wire:target="setTftpDefense"')
        ->assertSeeHtml('wire:loading.attr="disabled"')
        ->assertSeeHtml('wire:loading.class="opacity-70 pointer-events-none"')
        ->assertSeeHtml('loading loading-spinner')
        ->assertSee(__('admin.security_tftp_disabling'))
        // Per-rule counters: uploads, traversal, probes, and the flood sum.
        ->assertSee('4137')
        ->assertSee('7357')
        ->assertSee('3131')
        ->assertSee('1971');
});

it('parses the per-rule counters from the live kernel status', function (): void {
    $this->statusOutput = "table inet tallpbx_filter {\n"
        ."\tchain input {\n"
        ."\t\tudp dport 69 @th,64,16 0x0002 counter packets 41 bytes 5000 drop\n"
        ."\t\tudp dport 69 @th,64,16 0x0001 @th,80,24 0x2e2e2f counter packets 7 bytes 700 drop\n"
        ."\t\tudp dport 69 @th,64,16 0x0001 @th,80,16 0x2f78 counter packets 3 bytes 300 drop\n"
        ."\t\tudp dport 69 update @tftp_flood4 { ip saddr limit rate over 10/minute burst 20 packets } counter packets 11 bytes 1100 drop\n"
        ."\t\tudp dport 69 update @tftp_flood6 { ip6 saddr limit rate over 10/minute burst 20 packets } counter packets 8 bytes 800 drop\n"
        ."\t}\n}";

    expect((new SecurityManager)->tftpDefenseCounters())->toBe([
        'uploads' => 41,
        'traversal' => 7,
        'probes' => 3,
        // Both families' flood meters are summed into one administrator-facing number.
        'flood' => 19,
    ]);
});

it('reports null counters when the kernel status is unavailable', function (): void {
    expect((new SecurityManager)->tftpDefenseCounters())->toBe([
        'uploads' => null,
        'traversal' => null,
        'probes' => null,
        'flood' => null,
    ]);
});

it('toggles the hardened tftp defense profile, audits it, and re-applies the ruleset', function (): void {
    // Turn the profile off: the defensive rules are removed but TFTP
    // provisioning itself stays reachable.
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('setTftpDefense', false)
        ->assertSet('tftpDefenseEnabled', false);

    expect(SecuritySetting::get('tftp_defense_enabled'))->toBe('0');
    $this->assertDatabaseHas('security_audit_logs', ['action' => 'tftp_defense_disabled']);
    $this->executor->shouldHaveReceived('apply')->once();

    // Turn it back on: defaults re-engage and the kernel is re-armed.
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('setTftpDefense', true)
        ->assertSet('tftpDefenseEnabled', true);

    expect(SecuritySetting::get('tftp_defense_enabled'))->toBe('1');
    $this->assertDatabaseHas('security_audit_logs', ['action' => 'tftp_defense_enabled']);
    $this->executor->shouldHaveReceived('apply')->twice();
});

it('ships every tftp defense label in English, Spanish, and French', function (): void {
    $required = [
        'security_tftp_defense_label',
        'security_tftp_defense_tooltip',
        'security_tftp_defense_rate_value',
        'security_tftp_counter_uploads',
        'security_tftp_counter_traversal',
        'security_tftp_counter_probes',
        'security_tftp_counter_flood',
        'security_tftp_defense_saved',
        'security_tftp_enabling',
        'security_tftp_disabling',
    ];

    foreach (['en', 'es', 'fr'] as $locale) {
        $lines = require lang_path($locale.'/admin.php');
        $missing = array_values(array_diff($required, array_keys($lines)));

        expect($missing)->toBe([], 'Missing keys in '.$locale.': '.implode(', ', $missing));
    }
});
