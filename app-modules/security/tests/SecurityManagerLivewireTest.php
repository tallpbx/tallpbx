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
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\FirewallSyncVerifier;
use Modules\Security\Services\SecurityConfigGenerator;
use Modules\Security\Support\FirewallSyncStatus;

/**
 * Feature tests for the SecurityManager unified Livewire component.
 *
 * Tests the single-screen security command center, including IP management,
 * live threat defense, rule sequencing, PBX port catalog, settings drawer,
 * default inbound policy form, and lockout guard.
 */
beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);

    // Seed standard services
    $this->seed(SecurityServiceSeeder::class);

    // Keep privileged host operations out of the test suite: the firewall apply
    // flow must never execute the real bounded helper or write the pending
    // ruleset into /etc/tallpbx on the machine running the tests.
    $executor = Mockery::mock(SecurityExecutorInterface::class);
    $executor->shouldReceive('ban')->andReturn(true);
    $executor->shouldReceive('unban')->andReturn(true);
    $executor->shouldReceive('apply')->andReturn(true);
    $executor->shouldReceive('status')->andReturn('');
    $this->app->instance(SecurityExecutorInterface::class, $executor);

    $generator = Mockery::mock(SecurityConfigGenerator::class);
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);
});

it('mounts and renders the full security command center with plain-English labels', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertOk()
        ->assertSee('Security Center')
        ->assertSee('Firewall Status')
        ->assertSee('Attack Protection')
        ->assertSee('Blocked Attackers')
        ->assertSee('Blacklist IPs')
        ->assertSee('Whitelist IPs')
        ->assertSee('Firewall Rules')
        ->assertSee('Standard Services')
        ->assertSee('Protocol')
        ->assertSee('Port')
        ->assertSee('Rules are checked in order from top to bottom')
        ->assertDontSee('wire:click="refreshStatus"', false)
        ->assertDontSee('wire:click="applyFirewallChanges"', false)
        ->assertDontSee('wire:poll', false);
});

it('detects the administrator IP and allows 1-click whitelisting', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('whitelistCurrentIp')
        ->assertSet('isCurrentIpWhitelisted', true);

    expect(SecurityIpList::where('type', 'whitelist')->where('ip_address', '127.0.0.1')->exists())->toBeTrue();
});

it('switches between trusted and blocked IP lists and adds entries with validation', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('ipListType', 'whitelist')
        // Add trusted IP
        ->set('newIp', '192.168.1.0/24')
        ->set('newIpDescription', 'Headquarters Office')
        ->call('addIp')
        ->assertHasNoErrors()
        ->assertSee('192.168.1.0/24')
        ->assertSee('Headquarters Office');

    expect(SecurityIpList::where('type', 'whitelist')->where('ip_address', '192.168.1.0/24')->exists())->toBeTrue();

    // Switch to blacklist
    $component->call('switchIpListType', 'blacklist')
        ->assertSet('ipListType', 'blacklist')
        ->set('newIp', '203.0.113.50')
        ->set('newIpDescription', 'Known scanner')
        ->call('addIp')
        ->assertHasNoErrors()
        ->assertSee('203.0.113.50');

    expect(SecurityIpList::where('type', 'blacklist')->where('ip_address', '203.0.113.50')->exists())->toBeTrue();
});

it('deletes an IP from the list', function (): void {
    $ip = SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '10.0.0.1',
        'description' => 'Branch Office',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('deleteIp', $ip->id)
        ->assertDontSee('10.0.0.1');

    expect(SecurityIpList::find($ip->id))->toBeNull();
});

