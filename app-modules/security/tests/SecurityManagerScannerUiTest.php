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
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityConfigGenerator;
use Modules\Security\Support\SipScannerSignatures;

/**
 * Feature tests for the SIP Bot & Scanner Signatures card and the enhanced
 * bans table in the Attackers tab.
 *
 * Covers the enforcement toggle (ships off), the ban duration selector, the
 * read-only tier groups, custom signature add/remove with validation, the
 * detected-but-unblocked incident list with its Add-to-auto-ban action, and
 * the vector/reason display on banned rows.
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

it('renders the scanner card with tier groups, the off-by-default toggle, durations, and the NAT note', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    // Not rendered outside the Attackers tab.
    $component->assertDontSee(__('admin.security_scanner_title'));

    $component->set('activeTab', 'attackers')
        ->assertSee(__('admin.security_scanner_title'))
        ->assertSee(__('admin.security_scanner_desc'))
        ->assertSet('sipScannerEnforcement', false)
        // Both tier groups are visible with their curated members (monitored by default).
        ->assertSee(__('admin.security_scanner_high_group_monitored'))
        ->assertSee('friendly-scanner')
        ->assertSee('sipvicious')
        ->assertSee('sipcli')
        ->assertSee('Ozeki')
        ->assertSee(__('admin.security_scanner_low_group'))
        ->assertSee('SIP Call')
        // Ban duration selector and the custom-signature input.
        ->assertSee(__('admin.security_scanner_duration_24h'))
        ->assertSee(__('admin.security_scanner_duration_permanent'))
        ->assertSee(__('admin.security_scanner_add'))
        ->assertSee(__('admin.security_scanner_incidents'))
        ->assertSee(__('admin.security_scanner_incidents_empty'));
});

it('toggles enforcement, audits it, and never rewrites the firewall ruleset', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers')
        ->call('setSipScannerEnforcement', true)
        ->assertSet('sipScannerEnforcement', true)
        ->assertSee(__('admin.security_scanner_high_group_active'));

    expect(SecuritySetting::getBoolean('sip_scanner_enforcement_enabled'))->toBeTrue();
    $this->assertDatabaseHas('security_audit_logs', ['action' => 'sip_scanner_enforcement_enabled']);

    // The toggle gates future bans only — it is not a firewall change.
    $this->executor->shouldNotHaveReceived('apply');

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers')
        ->call('setSipScannerEnforcement', false)
        ->assertSet('sipScannerEnforcement', false)
        ->assertSee(__('admin.security_scanner_high_group_monitored'));

    expect(SecuritySetting::getBoolean('sip_scanner_enforcement_enabled'))->toBeFalse();
    $this->assertDatabaseHas('security_audit_logs', ['action' => 'sip_scanner_enforcement_disabled']);
});

it('persists the documented ban durations and refuses unknown ones', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers');

    foreach ([3600, 86400, 604800, 0] as $seconds) {
        $component->call('setSipScannerBanSeconds', (string) $seconds)
            ->assertSet('sipScannerBanSeconds', $seconds)
            ->assertSet('operationalMessageType', 'success');

        expect(SecuritySetting::getInt('sip_scanner_ban_seconds'))->toBe($seconds);
    }

    // An out-of-range choice is refused outright and the last good value stays.
    $component->call('setSipScannerBanSeconds', '1234')
        ->assertSet('sipScannerBanSeconds', 0)
        ->assertSet('operationalMessageType', 'error');

    expect(SecuritySetting::getInt('sip_scanner_ban_seconds'))->toBe(0);
});

it('adds a custom signature, audits it, and clears the input', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers')
        ->set('newScannerSignature', '  Zoiper  ')
        ->call('addScannerSignature')
        ->assertHasNoErrors()
        ->assertSet('newScannerSignature', '')
        ->assertSet('operationalMessageType', 'success');

    expect(SipScannerSignatures::custom())->toBe(['Zoiper']);
    $this->assertDatabaseHas('security_audit_logs', ['action' => 'sip_scanner_signature_added']);
});

it('refuses duplicates of built-in and custom signatures with an inline error', function (): void {
    SipScannerSignatures::saveCustom(['Zoiper']);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers');

    // Case-insensitive collision with the curated base list.
    $component->set('newScannerSignature', 'SIPVICIOUS')
        ->call('addScannerSignature')
        ->assertHasErrors('newScannerSignature');

    // Case-insensitive collision with the existing custom list.
    $component->set('newScannerSignature', 'zoiper')
        ->call('addScannerSignature')
        ->assertHasErrors('newScannerSignature');

    expect(SipScannerSignatures::custom())->toBe(['Zoiper']);
});

it('refuses malformed custom signatures with an inline error', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers');

    foreach (["bad\nagent", str_repeat('x', 65), ''] as $invalid) {
        $component->set('newScannerSignature', $invalid)
            ->call('addScannerSignature')
            ->assertHasErrors('newScannerSignature');
    }

    expect(SipScannerSignatures::custom())->toBe([]);
});

it('removes a stored custom signature case-insensitively and refuses unknown removals', function (): void {
    SipScannerSignatures::saveCustom(['Zoiper', 'Yealink']);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers');

    $component->call('removeScannerSignature', 'zoiper')
        ->assertSet('operationalMessageType', 'success');

    expect(SipScannerSignatures::custom())->toBe(['Yealink']);
    $this->assertDatabaseHas('security_audit_logs', ['action' => 'sip_scanner_signature_removed']);

    // Only existing entries can be removed.
    $component->call('removeScannerSignature', 'NotStored')
        ->assertSet('operationalMessageType', 'error');

    expect(SipScannerSignatures::custom())->toBe(['Yealink']);
});

it('lists detected-but-unblocked incidents and shows vector plus reason on banned rows', function (): void {
    // A recorded incident from the record-only rollout.
    SecurityBan::create([
        'ip_address' => '203.0.113.60',
        'vector' => 'sip_scanner',
        'reason' => "Scanner signature match: User-Agent = 'SIP Call' (confidence: low)",
        'attempt_count' => 3,
        'banned_at' => now(),
        'is_active' => false,
    ]);

    // An enforced scanner ban shown in the attackers table.
    SecurityBan::create([
        'ip_address' => '203.0.113.61',
        'vector' => 'sip_scanner',
        'reason' => "Scanner signature match: User-Agent = 'friendly-scanner' (confidence: high)",
        'attempt_count' => 1,
        'banned_at' => now(),
        'expires_at' => now()->addDay(),
        'is_active' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers')
        ->assertSee(__('admin.security_scanner_incidents'))
        ->assertSee(__('admin.security_scanner_ban_ip'))
        // The detected incident is listed...
        ->assertSee('203.0.113.60')
        // ...and the enforced ban carries its vector label and reason.
        ->assertSee(__('admin.security_vector_sip_scanner'))
        ->assertSee('friendly-scanner');
});

it('promotes a detected incident to the auto-ban list through the ban service', function (): void {
    SecuritySetting::set('sip_scanner_ban_seconds', 604800);

    SecurityBan::create([
        'ip_address' => '203.0.113.62',
        'vector' => 'sip_scanner',
        'reason' => "Scanner signature match: User-Agent = 'Generic Softphone' (confidence: low)",
        'attempt_count' => 2,
        'banned_at' => now(),
        'is_active' => false,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers')
        ->call('promoteScannerIncident', '203.0.113.62')
        ->assertSet('operationalMessageType', 'success');

    // The real ban service wrote the active row (through the mocked kernel).
    $ban = SecurityBan::where('ip_address', '203.0.113.62')->where('is_active', true)->first();
    expect($ban)->not->toBeNull()
        ->and($ban->vector)->toBe('sip_scanner')
        ->and($ban->reason)->toContain('Generic Softphone')
        ->and($ban->expires_at)->not->toBeNull();

    $this->executor->shouldHaveReceived('ban')->once();
    $this->assertDatabaseHas('security_audit_logs', ['action' => 'ban_created']);

    // Unknown incidents are refused without invoking the ban service.
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers')
        ->call('promoteScannerIncident', '203.0.113.99')
        ->assertSet('operationalMessageType', 'error');
});

it('ships every scanner label in English, Spanish, and French', function (): void {
    $required = [
        'security_scanner_title',
        'security_scanner_desc',
        'security_scanner_enforcement',
        'security_scanner_enforcement_help',
        'security_scanner_enforcement_help_on',
        'security_scanner_enforcement_help_off',
        'security_scanner_duration',
        'security_scanner_duration_1h',
        'security_scanner_duration_24h',
        'security_scanner_duration_7d',
        'security_scanner_duration_permanent',
        'security_scanner_duration_invalid',
        'security_scanner_autoban_group',
        'security_scanner_record_group',
        'security_scanner_high_group_active',
        'security_scanner_high_group_monitored',
        'security_scanner_low_group',
        'security_scanner_custom',
        'security_scanner_custom_empty',
        'security_scanner_custom_help',
        'security_scanner_add',
        'security_scanner_signature_invalid',
        'security_scanner_signature_duplicate',
        'security_scanner_signature_added',
        'security_scanner_signature_removed',
        'security_scanner_incidents',
        'security_scanner_incidents_help',
        'security_scanner_incidents_empty',
        'security_scanner_add_to_ban',
        'security_scanner_ban_ip',
        'security_scanner_incident_banned',
        'security_scanner_incident_missing',
        'security_scanner_incident_refused',
        'security_scanner_saved',
        'security_vector_sip_scanner',
    ];

    $bannedIpKeys = [
        'security_banned_attackers_desc',
        'security_banned_attackers_cli_hint',
        'security_manual_ban_desc',
        'security_no_attackers_help',
    ];

    $allRequired = array_merge($required, $bannedIpKeys);

    foreach (['en', 'es', 'fr'] as $locale) {
        $lines = require lang_path($locale.'/admin.php');
        $missing = array_values(array_diff($allRequired, array_keys($lines)));

        expect($missing)->toBe([], 'Missing keys in '.$locale.': '.implode(', ', $missing));
    }
});

it('explains in the UI where banned IPs are put in the firewall', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'attackers')
        ->assertSee(__('admin.security_banned_attackers_desc'))
        ->assertSee(__('admin.security_banned_attackers_cli_hint'))
        ->assertSee('sudo nft list set inet tallpbx_filter banned_ips')
        ->assertSee('@banned_ips')
        ->assertSee(__('admin.security_no_attackers_help'))
        ->call('openManualBanModal')
        ->assertSee(__('admin.security_manual_ban_desc'));
});
