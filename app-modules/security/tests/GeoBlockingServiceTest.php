<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Mockery;
use Modules\Security\Contracts\GeoBlockingProviderInterface;
use Modules\Security\Contracts\SecurityExecutorInterface;
use Modules\Security\Models\SecurityGeoRule;
use Modules\Security\Services\GeoBlockingService;

beforeEach(function (): void {
    $this->tempDir = sys_get_temp_dir().'/tallpbx_geo_test_'.uniqid();
    mkdir($this->tempDir, 0700, true);
    $this->cacheDir = sys_get_temp_dir().'/tallpbx_geo_cache_'.uniqid();
    mkdir($this->cacheDir, 0700, true);

    $this->executor = Mockery::mock(SecurityExecutorInterface::class);
    $this->executor->shouldReceive('updateExternalBlocklist')->byDefault()->andReturn(true);

    $this->provider = Mockery::mock(GeoBlockingProviderInterface::class);

    $this->geoService = new GeoBlockingService(
        $this->provider,
        $this->executor,
        $this->tempDir,
        $this->cacheDir
    );
});

afterEach(function (): void {
    foreach ([$this->tempDir, $this->cacheDir] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
});

it('syncs country ranges, writes cache zones and compiles set element statements', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update([
        'enabled' => true,
        'mode' => 'blacklist',
        'port_scope' => 'all_traffic',
        'countries' => ['US'],
    ]);

    $this->provider->shouldReceive('fetchCountryRanges')
        ->with('US')
        ->once()
        ->andReturn([
            'v4' => ['1.0.0.0/24'],
            'v6' => ['2600::/12'],
        ]);

    $result = $this->geoService->sync($rule, force: true);

    expect($result['status'])->toBe('success')
        ->and($result['v4_count'])->toBe(1)
        ->and($result['v6_count'])->toBe(1);

    expect(file_exists($this->tempDir.'/external_blocklist.nft.pending'))->toBeTrue();

    $content = file_get_contents($this->tempDir.'/external_blocklist.nft.pending');
    expect($content)->toContain('flush set inet tallpbx_filter geo_blocked_ips')
        ->and($content)->toContain('add element inet tallpbx_filter geo_blocked_ips { 1.0.0.0/24 }')
        ->and($content)->toContain('add element inet tallpbx_filter geo_blocked_ips6 { 2600::/12 }');
});

it('clears kernel geo sets when clear is called', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update(['enabled' => false, 'countries' => ['US']]);

    $this->geoService->clear();

    $content = file_get_contents($this->tempDir.'/external_blocklist.nft.pending');
    expect($content)->toContain('flush set inet tallpbx_filter geo_blocked_ips')
        ->and($content)->toContain('flush set inet tallpbx_filter geo_allowed_ips')
        ->and($content)->not->toContain('add element inet tallpbx_filter geo_blocked_ips');
});

it('only flushes target mode sets during sync to prevent drop window during mode switch (DM-3)', function (): void {
    $rule = SecurityGeoRule::instance();

    // 1. Blacklist sync should only flush geo_blocked_ips, never geo_allowed_ips
    $rule->update([
        'enabled' => true,
        'mode' => 'blacklist',
        'countries' => ['US'],
    ]);

    $this->provider->shouldReceive('fetchCountryRanges')
        ->with('US')
        ->andReturn([
            'v4' => ['1.0.0.0/24'],
            'v6' => [],
        ]);

    $this->geoService->sync($rule, force: true);

    $content = file_get_contents($this->tempDir.'/external_blocklist.nft.pending');
    expect($content)->toContain('flush set inet tallpbx_filter geo_blocked_ips')
        ->and($content)->toContain('flush set inet tallpbx_filter geo_blocked_ips6')
        ->and($content)->not->toContain('flush set inet tallpbx_filter geo_allowed_ips');

    // 2. Whitelist sync should only flush geo_allowed_ips, never geo_blocked_ips
    $rule->update([
        'enabled' => true,
        'mode' => 'whitelist',
        'countries' => ['US'],
    ]);

    $this->geoService->sync($rule, force: true);

    $content = file_get_contents($this->tempDir.'/external_blocklist.nft.pending');
    expect($content)->toContain('flush set inet tallpbx_filter geo_allowed_ips')
        ->and($content)->toContain('flush set inet tallpbx_filter geo_allowed_ips6')
        ->and($content)->not->toContain('flush set inet tallpbx_filter geo_blocked_ips');
});