it('displays active bans and supports unban, promoteToWhitelist, and promoteToBlacklist', function (): void {
    $ban = SecurityBan::create([
        'ip_address' => '198.51.100.99',
        'vector' => 'sip_auth',
        'reason' => '5 failed SIP registrations',
        'attempt_count' => 5,
        'banned_at' => now(),
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSee('198.51.100.99')
        ->assertSee('Phone (SIP)');

    // 1-click unban
    $component->call('unban', '198.51.100.99');
    expect($ban->fresh()->is_active)->toBeFalse();

    // Re-ban to test promoteToWhitelist
    $ban->update(['is_active' => true]);
    $component->call('promoteToWhitelist', '198.51.100.99');
    expect(SecurityIpList::where('type', 'whitelist')->where('ip_address', '198.51.100.99')->exists())->toBeTrue();

    // Test promoteToBlacklist
    $ban2 = SecurityBan::create([
        'ip_address' => '198.51.100.100',
        'vector' => 'web_auth',
        'reason' => 'Brute force login',
        'attempt_count' => 8,
        'banned_at' => now(),
        'expires_at' => now()->addHour(),
        'is_active' => true,
    ]);

    $component->call('promoteToBlacklist', '198.51.100.100');
    expect(SecurityIpList::where('type', 'blacklist')->where('ip_address', '198.51.100.100')->exists())->toBeTrue();
});

it('applies a manual IP ban through the modal', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openManualBanModal')
        ->assertSet('showManualBanModal', true)
        ->set('manualBanIp', '198.51.100.222')
        ->set('manualBanDuration', 3600)
        ->set('manualBanReason', 'Suspicious port probe')
        ->call('manualBan')
        ->assertSet('showManualBanModal', false);

    expect(SecurityBan::where('ip_address', '198.51.100.222')->where('is_active', true)->exists())->toBeTrue();
});

it('shows a DaisyUI alert instead of throwing when manually banning a whitelisted IP', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.199',
        'description' => 'Protected test address',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openManualBanModal')
        ->set('manualBanIp', '203.0.113.199')
        ->set('manualBanDuration', 3600)
        ->set('manualBanReason', 'Attempted ban of protected IP')
        ->call('manualBan')
        ->assertSet('operationalMessageType', 'error')
        ->assertSee('is whitelisted and cannot be banned')
        ->assertSee('role="alert"', false)
        ->assertSet('showManualBanModal', false);

    expect(SecurityBan::where('ip_address', '203.0.113.199')->exists())->toBeFalse();
});

it('refuses the permanent manual ban option for a whitelisted IP without blacklisting it', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.198',
        'description' => 'Protected test address',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openManualBanModal')
        ->set('manualBanIp', '203.0.113.198')
        ->set('manualBanDuration', -1)
        ->call('manualBan')
        ->assertSet('operationalMessageType', 'error')
        ->assertSee('is whitelisted and cannot be banned')
        ->assertSet('showManualBanModal', false);

    expect(SecurityIpList::where('type', 'blacklist')->where('ip_address', '203.0.113.198')->exists())->toBeFalse();
});

it('shows an inline error when adding a whitelisted IP to the blacklist', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.197',
        'description' => 'Protected test address',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('newBlacklistIp', '203.0.113.197')
        ->set('newBlacklistDescription', 'Attempted block of protected IP')
        ->call('addBlacklistIp')
        ->assertHasErrors('newBlacklistIp')
        ->assertSee('is whitelisted and cannot be blacklisted');

    expect(SecurityIpList::where('type', 'blacklist')->where('ip_address', '203.0.113.197')->exists())->toBeFalse();
});

