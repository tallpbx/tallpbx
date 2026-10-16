<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use Database\Seeders\AdminSeeder;
use Database\Seeders\SecurityServiceSeeder;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Livewire\SecurityManager;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Services\SecurityConfigGenerator;
use Modules\Security\Services\ThreatFeedIngestionService;
use Modules\Security\Services\ThreatFeedManager;
use Modules\Security\Services\VoipblFeedProvider;

/**
 * Feature tests for the Threat Feeds tab of the Security Center.
 *
 * Covers the status badge, country filtering form, sync interval, on-demand
 * sync, the remove-all-blocks escape hatch, the live kernel drop counter,
 * and the registered manage permission.
 */
beforeEach(function (): void {
    $this->artisan('module:sync --only-local');
    $this->seed(AdminSeeder::class);
    $this->superAdminGroup = Group::where('name', 'Super Administrators')->first();
    $this->admin = Admin::factory()->create(['enabled' => true]);
    $this->admin->groups()->attach($this->superAdminGroup->id);

    $this->seed(SecurityServiceSeeder::class);
    SecuritySetting::set('threat_feed_min_entries', '3');

    $this->tempDir = sys_get_temp_dir().'/tallpbx_feed_ui_test_'.uniqid();
    mkdir($this->tempDir, 0700, true);
    $this->firewallDir = sys_get_temp_dir().'/tallpbx_feed_ui_fw_'.uniqid();
    mkdir($this->firewallDir, 0700, true);

    $this->executor = Mockery::mock(SecurityExecutorInterface::class);
    // The status output is swappable per test: Mockery matches the first
    // declared expectation, so a second shouldReceive('status') in a test
    // would never fire — tests instead reassign this property.
    $this->statusOutput = '';

    $this->executor->shouldReceive('ban')->andReturn(true);
    $this->executor->shouldReceive('unban')->andReturn(true);
    $this->executor->shouldReceive('apply')->andReturn(true);
    $this->executor->shouldReceive('status')->andReturnUsing(fn (): string => $this->statusOutput);
    $this->executor->shouldReceive('flushConntrack')->andReturn(true);
    $this->executor->shouldReceive('updateExternalBlocklist')->byDefault()->andReturn(true);
    $this->executor->shouldReceive('updateThreatFeed')->byDefault()->andReturn(true);
    $this->app->instance(SecurityExecutorInterface::class, $this->executor);

    $ingestion = new ThreatFeedIngestionService($this->firewallDir, $this->tempDir);
    $this->app->instance(ThreatFeedIngestionService::class, $ingestion);
    $this->app->instance(ThreatFeedManager::class, new ThreatFeedManager(new VoipblFeedProvider($ingestion, $this->executor)));

    $generator = Mockery::mock(SecurityConfigGenerator::class)->makePartial();
    $generator->shouldReceive('writePending')->andReturn(sys_get_temp_dir().'/tallpbx-test-firewall.nft.pending');
    $generator->shouldReceive('validateSyntax')->andReturn(true);
    $this->app->instance(SecurityConfigGenerator::class, $generator);
});

