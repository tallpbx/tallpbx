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
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\Firewall\SecurityConfigGenerator;
use Modules\Security\Services\Firewall\SecurityExecutor;

/**
 * Feature tests for the Firewall Rules workbench in the Security Manager.
 *
 * Consolidates pre-filter switches, global observe-mode toggle, pre-filter stage
 * reordering, hardened TFTP defense profile, and kernel status read budget.
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

/*
|--------------------------------------------------------------------------
| Pre-Filter and Firewall Switches & Policy Tests
|--------------------------------------------------------------------------
*/

it('disables the pre-filter when the administrator stays reachable and the policy allows', function (): void {
    SecuritySetting::set('firewall_default_policy', 'accept');

    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.10',
        'description' => 'Admin uplink',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', false)
        ->assertSet('prefilterEnabled', false);

    expect(SecuritySetting::getBoolean('prefilter_enabled', true))->toBeFalse()
        ->and(session('error'))->toBeNull();

    $this->executor->shouldHaveReceived('apply')->once();
});

it('refuses to disable the pre-filter when the administrator would be dropped', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', false)
        ->assertSet('prefilterEnabled', true);

    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain('203.0.113.10')
        ->and(SecuritySetting::getBoolean('prefilter_enabled', true))->toBeTrue();

    $this->executor->shouldNotHaveReceived('apply');
});

it('refuses to disable the pre-filter when local services would be severed', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.10',
        'description' => 'Admin uplink',
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', false)
        ->assertSet('prefilterEnabled', true);

    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain(__('admin.security_prefilter_local_services_refused'))
        ->and(SecuritySetting::getBoolean('prefilter_enabled', true))->toBeTrue();

    $this->executor->shouldNotHaveReceived('apply');
});

it('refuses to disable the pre-filter when whitelist geo-blocking is armed', function (): void {
    SecuritySetting::set('firewall_default_policy', 'accept');

    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.10',
        'description' => 'Admin uplink',
    ]);

    $rule = SecurityGeoRule::instance();
    $rule->enabled = true;
    $rule->mode = 'whitelist';
    $rule->countries = ['US'];
    $rule->save();

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', false)
        ->assertSet('prefilterEnabled', true);

    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain(__('admin.security_geo_prefilter_required'))
        ->and(SecuritySetting::getBoolean('prefilter_enabled', true))->toBeTrue();

    $this->executor->shouldNotHaveReceived('apply');
});

it('allows disabling the pre-filter when custom lo and ct rules exist', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.10',
        'description' => 'Admin uplink',
    ]);

    SecurityRule::create([
        'sequence' => 10,
        'description' => 'Custom loopback accept',
        'source_ip' => '127.0.0.1',
        'action' => 'accept',
        'enabled' => true,
    ]);

    SecurityRule::create([
        'sequence' => 20,
        'description' => 'Custom ct state established accept',
        'source_ip' => 'any',
        'action' => 'accept',
        'enabled' => true,
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', false)
        ->assertSet('prefilterEnabled', false);

    expect($component->get('operationalMessageType'))->toBe('success')
        ->and(SecuritySetting::getBoolean('prefilter_enabled', true))->toBeFalse();

    $this->executor->shouldHaveReceived('apply')->once();
});

it('records an audit entry when the pre-filter is switched off', function (): void {
    SecuritySetting::set('firewall_default_policy', 'accept');

    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.10',
        'description' => 'Admin uplink',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', false);

    $this->assertDatabaseHas('security_audit_logs', [
        'action' => 'prefilter_disabled',
        'ip_address' => '203.0.113.10',
    ]);
});

it('re-enables the pre-filter without a lockout check', function (): void {
    SecuritySetting::set('prefilter_enabled', false);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setPrefilterEnabled', true)
        ->assertSet('prefilterEnabled', true);

    expect(SecuritySetting::getBoolean('prefilter_enabled', true))->toBeTrue();

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'prefilter_enabled']);
});