it('accepts IPv6 entries and rejects malformed addresses in the IP list forms', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class);

    // Full IPv6 support: addresses and CIDR ranges are accepted.
    $component->set('newWhitelistIp', '2001:569:fcd9:900:e95c:2439:5a28:86b')
        ->call('addWhitelistIp')
        ->assertHasNoErrors();

    $component->set('newBlacklistIp', '2001:db8:bad::/48')
        ->call('addBlacklistIp')
        ->assertHasNoErrors();

    expect(SecurityIpList::where('ip_address', '2001:569:fcd9:900:e95c:2439:5a28:86b')->exists())->toBeTrue()
        ->and(SecurityIpList::where('ip_address', '2001:db8:bad::/48')->exists())->toBeTrue();

    // Malformed values of both address families still fail validation.
    $component->set('newWhitelistIp', '344.34.34.34')
        ->call('addWhitelistIp')
        ->assertHasErrors('newWhitelistIp');

    $component->set('newBlacklistIp', '10.0.0.0/99')
        ->call('addBlacklistIp')
        ->assertHasErrors('newBlacklistIp');

    $component->set('newWhitelistIp', '1:::2')
        ->call('addWhitelistIp')
        ->assertHasErrors('newWhitelistIp');

    $component->set('newWhitelistIp', '2001:db8::/999')
        ->call('addWhitelistIp')
        ->assertHasErrors('newWhitelistIp');

    expect(SecurityIpList::where('ip_address', '344.34.34.34')->exists())->toBeFalse()
        ->and(SecurityIpList::where('ip_address', '10.0.0.0/99')->exists())->toBeFalse();
});

it('shows a dismiss button on the feedback toast that clears the message', function (): void {
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '203.0.113.196',
        'description' => 'Protected test address',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openManualBanModal')
        ->set('manualBanIp', '203.0.113.196')
        ->set('manualBanDuration', 3600)
        ->call('manualBan')
        ->assertSee('dismissFeedback', false)
        ->call('dismissFeedback')
        ->assertSet('operationalMessage', null)
        ->assertSet('operationalMessageType', null)
        ->assertDontSee('toast-top', false)
        ->assertDontSee('is whitelisted and cannot be banned');

    expect(session('error'))->toBeNull();
});

it('accepts IPv6 bans and rejects malformed addresses in the manual ban dialog', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openManualBanModal')
        ->set('manualBanDuration', 3600)
        // Malformed input must leave the dialog open so the field can be corrected.
        ->set('manualBanIp', '344.34.34.34')
        ->call('manualBan')
        ->assertSet('showManualBanModal', true)
        ->assertHasErrors('manualBanIp');

    // A valid IPv6 address is banned successfully and closes the dialog.
    $component->set('manualBanIp', '2001:db8::99')
        ->call('manualBan')
        ->assertSet('showManualBanModal', false)
        ->assertSet('operationalMessageType', 'success');

    expect(SecurityBan::where('ip_address', '344.34.34.34')->exists())->toBeFalse()
        ->and(SecurityBan::where('ip_address', '2001:db8::99')->exists())->toBeTrue();
});