afterEach(function (): void {
    foreach ([$this->tempDir, $this->firewallDir] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

it('registers the threat-feeds manage permission', function (): void {
    expect(Permission::where('name', 'security.threat-feeds.manage')->exists())->toBeTrue();
});

it('ships every threat feed label in English, Spanish, and French', function (): void {
    // The tab builds its labels from these keys; a missing translation would
    // render the raw key to an administrator.
    $required = [
        'security_threat_feeds_title',
        'security_threat_feeds_desc',
        'security_threat_feeds_tooltip',
        'security_threat_feed_manage',
        'security_threat_feed_status_active',
        'security_threat_feed_status_idle',
        'security_threat_feed_status_syncing',
        'security_threat_feed_status_error',
        'security_threat_feed_status_stale',
        'security_threat_feed_status_disabled',
        'security_threat_feed_enable',
        'security_threat_feed_disable',
        'security_threat_feed_description',
        'security_threat_feed_whitelist_hint',
        'security_threat_feed_search_url_label',
        'security_threat_feed_raw_url_label',
        'security_threat_feed_cli_hint',
        'security_threat_feed_interval',
        'security_threat_feed_every_hour',
        'security_threat_feed_every_4h',
        'security_threat_feed_every_12h',
        'security_threat_feed_daily',
        'security_threat_feed_ipv4_note',
        'security_threat_feed_metric_entries',
        'security_threat_feed_metric_rejected',
        'security_threat_feed_metric_last_sync',
        'security_threat_feed_metric_never',
        'security_threat_feed_metric_dropped',
        'security_threat_feed_sync_now',
        'security_threat_feed_sync_success',
        'security_threat_feed_sync_uptodate',
        'security_threat_feed_sync_failed',
        'security_threat_feed_remove_blocks',
        'security_threat_feed_remove_blocks_confirm',
        'security_threat_feed_remove_blocks_failed',
        'security_threat_feed_blocks_removed',
        'security_threat_feed_saved',
        'security_threat_feed_enabling',
        'security_threat_feed_disabling',
        'security_threat_feed_syncing',
        'security_threat_feed_removing_blocks',
        'security_threat_feed_enabled_notice',
        'security_threat_feed_disabled_notice',
        'security_threat_feed_downloaded_count',
        'security_threat_feed_in_feed_note',
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

it('renders translated labels instead of raw translation keys', function (): void {
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'threat-feeds')
        // No label may leak its raw key to the administrator.
        ->assertDontSee('admin.security_threat', false)
        ->assertSee('Sync Now');
});

it('renders the threat feeds tab with status, controls, and the IPv4-only note', function (): void {
    SecurityThreatFeed::factory()->create([
        'provider' => 'voipbl',
        'enabled' => true,
        'entries_count' => 12345,
        'last_status' => 'success',
        'last_sync_at' => now()->subMinutes(10),
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'threat-feeds')
        ->assertSee(__('admin.security_threat_feeds_title'))
        ->assertSee(__('admin.security_threat_feed_sync_now'))
        ->assertSee(__('admin.security_threat_feed_remove_blocks'))
        ->assertSee(__('admin.security_threat_feed_ipv4_note'))
        ->assertSee(__('admin.security_threat_feed_search_url_label'))
        ->assertSee(__('admin.security_threat_feed_raw_url_label'))
        ->assertSee('12,345');
});

it('saves sync interval', function (): void {
    $component = Livewire::actingAs($this->admin, 'admin')->test(SecurityManager::class);

    $component->set('activeTab', 'threat-feeds')
        ->set('feedEnabled', true)
        ->set('feedSyncInterval', '12_hours')
        ->call('saveFeedSettings')
        ->assertHasNoErrors();

    $feed = SecurityThreatFeed::where('provider', 'voipbl')->first();
    expect($feed)->not->toBeNull()
        ->and($feed->enabled)->toBeTrue()
        ->and($feed->sync_interval)->toBe('12_hours');
});

it('syncs on demand and reports the compiled entry count', function (): void {
    Http::fake(['www.voipbl.org/*' => Http::response("203.0.113.1/32\n203.0.113.2/32\n203.0.113.3/32\n203.0.113.4/32", 200)]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'threat-feeds')
        ->call('syncThreatFeedNow');

    $feed = SecurityThreatFeed::where('provider', 'voipbl')->first();
    expect($feed->last_status)->toBe('success')
        ->and($feed->entries_count)->toBe(4);

    // The freshly compiled elements were pushed into the kernel.
    $this->executor->shouldHaveReceived('updateExternalBlocklist')->once();
});

it('preserves geo elements when compiling threat feed set files', function (): void {
    // A geo block compiled earlier by the Geo-Blocking service sits in the
    // shared pending pipeline; a feed sync rebuild must keep it alongside
    // its own elements under the shared lock protocol.
    file_put_contents($this->firewallDir.'/geo_blocking.nft.pending', implode("\n", [
        '# TallPBX Geo-Blocking kernel elements — generated 2026-10-16T00:00:00+00:00; do not edit.',
        'flush set inet tallpbx_filter geo_blocked_ips',
        'flush set inet tallpbx_filter geo_blocked_ips6',
        'add element inet tallpbx_filter geo_blocked_ips { 1.0.0.0/24 }',
    ])."\n");

    Http::fake(['www.voipbl.org/*' => Http::response("203.0.113.1/32\n203.0.113.2/32\n203.0.113.3/32\n203.0.113.4/32", 200)]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'threat-feeds')
        ->call('syncThreatFeedNow');

    $content = file_get_contents($this->firewallDir.'/external_blocklist.nft.pending');
    expect($content)->toContain('add element inet tallpbx_filter external_blocklist_ips {')
        ->and($content)->toContain('add element inet tallpbx_filter geo_blocked_ips { 1.0.0.0/24 }')
        ->and(substr_count($content, '# TallPBX Geo-Blocking kernel elements'))->toBe(1);
});

it('removes all feed blocks without disabling the feed', function (): void {
    SecurityThreatFeed::factory()->create([
        'provider' => 'voipbl',
        'enabled' => true,
        'entries_count' => 500,
        'last_status' => 'success',
    ]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'threat-feeds')
        ->call('removeAllFeedBlocks');

    // The kernel sets were flushed through the helper while the feed stays
    // enabled — the documented escape hatch for a provider false positive.
    $this->executor->shouldHaveReceived('updateExternalBlocklist')->once();

    expect(SecurityThreatFeed::where('provider', 'voipbl')->first()->enabled)->toBeTrue()
        ->and(SecurityThreatFeed::where('provider', 'voipbl')->first()->entries_count)->toBe(0);

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'threat_feed_blocks_removed']);
});

it('displays zero active addresses and notes the downloaded feed count when feed is disabled', function (): void {
    SecurityThreatFeed::factory()->create([
        'provider' => 'voipbl',
        'enabled' => false,
        'entries_count' => 12345,
        'last_status' => 'success',
        'last_sync_at' => now()->subMinutes(10),
    ]);

    $test = Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'threat-feeds')
        ->assertSee(__('admin.security_threat_feed_status_disabled'))
        ->assertSee(__('admin.security_threat_feed_downloaded_count', ['count' => '12,345']));

    // In preFilterRows, threat_feeds stage must be disabled with count 0
    $rows = $test->instance()->preFilterRows();
    $threatFeedRow = collect($rows)->firstWhere('key', 'threat_feeds');

    expect($threatFeedRow)->not->toBeNull()
        ->and($threatFeedRow['enabled'])->toBeFalse()
        ->and($threatFeedRow['count'])->toBe(0)
        ->and($threatFeedRow['downloaded_count'])->toBe(12345);
});

it('refuses feed changes without the manage permission', function (): void {
    // An administrator with no groups has none of the security permissions:
    // every threat feed mutation must be refused with a 403.
    $limited = Admin::factory()->create(['enabled' => true]);

    foreach (['saveFeedSettings', 'syncThreatFeedNow', 'removeAllFeedBlocks', 'setFeedEnabled'] as $action) {
        // A fresh component per action: the harness converts the abort(403)
        // into a plain 403 response and the failed state cannot be reused.
        Livewire::actingAs($limited, 'admin')
            ->test(SecurityManager::class)
            ->set('activeTab', 'threat-feeds')
            ->call($action, false)
            ->assertStatus(403);
    }

    // The refusal happened before any database or kernel side effect.
    expect(SecurityThreatFeed::where('provider', 'voipbl')->exists())->toBeFalse();
    $this->executor->shouldNotHaveReceived('updateExternalBlocklist');
});

it('enables and disables external threat feed with immediate kernel synchronization and audit logging', function (): void {
    SecurityThreatFeed::factory()->create([
        'provider' => 'voipbl',
        'enabled' => true,
        'entries_count' => 100,
        'last_status' => 'success',
    ]);

    // Test disabling
    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'threat-feeds')
        ->call('setFeedEnabled', false)
        ->assertSee(__('admin.security_threat_feed_disabled_notice'));

    expect(SecurityThreatFeed::where('provider', 'voipbl')->first()->enabled)->toBeFalse();
    $this->executor->shouldHaveReceived('updateExternalBlocklist')->once();
    $this->assertDatabaseHas('security_audit_logs', ['action' => 'threat_feed_disabled']);

    // Test enabling (with HTTP fake for feed download)
    Http::fake(['www.voipbl.org/*' => Http::response("203.0.113.1/32\n203.0.113.2/32\n203.0.113.3/32\n203.0.113.4/32", 200)]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'threat-feeds')
        ->call('setFeedEnabled', true)
        ->assertSee(__('admin.security_threat_feed_enabled_notice'));

    expect(SecurityThreatFeed::where('provider', 'voipbl')->first()->enabled)->toBeTrue();
    $this->executor->shouldHaveReceived('updateExternalBlocklist')->twice();
    $this->assertDatabaseHas('security_audit_logs', ['action' => 'threat_feed_enabled']);
});

it('renders the feed drop counter from the live kernel status', function (): void {
    $this->statusOutput = "table inet tallpbx_filter {\n"
        ."\tchain input {\n"
        ."\t\tip saddr @external_blocklist_ips counter packets 12345 bytes 678901 drop\n"
        ."\t}\n}";

    SecurityThreatFeed::factory()->create(['provider' => 'voipbl', 'enabled' => true]);

    Livewire::actingAs($this->admin, 'admin')
        ->test(SecurityManager::class)
        ->set('activeTab', 'threat-feeds')
        ->assertSee('12345');
});