it('turns observe mode on and records an audit entry', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('setObserveMode', true)
        ->assertSet('firewallObserveMode', true);

    expect(SecuritySetting::getBoolean('firewall_observe_mode', false))->toBeTrue();

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'observe_mode_enabled']);
});

it('refuses to turn observe mode off when enforcement would drop the administrator', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setObserveMode', false)
        ->assertSet('firewallObserveMode', true);

    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain('203.0.113.10')
        ->and(SecuritySetting::getBoolean('firewall_observe_mode', false))->toBeTrue();

    $this->executor->shouldNotHaveReceived('apply');
});

it('refuses to leave observe mode while the pre-filter is off and the default policy blocks', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);
    SecuritySetting::set('prefilter_enabled', false);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('setObserveMode', false)
        ->assertSet('firewallObserveMode', true);

    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain(__('admin.security_observe_local_services_refused'))
        ->and(SecuritySetting::getBoolean('firewall_observe_mode', false))->toBeTrue();

    $this->executor->shouldNotHaveReceived('apply');
});

it('refuses to switch the default policy to blocking while the pre-filter is off', function (): void {
    SecuritySetting::set('firewall_default_policy', 'accept');
    SecuritySetting::set('prefilter_enabled', false);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openDefaultPolicyForm')
        ->set('firewallDefaultPolicy', 'drop')
        ->call('saveDefaultPolicy');

    expect($component->get('operationalMessageType'))->toBe('error')
        ->and((string) $component->get('operationalMessage'))->toContain(__('admin.security_default_policy_local_services_refused'))
        ->and(SecuritySetting::get('firewall_default_policy'))->toBe('accept');

    $this->executor->shouldNotHaveReceived('apply');
});

it('turns observe mode off when the administrator IP stays reachable', function (): void {
    SecuritySetting::set('firewall_observe_mode', true);
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.10',
        'description' => 'Admin uplink',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setObserveMode', false)
        ->assertSet('firewallObserveMode', false);

    expect(SecuritySetting::getBoolean('firewall_observe_mode', false))->toBeFalse();

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'observe_mode_disabled']);
});

it('ships the local-services refusal translations in English, Spanish, and French', function (): void {
    $required = [
        'security_prefilter_local_services_refused',
        'security_observe_local_services_refused',
        'security_default_policy_local_services_refused',
    ];

    foreach (['en', 'es', 'fr'] as $locale) {
        $moduleFile = __DIR__.'/../lang/'.$locale.'/admin.php';
        $lines = file_exists($moduleFile)
            ? array_merge(require lang_path($locale.'/admin.php'), require $moduleFile)
            : require lang_path($locale.'/admin.php');
        $missing = array_values(array_diff($required, array_keys($lines)));

        expect($missing)->toBe([], 'Missing keys in '.$locale.': '.implode(', ', $missing));
    }
});

it('rolls back the master firewall switch when the guard refuses the change', function (): void {
    SecuritySetting::set('firewall_enabled', false);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('adminIp', '203.0.113.10')
        ->call('setFirewallEnabled', true)
        ->assertSet('firewallEnabled', false);

    expect($component->get('operationalMessageType'))->toBe('error')
        ->and(SecuritySetting::getBoolean('firewall_enabled', true))->toBeFalse();

    $this->executor->shouldNotHaveReceived('apply');
});

/*
|--------------------------------------------------------------------------
| Pre-Filter Reordering Actions Tests
|--------------------------------------------------------------------------
*/

it('moves a pre-filter stage down and back up, persisting the order', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    $component->call('movePreFilterDown', 'invalid');

    expect(json_decode((string) SecuritySetting::get('pre_filter_order'), true))
        ->toBe(['loopback', 'whitelist', 'fast_path', 'invalid', 'blacklist', 'banned', 'threat_feeds']);

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'pre_filter_reordered']);

    $component->call('movePreFilterUp', 'invalid');

    expect(json_decode((string) SecuritySetting::get('pre_filter_order'), true))
        ->toBe(SecurityConfigGenerator::DEFAULT_PRE_FILTER_ORDER);
});

