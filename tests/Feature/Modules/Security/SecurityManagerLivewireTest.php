<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use App\Models\Group;
use Livewire\Livewire;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Livewire\SecurityManager;
use Modules\Security\Models\SecurityBan;
use Modules\Security\Models\SecurityIpList;
use Modules\Security\Models\SecurityRule;
use Modules\Security\Models\SecurityService;
use Modules\Security\Models\SecuritySetting;

/**
 * Feature tests for the SecurityManager unified Livewire component.
 *
 * Tests the single-screen security command center, including IP management,
 * live threat defense, rule sequencing, PBX port catalog, settings drawer, and lockout guard.
 */

beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(\Database\Seeders\AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);

    // Seed standard services
    $this->seed(\Database\Seeders\SecurityServiceSeeder::class);
});

it('mounts and renders the full security command center with plain-English labels', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->assertOk()
        ->assertSee('Security Center')
        ->assertSee('Firewall Status')
        ->assertSee('Attack Protection')
        ->assertSee('Currently Blocked Attackers')
        ->assertSee('Your Connection')
        ->assertSee('Trusted & Blocked IP Addresses')
        ->assertSee('Firewall Rules & Port Access')
        ->assertSee('Standard PBX Ports')
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

it('saves attack protection sensitivity settings through the drawer', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->call('openSettingsDrawer')
        ->assertSet('showSettingsDrawer', true)
        ->set('maxRetry', 3)
        ->set('findTime', 300)
        ->set('banTime', 7200)
        ->set('protectSsh', false)
        ->set('firewallDefaultPolicy', 'drop')
        ->call('saveSettings')
        ->assertSet('showSettingsDrawer', false);

    expect(SecuritySetting::get('max_retry'))->toBe('3')
        ->and(SecuritySetting::get('find_time'))->toBe('300')
        ->and(SecuritySetting::get('ban_time'))->toBe('7200')
        ->and(SecuritySetting::getBoolean('protect_ssh'))->toBeFalse()
        ->and(SecuritySetting::get('firewallDefaultPolicy'))->toBeNull() // key is firewall_default_policy
        ->and(SecuritySetting::get('firewall_default_policy'))->toBe('drop');
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
