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