it('refuses to move the pinned loopback stage', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    $component->call('movePreFilterDown', 'loopback');

    expect(SecuritySetting::get('pre_filter_order'))->toBeNull()
        ->and($component->get('operationalMessageType'))->toBe('error');
});

it('refuses a move that would put a drop stage above the whitelist', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    $component->call('movePreFilterDown', 'whitelist');

    expect(SecuritySetting::get('pre_filter_order'))->toBeNull()
        ->and((string) $component->get('operationalMessage'))->toContain('whitelist');
});

it('rejects an unknown stage name', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    $component->call('movePreFilterUp', 'bogus_stage');

    expect(SecuritySetting::get('pre_filter_order'))->toBeNull()
        ->and($component->get('operationalMessageType'))->toBe('error');
});

it('resets the stored order to the recommended default', function (): void {
    SecuritySetting::set('pre_filter_order', json_encode([
        'loopback', 'whitelist', 'fast_path', 'invalid', 'banned', 'blacklist', 'threat_feeds',
    ]));

    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);
    $component->call('resetPreFilterOrder');

    expect(json_decode((string) SecuritySetting::get('pre_filter_order'), true))
        ->toBe(SecurityConfigGenerator::DEFAULT_PRE_FILTER_ORDER);

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'pre_filter_order_reset']);
});

/*
|--------------------------------------------------------------------------
| Hardened TFTP Defense Profile & Counters Tests
|--------------------------------------------------------------------------
*/

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
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('setTftpDefense', false)
        ->assertSet('tftpDefenseEnabled', false);

    expect(SecuritySetting::get('tftp_defense_enabled'))->toBe('0');
    $this->assertDatabaseHas('security_audit_logs', ['action' => 'tftp_defense_disabled']);
    $this->executor->shouldHaveReceived('apply')->once();

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
        $moduleFile = __DIR__.'/../lang/'.$locale.'/admin.php';
        $lines = file_exists($moduleFile)
            ? array_merge(require lang_path($locale.'/admin.php'), require $moduleFile)
            : require lang_path($locale.'/admin.php');
        $missing = array_values(array_diff($required, array_keys($lines)));

        expect($missing)->toBe([], 'Missing keys in '.$locale.': '.implode(', ', $missing));
    }
});

/*
|--------------------------------------------------------------------------
| Live Kernel Status Query Budget Test
|--------------------------------------------------------------------------
*/

it('queries the kernel status helper at most once when rendering and switching tabs', function (): void {
    $stubDir = sys_get_temp_dir().'/tallpbx_status_budget_'.uniqid();
    mkdir($stubDir, 0700, true);
    $counterFile = $stubDir.'/runs.log';
    $stubHelper = $stubDir.'/stub-helper';
    file_put_contents(
        $stubHelper,
        "#!/bin/bash\n".
        "if [ \"\$1\" = \"status\" ]; then\n".
        "    echo run >> {$counterFile}\n".
        "    echo 'table inet tallpbx_filter {'\n".
        "    echo '}'\n".
        "    exit 0\n".
        "fi\n".
        "exit 1\n"
    );
    chmod($stubHelper, 0755);

    $this->app->instance(SecurityExecutorInterface::class, new SecurityExecutor($stubHelper));

    try {
        $helperRuns = fn (): int => is_file($counterFile)
            ? substr_count((string) file_get_contents($counterFile), 'run')
            : 0;

        Livewire::actingAs($this->admin, 'admin')
            ->test(SecurityManager::class)
            ->assertOk();

        expect($helperRuns())->toBeLessThanOrEqual(1);

        Livewire::actingAs($this->admin, 'admin')
            ->test(SecurityManager::class)
            ->set('activeTab', 'firewall-rules')
            ->assertOk()
            ->set('activeTab', 'attackers')
            ->assertOk();

        expect($helperRuns())->toBeLessThanOrEqual(1);
    } finally {
        @unlink($stubHelper);
        @unlink($counterFile);
        @rmdir($stubDir);
    }
});