it('reorders sequential firewall rules up and down', function (): void {
    $rule1 = SecurityRule::create([
        'sequence' => 10,
        'description' => 'Rule 1',
        'source_ip' => 'any',
        'action' => 'accept',
        'enabled' => true,
    ]);

    $rule2 = SecurityRule::create([
        'sequence' => 20,
        'description' => 'Rule 2',
        'source_ip' => 'any',
        'action' => 'accept',
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('moveRuleDown', $rule1->id);

    expect($rule1->fresh()->sequence)->toBe(20)
        ->and($rule2->fresh()->sequence)->toBe(10);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('moveRuleUp', $rule1->id);

    expect($rule1->fresh()->sequence)->toBe(10)
        ->and($rule2->fresh()->sequence)->toBe(20);
});

it('toggles rule enable state and deletes a rule', function (): void {
    $rule = SecurityRule::create([
        'sequence' => 10,
        'description' => 'Toggle Test',
        'source_ip' => 'any',
        'action' => 'accept',
        'enabled' => true,
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('toggleRule', $rule->id);

    expect($rule->fresh()->enabled)->toBeFalse();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('deleteRule', $rule->id);

    expect(SecurityRule::find($rule->id))->toBeNull();
});

it('adds a standard service rule from the PBX port catalog', function (): void {
    $sipService = SecurityService::where('name', 'SIP Signaling')->first();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('addServiceFromCatalog', $sipService->id);

    $created = SecurityRule::where('service_id', $sipService->id)->first();
    expect($created)->not->toBeNull()
        ->and($created->action)->toBe('accept')
        ->and($created->enabled)->toBeTrue();
});

it('creates and updates a custom firewall rule', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openCustomRuleModal')
        ->assertSet('showRuleModal', true)
        ->set('ruleDescription', 'Allow Custom API')
        ->set('ruleSourceIp', '10.50.0.0/16')
        ->set('ruleCustomPort', '8443')
        ->set('ruleCustomProtocol', 'tcp')
        ->set('ruleAction', 'accept')
        ->call('saveCustomRule')
        ->assertSet('showRuleModal', false);

    $rule = SecurityRule::where('description', 'Allow Custom API')->first();
    expect($rule)->not->toBeNull()
        ->and($rule->custom_port)->toBe('8443')
        ->and($rule->source_ip)->toBe('10.50.0.0/16');
});

it('normalizes colon port ranges to hyphen when saving custom rules and system services', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openCustomRuleModal')
        ->set('ruleDescription', 'Colon Port Range Rule')
        ->set('ruleSourceIp', '10.50.0.0/16')
        ->set('ruleCustomPort', '10000:20000')
        ->set('ruleCustomProtocol', 'tcp')
        ->set('ruleAction', 'accept')
        ->call('saveCustomRule');

    $rule = SecurityRule::where('description', 'Colon Port Range Rule')->first();
    expect($rule)->not->toBeNull()
        ->and($rule->custom_port)->toBe('10000-20000');

    $service = SecurityService::where('name', 'SIP Signaling')->first();
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openEditSystemServiceModal', $service->id)
        ->set('systemServicePortRange', '5060:5070')
        ->call('saveSystemService');

    expect($service->fresh()->port_range)->toBe('5060-5070');
});

it('saves attack protection sensitivity settings through the drawer', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openSettingsDrawer')
        ->assertSet('showSettingsDrawer', true)
        ->set('maxRetry', 3)
        ->set('findTime', 300)
        ->set('banTime', 7200)
        ->set('protectSsh', false)
        // Attempting to set the policy through the drawer must have no effect.
        ->set('firewallDefaultPolicy', 'accept')
        ->call('saveSettings')
        ->assertSet('showSettingsDrawer', false);

    expect(SecuritySetting::get('max_retry'))->toBe('3')
        ->and(SecuritySetting::get('find_time'))->toBe('300')
        ->and(SecuritySetting::get('ban_time'))->toBe('7200')
        ->and(SecuritySetting::getBoolean('protect_ssh'))->toBeFalse()
        // The drawer no longer persists the policy: the property was set to
        // 'accept' above, yet the seeded 'drop' value must remain untouched.
        ->and(SecuritySetting::get('firewall_default_policy'))->toBe('drop');
});

it('saves the default inbound policy through its dedicated Configure form', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openDefaultPolicyForm')
        ->assertSet('showDefaultPolicyModal', true)
        ->set('firewallDefaultPolicy', 'accept')
        ->call('saveDefaultPolicy')
        ->assertSet('showDefaultPolicyModal', false);

    expect(SecuritySetting::get('firewall_default_policy'))->toBe('accept');
});

it('reloads the persisted policy when the dedicated Configure form opens', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('firewallDefaultPolicy', 'drop');

    // Simulate the persisted policy changing elsewhere after the initial load.
    SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => 'accept']);

    $component->call('openDefaultPolicyForm')
        ->assertSet('showDefaultPolicyModal', true)
        ->assertSet('firewallDefaultPolicy', 'accept');
});