it('fails a whitelist sync when any country yields no entries', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update([
        'enabled' => true,
        'mode' => 'whitelist',
        'countries' => ['US', 'CA'],
    ]);

    $this->provider->shouldReceive('fetchCountryRanges')->with('US')->once()->andReturn([
        'v4' => ['1.0.0.0/24'],
        'v6' => [],
    ]);
    $this->provider->shouldReceive('fetchCountryRanges')->with('CA')->once()->andReturn([
        'v4' => [],
        'v6' => [],
    ]);

    // A partial allow-set must never be promoted: the missing country
    // would be silently dropped at the perimeter while the UI shows success.
    $this->executor->shouldReceive('updateExternalBlocklist')->never();

    $result = $this->geoService->sync($rule, force: true);

    expect($result['status'])->toBe('failed')
        ->and($result['error'])->toContain('CA');
});

it('reports a cache write failure instead of silently proceeding', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update(['enabled' => true, 'mode' => 'blacklist', 'countries' => ['US']]);

    // A directory where the v4 zone file should be written makes the
    // cache write fail regardless of the user running the tests.
    mkdir($this->cacheDir.'/US.v4.zone');

    $this->provider->shouldReceive('fetchCountryRanges')->with('US')->once()->andReturn([
        'v4' => ['1.0.0.0/24'],
        'v6' => ['2600::/12'],
    ]);

    $result = $this->geoService->sync($rule, force: true);

    expect($result['status'])->toBe('failed')
        ->and($result['error'])->toContain('Failed to write zone cache');

    // Restore so the shared afterEach cleanup can remove the cache dir.
    rmdir($this->cacheDir.'/US.v4.zone');
});

it('repeated merges leave exactly one geo header and keep external elements', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update(['enabled' => true, 'mode' => 'blacklist', 'countries' => ['US']]);

    $this->provider->shouldReceive('fetchCountryRanges')->with('US')->andReturn([
        'v4' => ['1.0.0.0/24'],
        'v6' => [],
    ]);

    // Seed an external pending file that already carries feed elements plus
    // a stale geo block with TWO accumulated header comment lines.
    file_put_contents($this->tempDir.'/external_blocklist.nft.pending', implode("\n", [
        '# TallPBX external blocklist kernel elements — generated 2026-10-16T00:00:00+00:00; do not edit.',
        'flush set inet tallpbx_filter external_blocklist_ips',
        'flush set inet tallpbx_filter external_blocklist_ips6',
        'add element inet tallpbx_filter external_blocklist_ips { 6.6.6.0/24 }',
        '# TallPBX Geo-Blocking kernel elements — generated 2026-10-15T00:00:00+00:00; do not edit.',
        '# TallPBX Geo-Blocking kernel elements — generated 2026-10-14T00:00:00+00:00; do not edit.',
        'flush set inet tallpbx_filter geo_blocked_ips',
    ])."\n");

    // Two syncs exercise the merge path repeatedly.
    $this->geoService->sync($rule, force: true);
    $this->geoService->sync($rule, force: true);

    $content = file_get_contents($this->tempDir.'/external_blocklist.nft.pending');
    expect(substr_count($content, '# TallPBX Geo-Blocking kernel elements'))->toBe(1)
        ->and($content)->toContain('add element inet tallpbx_filter external_blocklist_ips { 6.6.6.0/24 }')
        ->and($content)->toContain('add element inet tallpbx_filter geo_blocked_ips { 1.0.0.0/24 }')
        // The atomic tmp+rename publish must never leave a sibling behind.
        ->and(file_exists($this->tempDir.'/geo_blocking.nft.pending.tmp'))->toBeFalse();
});