it('opens and cancels pre-filter order reset confirmation modal', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'firewall-rules'])
        ->assertSet('confirmingResetPreFilterOrder', false)
        ->call('confirmResetPreFilterOrder')
        ->assertSet('confirmingResetPreFilterOrder', true)
        ->assertSee(__('admin.security_prefilter_reset'))
        ->call('cancelResetPreFilterOrder')
        ->assertSet('confirmingResetPreFilterOrder', false);
});

it('renders every pre-filter row action in the same neutral colour regardless of subsystem or status', function (): void {
    // The row indicators (status dot, action badge) already carry the semantic
    // colour, so the cross-tab "manage" buttons must not add a second, rival
    // colour coding of their own.
    SecurityIpList::create(['type' => 'whitelist', 'ip_address' => '198.51.100.7', 'description' => 'Office']);
    SecurityIpList::create(['type' => 'blacklist', 'ip_address' => '203.0.113.9', 'description' => 'Scanner']);
    SecuritySetting::set('attack_protection_enabled', '1');

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'firewall-rules']);

    // Every pre-filter stage that offers a cross-tab action must share one
    // neutral styling, whether the subsystem allows, drops, or is disabled.
    $managed = collect($component->instance()->preFilterRows)
        ->filter(fn (array $row): bool => $row['manage'] !== null);

    expect($managed->count())->toBeGreaterThanOrEqual(4)
        ->and($managed->every(fn (array $row): bool => ($row['manage']['class'] ?? null) === null))->toBeTrue();

    // No row action button may be tinted with a semantic colour token.
    $component
        ->assertDontSeeHtml('btn-square text-error')
        ->assertDontSeeHtml('btn-square text-success')
        ->assertDontSeeHtml('btn-square text-primary');

    $html = $component->html();

    // All cross-section links are rendered dimmed (softer than plain
    // base-content), so an undimmed variant must never appear.
    expect(substr_count($html, 'btn btn-ghost btn-xs btn-square'))->toBeGreaterThanOrEqual(4)
        ->and(substr_count($html, 'btn btn-ghost btn-xs btn-square text-base-content/70'))
        ->toBe(substr_count($html, 'btn btn-ghost btn-xs btn-square'));
});

it('dims every row edit control to the same brightness as the pre-filter row links', function (): void {
    // Administrators read the whole pipeline as one control surface, so the
    // pencil links in the standard services and custom rules sections must
    // carry the same softened tone as the pre-filter cross-section links.
    SecurityRule::create([
        'sequence' => 100,
        'description' => 'Allow office workstation',
        'source_ip' => '198.51.100.23',
        'action' => 'accept',
        'enabled' => true,
    ]);

    $html = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'firewall-rules'])
        ->html();

    // Every edit control shares the pre-filter tone, and no edit control is
    // rendered at full base-content brightness.
    expect(substr_count($html, 'text-base-content/70 hover:text-base-content'))
        ->toBeGreaterThanOrEqual(6)
        ->and(substr_count($html, 'openEditSystemServiceModal'))->toBeGreaterThanOrEqual(1)
        ->and(substr_count($html, 'openCustomRuleModal('))->toBeGreaterThanOrEqual(1);

    foreach (['openEditSystemServiceModal', 'openCustomRuleModal('] as $editAction) {
        preg_match_all('/<(button|a)\b[^>]*'.preg_quote($editAction, '/').'[^>]*>/i', $html, $matches);

        expect($matches[0])->not->toBeEmpty()
            ->and(collect($matches[0])->every(
                fn (string $tag): bool => str_contains($tag, 'text-base-content/70')
            ))->toBeTrue("edit control {$editAction} is not dimmed like the pre-filter links");
    }
});

