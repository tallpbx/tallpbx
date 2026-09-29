<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Illuminate\Support\Facades\Http;
use Mockery;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Services\ThreatFeedIngestionService;
use Modules\Security\Services\ThreatFeedManager;
use Modules\Security\Services\VoipblFeedProvider;

/**
 * Feature tests for the scheduled threat feed sync command.
 *
 * The command honours each feed's own sync interval on an hourly schedule,
 * clears kernel elements the moment a feed is disabled, records staleness
 * discovered after an outage, and never bypasses the fail-open checks.
 */
beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/tallpbx_feed_cmd_test_'.uniqid();
    mkdir($this->tempDir, 0700, true);
    $this->firewallDir = sys_get_temp_dir().'/tallpbx_feed_cmd_fw_'.uniqid();
    mkdir($this->firewallDir, 0700, true);

    SecuritySetting::set('threat_feed_min_entries', '3');

    $this->ingestion = new ThreatFeedIngestionService($this->firewallDir, $this->tempDir);
    $this->provider = new VoipblFeedProvider($this->ingestion);

    // Keep the privileged helper out of the test run; the command's kernel
    // step is observable through this mock.
    $this->executor = Mockery::mock(SecurityExecutorInterface::class);
    $this->executor->shouldReceive('updateThreatFeed')->andReturn(true);
    $this->app->instance(SecurityExecutorInterface::class, $this->executor);

    $this->app->instance(ThreatFeedIngestionService::class, $this->ingestion);
    $this->app->instance(ThreatFeedManager::class, new ThreatFeedManager($this->provider));
});

afterEach(function (): void {
    foreach ([$this->tempDir, $this->firewallDir] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

it('syncs a feed that is due and skips one that is not', function (): void {
    Http::fake(['www.voipbl.org/*' => Http::response("203.0.113.1/32\n203.0.113.2/32\n203.0.113.3/32", 200)]);

    $due = SecurityThreatFeed::factory()->create([
        'provider' => 'voipbl',
        'enabled' => true,
        'sync_interval' => 'hourly',
        'last_sync_at' => now()->subHours(2),
    ]);

    $notDue = SecurityThreatFeed::factory()->create([
        // A different driver that is never due, so the unique-per-provider
        // constraint holds and no HTTP request is expected for it.
        'provider' => 'apiban',
        'enabled' => true,
        'sync_interval' => 'daily',
        'last_sync_at' => now()->subMinutes(30),
    ]);

    $this->artisan('security:sync-threat-feeds')->assertSuccessful();

    // The hourly feed is past its interval and syncs; the daily feed is not.
    expect($due->fresh()->last_status)->toBe('success')
        ->and($due->fresh()->entries_count)->toBe(3)
        ->and($notDue->fresh()->last_sync_at->lt(now()->subMinutes(29)))->toBeTrue();

    Http::assertSentCount(1);
});

it('force bypasses the interval without bypassing the fail-open checks', function (): void {
    Http::fake(['www.voipbl.org/*' => Http::response('server exploded', 500)]);

    $feed = SecurityThreatFeed::factory()->create([
        'provider' => 'voipbl',
        'enabled' => true,
        'sync_interval' => 'daily',
        'last_sync_at' => now()->subMinutes(5),
    ]);

    $this->artisan('security:sync-threat-feeds --force')->assertSuccessful();

    // Forced means \"attempt now\", never \"accept broken downloads\".
    expect($feed->fresh()->last_status)->toBe('failed');
    Http::assertSentCount(1);
});

it('filters to a single feed with the --feed option', function (): void {
    Http::fake(['www.voipbl.org/*' => Http::response("203.0.113.1/32\n203.0.113.2/32\n203.0.113.3/32", 200)]);

    SecurityThreatFeed::factory()->create([
        'provider' => 'voipbl',
        'enabled' => true,
        'sync_interval' => 'hourly',
        'last_sync_at' => now()->subHours(2),
    ]);

    $this->artisan('security:sync-threat-feeds --feed=voipbl')->assertSuccessful();

    Http::assertSentCount(1);

    $this->artisan('security:sync-threat-feeds --feed=apiban')->assertSuccessful();
    Http::assertSentCount(1);
});

it('clears kernel elements when a feed is disabled and arms an immediate sync on re-enable', function (): void {
    // The disable transition must flush the kernel sets even when the sync
    // was never attempted from this command before.
    $feed = SecurityThreatFeed::factory()->create([
        'provider' => 'voipbl',
        'enabled' => false,
        'last_status' => 'success',
    ]);

    $this->artisan('security:sync-threat-feeds')->assertSuccessful();

    expect($feed->fresh()->last_status)->toBe('disabled');

    // The disable transition flushed the kernel sets through the helper.
    $this->executor->shouldHaveReceived('updateThreatFeed')->once();

    $this->assertDatabaseHas('security_audit_logs', ['action' => 'threat_feed_disabled']);

    // Re-enabling triggers an attempt on the next tick even inside the
    // interval window, before the sets are repopulated.
    Http::fake(['www.voipbl.org/*' => Http::response("203.0.113.1/32\n203.0.113.2/32\n203.0.113.3/32", 200)]);

    $feed->update(['enabled' => true, 'last_sync_at' => now()->subMinutes(5), 'sync_interval' => 'daily']);

    $this->artisan('security:sync-threat-feeds')->assertSuccessful();

    expect($feed->fresh()->last_status)->toBe('success');
});

it('records a staleness audit entry after an outage and still attempts the sync', function (): void {
    Http::fake(['www.voipbl.org/*' => Http::response("203.0.113.1/32\n203.0.113.2/32\n203.0.113.3/32", 200)]);

    // Hourly feed last attempted three hours ago: the scheduler missed two
    // full intervals — that is silent rot and must be visible in the audit.
    $feed = SecurityThreatFeed::factory()->create([
        'provider' => 'voipbl',
        'enabled' => true,
        'sync_interval' => 'hourly',
        'last_sync_at' => now()->subHours(3),
    ]);

    $this->artisan('security:sync-threat-feeds')->assertSuccessful();

    $this->assertDatabaseHas('security_audit_logs', [
        'action' => 'threat_feed_stale',
    ]);

    expect($feed->fresh()->last_status)->toBe('success');
});

it('reports an empty configuration without failing', function (): void {
    $this->artisan('security:sync-threat-feeds')
        ->expectsOutputToContain('No threat feeds are configured')
        ->assertSuccessful();
});