it('seeds from active external blocklist file under lock when pending does not yet exist', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update(['enabled' => true, 'mode' => 'blacklist', 'countries' => ['US']]);

    $this->provider->shouldReceive('fetchCountryRanges')->with('US')->andReturn([
        'v4' => ['1.0.0.0/24'],
        'v6' => [],
    ]);

    // Active file exists, but pending does not exist
    file_put_contents($this->tempDir.'/external_blocklist.nft', implode("\n", [
        '# TallPBX external blocklist kernel elements — active',
        'flush set inet tallpbx_filter external_blocklist_ips',
        'flush set inet tallpbx_filter external_blocklist_ips6',
        'add element inet tallpbx_filter external_blocklist_ips { 8.8.8.8 }',
    ])."\n");

    $this->geoService->sync($rule, force: true);

    $content = file_get_contents($this->tempDir.'/external_blocklist.nft.pending');
    expect($content)->toContain('add element inet tallpbx_filter external_blocklist_ips { 8.8.8.8 }')
        ->and($content)->toContain('add element inet tallpbx_filter geo_blocked_ips { 1.0.0.0/24 }');
});

it('re-downloads a country zone whose cache file exists but is empty', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update(['enabled' => true, 'mode' => 'whitelist', 'countries' => ['US']]);

    // An existing but empty zone cache carries no usable ranges: it must not
    // pass the fail-closed whitelist guard as if it held valid content.
    file_put_contents($this->cacheDir.'/US.v4.zone', '');
    file_put_contents($this->cacheDir.'/US.v6.zone', '');

    $this->provider->shouldReceive('fetchCountryRanges')->with('US')->once()->andReturn([
        'v4' => ['1.0.0.0/24'],
        'v6' => ['2600::/12'],
    ]);

    $result = $this->geoService->sync($rule, force: false);

    expect($result['status'])->toBe('success')
        ->and($result['v4_count'])->toBe(1)
        ->and($result['v6_count'])->toBe(1);
});

it('fails a whitelist sync when an empty cached zone cannot be re-downloaded', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update(['enabled' => true, 'mode' => 'whitelist', 'countries' => ['US']]);

    // Empty cache plus a failed re-download leaves no usable content:
    // arming the perimeter would drop everything not in an empty allow-set.
    file_put_contents($this->cacheDir.'/US.v4.zone', '');
    file_put_contents($this->cacheDir.'/US.v6.zone', '');

    $this->provider->shouldReceive('fetchCountryRanges')->with('US')->once()->andReturn([
        'v4' => [],
        'v6' => [],
    ]);

    $this->executor->shouldReceive('updateExternalBlocklist')->never();

    $result = $this->geoService->sync($rule, force: false);

    expect($result['status'])->toBe('failed')
        ->and($result['error'])->toContain('US');
});

it('fails a sync when the compiled pending file cannot be published', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update(['enabled' => true, 'mode' => 'blacklist', 'countries' => ['US']]);

    $this->provider->shouldReceive('fetchCountryRanges')->with('US')->once()->andReturn([
        'v4' => ['1.0.0.0/24'],
        'v6' => [],
    ]);

    // A directory occupying the pending path makes the atomic tmp+rename
    // publish fail regardless of the user running the tests.
    mkdir($this->tempDir.'/geo_blocking.nft.pending');

    $result = $this->geoService->sync($rule, force: true);

    expect($result['status'])->toBe('failed')
        ->and($result['error'])->toContain('Failed to write geo set elements');

    rmdir($this->tempDir.'/geo_blocking.nft.pending');
});

it('fails a sync when the merged pending file cannot be written', function (): void {
    $rule = SecurityGeoRule::instance();
    $rule->update(['enabled' => true, 'mode' => 'blacklist', 'countries' => ['US']]);

    $this->provider->shouldReceive('fetchCountryRanges')->with('US')->once()->andReturn([
        'v4' => ['1.0.0.0/24'],
        'v6' => [],
    ]);

    // A directory occupying the shared pending path defeats both the locked
    // open and the plain-write fallback.
    mkdir($this->tempDir.'/external_blocklist.nft.pending');

    $result = $this->geoService->sync($rule, force: true);

    expect($result['status'])->toBe('failed')
        ->and($result['error'])->toContain('Failed to write geo set elements');

    rmdir($this->tempDir.'/external_blocklist.nft.pending');
});
