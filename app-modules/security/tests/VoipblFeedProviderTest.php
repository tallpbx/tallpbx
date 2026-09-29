<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Models\SecurityThreatFeed;
use Modules\Security\Services\ThreatFeedIngestionService;
use Modules\Security\Services\ThreatFeedManager;
use Modules\Security\Services\VoipblFeedProvider;

/**
 * Feature tests for the VoIPBL provider and the streaming ingestion service.
 *
 * Every test runs against throwaway firewall and temp directories so the
 * live host state in /etc/tallpbx is never read or rewritten, and the
 * minimum-entry guard is lowered so small fixture downloads count as valid.
 */
beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/tallpbx_feed_test_'.uniqid();
    mkdir($this->tempDir, 0700, true);
    $this->firewallDir = sys_get_temp_dir().'/tallpbx_feed_fw_'.uniqid();
    mkdir($this->firewallDir, 0700, true);

    SecuritySetting::set('threat_feed_min_entries', '3');

    $ingestion = new ThreatFeedIngestionService($this->firewallDir, $this->tempDir);
    $this->provider = new VoipblFeedProvider($ingestion);
});

afterEach(function (): void {
    foreach ([$this->tempDir, $this->firewallDir] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

it('formats the VoIPBL query URL for every country mode', function (): void {
    expect($this->provider->fetchUrl(SecurityThreatFeed::factory()->make(['country_mode' => 'all'])))
        ->toBe('https://www.voipbl.org/update/');

    expect($this->provider->fetchUrl(SecurityThreatFeed::factory()->make([
        'country_mode' => 'blacklist',
        'countries' => ['cn', 'RU', 'kr'],
    ])))->toBe('https://www.voipbl.org/update/?bc=CN,RU,KR');

    expect($this->provider->fetchUrl(SecurityThreatFeed::factory()->make([
        'country_mode' => 'whitelist',
        'countries' => ['us', 'CA', 'gb'],
    ])))->toBe('https://www.voipbl.org/update/?wc=US,CA,GB');

    expect($this->provider->identifier())->toBe('voipbl')
        ->and($this->provider->name())->toBe('VoIPBL');
});

it('compiles a successful download into chunked set-element statements', function (): void {
    // 300 genuinely valid IPv4 ranges (octets stay inside 1–250), one valid
    // IPv6 range, and one line of garbage for the rejection counter.
    $body = implode("\n", array_map(
        fn (int $i): string => sprintf('203.0.%d.%d/32', intdiv($i - 1, 250), (($i - 1) % 250) + 1),
        range(1, 300)
    ))."\nnot-an-ip\n2001:db8::/48\n";

    Http::fake(['www.voipbl.org/*' => Http::response($body, 200, [
        'ETag' => '"abc123"',
        'Last-Modified' => 'Tue, 22 Sep 2026 00:00:00 GMT',
    ])]);

    $feed = SecurityThreatFeed::factory()->create(['enabled' => true]);
    $result = $this->provider->sync($feed);

    // 300 valid IPv4 ranges plus one IPv6 range; one line rejected as garbage.
    expect($result->status)->toBe('success')
        ->and($result->entriesCount)->toBe(301)
        ->and($result->rejectedLines)->toBe(1);

    $feed->refresh();
    expect($feed->last_status)->toBe('success')
        ->and($feed->entries_count)->toBe(301)
        ->and($feed->last_rejected_lines)->toBe(1)
        ->and($feed->etag)->toBe('"abc123"')
        ->and($feed->last_sync_at)->not->toBeNull();

    // The compiled file is a set-element file: flush statements first, then
    // add-element statements chunked so no line outgrows the nft lexer.
    $pending = $this->firewallDir.'/threat_feed.nft.pending';
    expect(file_exists($pending))->toBeTrue();

    $content = (string) file_get_contents($pending);
    expect($content)->toContain('flush set inet tallpbx_filter threat_feed_ips')
        ->and($content)->toContain('flush set inet tallpbx_filter threat_feed_ips6')
        ->and($content)->toContain('2001:db8::/48');

    $addLines = array_values(array_filter(
        explode("\n", $content),
        fn (string $line): bool => str_starts_with($line, 'add element')
    ));

    // 300 IPv4 entries chunk into 256 + 44; the single IPv6 entry into one.
    expect($addLines)->toHaveCount(3);

    foreach ($addLines as $line) {
        expect(substr_count($line, ',') + 1)->toBeLessThanOrEqual(256);
    }
});

it('updates only the check timestamp when the server reports not modified', function (): void {
    Http::fake(['www.voipbl.org/*' => Http::response('', 304)]);

    $feed = SecurityThreatFeed::factory()->create([
        'enabled' => true,
        'etag' => '"cached"',
        'last_status' => 'success',
        'entries_count' => 4321,
        'last_sync_at' => now()->subHours(3),
    ]);

    $result = $this->provider->sync($feed);

    expect($result->status)->toBe('not_modified');

    $feed->refresh();
    expect($feed->last_status)->toBe('not_modified')
        ->and($feed->entries_count)->toBe(4321)
        ->and($feed->etag)->toBe('"cached"')
        ->and($feed->last_sync_at->gt(now()->subMinute()))->toBeTrue();
});

it('sends conditional headers and gzip acceptance, and force bypasses the cache', function (): void {
    $body = "203.0.113.1/32\n203.0.113.2/32\n203.0.113.3/32";

    Http::fake(['www.voipbl.org/*' => Http::response($body, 200)]);

    $feed = SecurityThreatFeed::factory()->create([
        'enabled' => true,
        'etag' => '"etag-1"',
        'last_modified_header' => 'Tue, 22 Sep 2026 00:00:00 GMT',
    ]);

    $this->provider->sync($feed);

    Http::assertSent(fn (Request $request): bool => $request->hasHeader('If-None-Match', '"etag-1"')
        && $request->hasHeader('If-Modified-Since', 'Tue, 22 Sep 2026 00:00:00 GMT')
        && $request->hasHeader('Accept-Encoding', 'gzip'));

    Http::fake(['www.voipbl.org/*' => Http::response($body, 200)]);
    $this->provider->sync($feed, force: true);

    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('If-None-Match')
        && ! $request->hasHeader('If-Modified-Since'));
});

it('fails open and keeps the previous list when the download fails', function (): void {
    $pending = $this->firewallDir.'/threat_feed.nft.pending';
    file_put_contents($pending, "OLD GOOD LIST\n");

    Http::fake(['www.voipbl.org/*' => Http::response('server exploded', 500)]);

    $feed = SecurityThreatFeed::factory()->create(['enabled' => true, 'entries_count' => 99999]);
    $result = $this->provider->sync($feed);

    expect($result->status)->toBe('failed')
        ->and((string) $result->error)->toContain('500');

    $feed->refresh();
    expect($feed->last_status)->toBe('failed')
        ->and((string) $feed->last_error)->toContain('500')
        // The last good entry count and list survive a broken download.
        ->and($feed->entries_count)->toBe(99999);

    expect(file_get_contents($pending))->toBe("OLD GOOD LIST\n");
});

it('treats rate limiting as a failed sync that keeps the loaded list', function (): void {
    $pending = $this->firewallDir.'/threat_feed.nft.pending';
    file_put_contents($pending, "OLD GOOD LIST\n");

    Http::fake(['www.voipbl.org/*' => Http::response('slow down', 429)]);

    $feed = SecurityThreatFeed::factory()->create(['enabled' => true]);
    $result = $this->provider->sync($feed);

    expect($result->status)->toBe('failed')
        ->and((string) $result->error)->toContain('429');

    expect(file_get_contents($pending))->toBe("OLD GOOD LIST\n");
});

it('rejects truncated downloads that fall below the minimum entry count', function (): void {
    $pending = $this->firewallDir.'/threat_feed.nft.pending';
    file_put_contents($pending, "OLD GOOD LIST\n");

    Http::fake(['www.voipbl.org/*' => Http::response("203.0.113.9/32\n", 200)]);

    $feed = SecurityThreatFeed::factory()->create(['enabled' => true]);
    $result = $this->provider->sync($feed);

    expect($result->status)->toBe('failed')
        ->and((string) $result->error)->toContain('minimum');

    expect(file_get_contents($pending))->toBe("OLD GOOD LIST\n");
});

it('routes sync requests to the registered provider through the manager', function (): void {
    Http::fake(['www.voipbl.org/*' => Http::response("203.0.113.1/32\n203.0.113.2/32\n203.0.113.3/32", 200)]);

    $manager = new ThreatFeedManager($this->provider);

    expect($manager->providerFor('voipbl'))->toBe($this->provider)
        ->and($manager->providerFor('mystery'))->toBeNull();

    $feed = SecurityThreatFeed::factory()->create(['enabled' => true]);
    expect($manager->sync($feed)->status)->toBe('success');

    $unknown = SecurityThreatFeed::factory()->make(['provider' => 'mystery']);
    $result = $manager->sync($unknown);

    expect($result->status)->toBe('failed')
        ->and((string) $result->error)->toContain('mystery');
});