it('aligns reorder, edit, and delete controls in one shared column grid in every section', function (): void {
    // Row controls must read as one grid across every section of the pipeline
    // table: a reorder column, a primary-action column (edit / manage /
    // configure), and the destructive delete column, each in its own lane.
    SecurityIpList::create(['type' => 'whitelist', 'ip_address' => '198.51.100.7', 'description' => 'Office']);
    SecurityIpList::create(['type' => 'blacklist', 'ip_address' => '203.0.113.9', 'description' => 'Scanner']);

    SecurityRule::create([
        'sequence' => 10,
        'description' => 'Block scanner subnet',
        'source_ip' => '203.0.113.0/24',
        'custom_protocol' => 'udp',
        'custom_port' => '5060',
        'action' => 'drop',
        'enabled' => true,
    ]);

    $html = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class, ['activeTab' => 'firewall-rules'])
        ->html();

    // Every rule row now ends with one shared trailing grid: three equal-width,
    // centered action columns — reorder | primary action | delete — so the icons
    // line up vertically across every section. We assert the stable `w-10` column
    // structure rather than a layout CSS class, which is brittle to restyling.
    $actionColumns = substr_count($html, '<td class="text-center px-1 w-10">');

    expect($actionColumns)->toBeGreaterThanOrEqual(12)
        ->and($actionColumns % 3)->toBe(0, 'every rule row must render exactly three action columns');

    // All non-destructive controls (reorder, edit, manage, configure) share one
    // square icon-button size and the same softened neutral tone in every section.
    expect(substr_count($html, 'btn btn-ghost btn-xs btn-square text-base-content/70 hover:text-base-content'))
        ->toBeGreaterThanOrEqual(4);

    // The delete control is the single intentionally destructive affordance and
    // carries the semantic error colour.
    expect($html)->toContain('btn btn-ghost btn-xs btn-square text-error hover:text-error/80');

    // Sections without a reorder or delete affordance reserve the empty lane with
    // a muted en-dash placeholder so the columns stay uniform across the whole table.
    expect(substr_count($html, 'text-base-content/30 font-mono'))
        ->toBeGreaterThanOrEqual(4);

    // The redundant always-on IPv4 & IPv6 dual-stack badge was removed from every row.
    expect($html)->not->toContain(__('admin.security_dual_stack_badge'));

    // Row status may only dim the row (opacity) or use a subtle disabled tint; it
    // must not band the table with a per-stage background. Assert no literal
    // `bg-base-200/5` token (negative lookahead keeps the unrelated `bg-base-200/50`
    // info callout from matching the substring).
    expect(preg_match('/bg-base-200\/5(?![0-9])/', $html))->toBe(0);

    // Within the action grid the destructive delete column sits furthest right: its
    // cell must come after the edit cell of the same rule row (scoped to the action
    // cells so the row-level wire:target, which lists both handlers, cannot skew it).
    preg_match_all('/<td class="text-center px-1 w-10">.*?<\/td>/s', $html, $actionCells);

    $editIndex = null;
    $deleteIndex = null;
    foreach ($actionCells[0] as $index => $cell) {
        if ($editIndex === null && str_contains($cell, 'openCustomRuleModal(')) {
            $editIndex = $index;
        }
        if (str_contains($cell, 'deleteRule(')) {
            $deleteIndex = $index;
        }
    }

    expect($editIndex)->not->toBeNull()
        ->and($deleteIndex)->not->toBeNull()
        ->and($deleteIndex)->toBeGreaterThan((int) $editIndex);

    // Built-in / kernel-invariant copy belongs to the name column, never an action lane.
    expect($actionCells[0])->not->toBeEmpty()
        ->and(collect($actionCells[0])->every(
            fn (string $cell): bool => ! str_contains($cell, __('admin.security_kernel_invariant'))
        ))->toBeTrue('built-in notes must not sit inside an action column');
});