it('reverts the persisted default inbound policy when the firewall apply fails', function (): void {
    // Force the privileged helper step to report failure.
    $executorMock = Mockery::mock(SecurityExecutorInterface::class);
    $executorMock->shouldReceive('apply')->once()->andReturnFalse();
    app()->instance(SecurityExecutorInterface::class, $executorMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openDefaultPolicyForm')
        ->set('firewallDefaultPolicy', 'accept')
        ->call('saveDefaultPolicy')
        ->assertSet('showDefaultPolicyModal', false)
        // The UI must snap back to the value the live kernel still runs...
        ->assertSet('firewallDefaultPolicy', 'drop')
        ->assertSet('operationalMessageType', 'error');

    // ...and the refused change must not stay persisted either.
    expect(SecuritySetting::get('firewall_default_policy'))->toBe('drop');
});

it('reports the firewall apply failure when saving drawer settings instead of masking it', function (): void {
    $executorMock = Mockery::mock(SecurityExecutorInterface::class);
    $executorMock->shouldReceive('apply')->once()->andReturnFalse();
    app()->instance(SecurityExecutorInterface::class, $executorMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openSettingsDrawer')
        ->set('maxRetry', 7)
        ->call('saveSettings')
        ->assertSet('showSettingsDrawer', false)
        // The drawer must not overwrite the apply failure with a success toast.
        ->assertSet('operationalMessageType', 'error');

    // Sensitivity thresholds are database-side settings and still persist.
    expect(SecuritySetting::get('max_retry'))->toBe('7');
});

it('shows a firewall drift banner when the live kernel policy differs from the saved policy', function (): void {
    SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => 'accept']);

    $executorMock = Mockery::mock(SecurityExecutorInterface::class);
    $executorMock->shouldReceive('status')->andReturn(
        "table inet tallpbx_filter {\n\tchain input {\n\t\ttype filter hook input priority filter - 10; policy drop;\n\t}\n}"
    );
    app()->instance(SecurityExecutorInterface::class, $executorMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('liveFirewallPolicy', 'drop')
        ->assertSee('Firewall Out of Sync')
        ->assertSee('Re-apply Ruleset');
});

it('does not show the drift banner when the live kernel policy matches the saved policy', function (): void {
    $executorMock = Mockery::mock(SecurityExecutorInterface::class);
    $executorMock->shouldReceive('status')->andReturn(
        "table inet tallpbx_filter {\n\tchain input {\n\t\ttype filter hook input priority filter - 10; policy drop;\n\t}\n}"
    );
    app()->instance(SecurityExecutorInterface::class, $executorMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('liveFirewallPolicy', 'drop')
        ->assertDontSee('Firewall Out of Sync');
});

it('shows the not-loaded warning when the kernel has no TallPBX firewall table', function (): void {
    $executorMock = Mockery::mock(SecurityExecutorInterface::class);
    $executorMock->shouldReceive('status')->andReturn("table inet other_filter {\n}");
    app()->instance(SecurityExecutorInterface::class, $executorMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('liveFirewallPolicy', 'absent')
        ->assertSee('not currently loaded in the Linux kernel');
});

it('keeps the drift banner silent when the live policy cannot be read', function (): void {
    $executorMock = Mockery::mock(SecurityExecutorInterface::class);
    $executorMock->shouldReceive('status')->andReturn('');
    app()->instance(SecurityExecutorInterface::class, $executorMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('liveFirewallPolicy', null)
        ->assertDontSee('Firewall Out of Sync');
});

it('clears the drift banner after a successful re-apply', function (): void {
    SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => 'accept']);

    $executorMock = Mockery::mock(SecurityExecutorInterface::class);
    $executorMock->shouldReceive('status')->andReturn(
        "table inet tallpbx_filter {\n\tchain input {\n\t\ttype filter hook input priority filter - 10; policy drop;\n\t}\n}"
    );
    $executorMock->shouldReceive('apply')->once()->andReturnTrue();
    app()->instance(SecurityExecutorInterface::class, $executorMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSee('Firewall Out of Sync')
        ->call('applyFirewallChanges')
        ->assertSet('liveFirewallPolicy', 'accept')
        ->assertDontSee('Firewall Out of Sync');
});

it('displays verified badge when firewall sync is verified', function (): void {
    $verifierMock = Mockery::mock(FirewallSyncVerifier::class);
    $verifierMock->shouldReceive('verify')->andReturn(
        new FirewallSyncStatus(
            state: 'verified',
            issues: [],
            desiredDigest: 'abc123',
            appliedDigest: 'abc123',
            appliedPolicy: 'drop',
            appliedAt: '2026-09-20T12:00:00Z',
        )
    );
    app()->instance(FirewallSyncVerifier::class, $verifierMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('firewallSyncState', 'verified')
        ->assertSet('firewallSyncAppliedAt', '2026-09-20T12:00:00Z')
        ->assertSee('Verified')
        ->assertDontSee('Firewall Out of Sync');
});

it('displays drift banner with content message when FirewallSyncVerifier reports ruleset drift', function (): void {
    $verifierMock = Mockery::mock(FirewallSyncVerifier::class);
    $verifierMock->shouldReceive('verify')->andReturn(
        new FirewallSyncStatus(
            state: 'drift',
            issues: ['Applied ruleset digest does not match current desired configuration.'],
            desiredDigest: 'abc123',
            appliedDigest: 'def456',
            appliedPolicy: 'drop',
            appliedAt: '2026-09-20T12:00:00Z',
        )
    );
    app()->instance(FirewallSyncVerifier::class, $verifierMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('firewallSyncState', 'drift')
        ->assertSee('Firewall Out of Sync')
        ->assertSee(__('admin.security_drift_content_body'));
});

it('displays unverified badge when firewall sync status is unknown', function (): void {
    $verifierMock = Mockery::mock(FirewallSyncVerifier::class);
    $verifierMock->shouldReceive('verify')->andReturn(
        FirewallSyncStatus::unknown(['No verified apply record found.'])
    );
    app()->instance(FirewallSyncVerifier::class, $verifierMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('firewallSyncState', 'unknown')
        ->assertSee('Unverified');
});

it('reports the apply failure instead of a success message when adding a whitelist entry', function (): void {
    $executorMock = Mockery::mock(SecurityExecutorInterface::class);
    $executorMock->shouldReceive('status')->andReturn('');
    $executorMock->shouldReceive('apply')->once()->andReturnFalse();
    app()->instance(SecurityExecutorInterface::class, $executorMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('newWhitelistIp', '203.0.113.210')
        ->set('newWhitelistDescription', 'Honest apply test')
        ->call('addWhitelistIp')
        // The kernel refused the apply, so no success message may be shown.
        ->assertSet('operationalMessageType', 'error');
});

it('reports the apply failure instead of a success message when toggling a rule', function (): void {
    $rule = SecurityRule::create([
        'sequence' => 30,
        'description' => 'Honest toggle rule',
        'source_ip' => 'any',
        'custom_port' => '9443',
        'custom_protocol' => 'tcp',
        'action' => 'accept',
        'enabled' => true,
    ]);

    $executorMock = Mockery::mock(SecurityExecutorInterface::class);
    $executorMock->shouldReceive('status')->andReturn('');
    $executorMock->shouldReceive('apply')->once()->andReturnFalse();
    app()->instance(SecurityExecutorInterface::class, $executorMock);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('toggleRule', $rule->id)
        ->assertSet('operationalMessageType', 'error');
});

it('applies firewall changes atomically via executor when lockout safe', function (): void {
    $executorMock = Mockery::mock(SecurityExecutorInterface::class);
    $executorMock->shouldReceive('apply')->once()->andReturn(true);
    app()->instance(SecurityExecutorInterface::class, $executorMock);

    // Whitelist admin IP first so lockout preflight passes
    SecurityIpList::create([
        'type' => 'whitelist',
        'ip_address' => '127.0.0.1',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('applyFirewallChanges')
        ->assertSet('pendingChangesCount', 0);
});

it('reactively updates status upon receiving refresh-security or echo push events without manual page reload', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSet('firewallDefaultPolicy', 'drop');

    SecuritySetting::updateOrCreate(['key' => 'firewall_default_policy'], ['value' => 'accept']);

    $component->dispatch('refresh-security')
        ->assertSet('firewallDefaultPolicy', 'accept');
});

it('renders the unified firewall rules table with pipeline stages and core PBX services', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertSee('Blacklist IPs')
        ->assertSee('@blacklist_ips')
        ->assertSee('Blocked Attackers')
        ->assertSee('@banned_ips')
        ->assertSee('Whitelist IPs')
        ->assertSee('@whitelist_ips')
        ->assertSee('Blacklist')
        ->assertSee('Whitelist')
        ->assertSee('Custom Rules')
        ->assertSee('Standard Services')
        ->assertSee('Default Inbound Policy')
        ->assertSee('SIP Signaling')
        ->assertSee('RTP Voice/Video Media')
        ->assertSee('Web Admin Portal')
        ->assertSee('SSH Console');
});

it('opens, edits, and saves a core PBX system service with custom port and source restrictions', function (): void {
    $service = SecurityService::where('name', 'FreeSWITCH ESL')->first();

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openEditSystemServiceModal', $service->id)
        ->assertSet('showSystemServiceModal', true)
        ->assertSet('systemServiceName', 'FreeSWITCH ESL')
        ->assertSet('systemServicePortRange', '8021')
        ->set('systemServicePortRange', '8022')
        ->set('systemServiceSourceIp', '10.8.0.0/24')
        ->call('saveSystemService')
        ->assertSet('showSystemServiceModal', false);

    expect($service->fresh()->port_range)->toBe('8022')
        ->and($service->fresh()->source_ip)->toBe('10.8.0.0/24');
});

it('prevents administrator lockout when modifying Web Admin Portal without whitelisting', function (): void {
    $webService = SecurityService::where('name', 'Web Admin Portal')->first();

    // Admin IP is 203.0.113.88 (not loopback, not whitelisted)
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class);

    $component->set('adminIp', '203.0.113.88')
        ->call('openEditSystemServiceModal', $webService->id)
        // Try restricting Web Admin away from 203.0.113.88
        ->set('systemServiceSourceIp', '10.0.0.0/8')
        ->call('saveSystemService')
        ->assertSee('Zero-Lockout Safety Alert');

    // Verify service was NOT modified
    expect($webService->fresh()->source_ip)->not->toBe('10.0.0.0/8');
});

it('toggles a core PBX service and resets it to factory defaults', function (): void {
    $webrtc = SecurityService::where('name', 'WebRTC WSS')->first();
    expect($webrtc->enabled)->toBeTrue();

    // 1. Toggle disabled
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('toggleSystemService', $webrtc->id);

    expect($webrtc->fresh()->enabled)->toBeFalse();

    // 2. Modify port
    $webrtc->update(['port_range' => '9999']);

    // 3. Reset to default
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('resetSystemServiceToDefault', $webrtc->id);

    expect($webrtc->fresh()->port_range)->toBe('7443')
        ->and($webrtc->fresh()->enabled)->toBeTrue();
});

it('manages blacklist and whitelist simultaneously in sequential pipeline cards', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        // Add to Blacklist
        ->set('newBlacklistIp', '198.51.100.25')
        ->set('newBlacklistDescription', 'Aggressive probe')
        ->call('addBlacklistIp')
        ->assertHasNoErrors()
        ->assertSee('198.51.100.25')
        ->assertSee('Aggressive probe')
        // Add to Whitelist
        ->set('newWhitelistIp', '192.0.2.10')
        ->set('newWhitelistDescription', 'Branch Office Router')
        ->call('addWhitelistIp')
        ->assertHasNoErrors()
        ->assertSee('192.0.2.10')
        ->assertSee('Branch Office Router');

    expect(SecurityIpList::where('type', 'blacklist')->where('ip_address', '198.51.100.25')->exists())->toBeTrue()
        ->and(SecurityIpList::where('type', 'whitelist')->where('ip_address', '192.0.2.10')->exists())->toBeTrue();
});
