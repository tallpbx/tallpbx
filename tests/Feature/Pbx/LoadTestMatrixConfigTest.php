<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

/*
|--------------------------------------------------------------------------
| Load-Test Refresh Matrix
|--------------------------------------------------------------------------
|
| The refresh series reads every tier, serving variant, load profile,
| cache policy and SIPp scenario from one declarative config. These tests
| keep that config complete so the runner never silently skips a cell and
| so the SIPp scenario list cannot drift away from tools/sipp.
|
*/

describe('load test matrix configuration', function (): void {
    it('defines every hardware tier and serving variant', function (): void {
        expect(array_keys(config('load-test-matrix.tiers')))
            ->toBe(['h1-1c1g', 'h1-1c2g', 'h2-2c2g', 'h2d-2c8g', 'h4d-4c16g'])
            ->and(collect(config('load-test-matrix.tiers'))->where('stage', 'a')->count())->toBe(3)
            ->and(collect(config('load-test-matrix.tiers'))->where('stage', 'b')->count())->toBe(2)
            ->and(array_keys(config('load-test-matrix.variants')))
            ->toBe(['v-stock-dynamic', 'v-stock-static', 'v-fast', 'v-lua-stock', 'v-lua-fast', 'v-octane']);
    });

    it('defines both CDR ingestion transports', function (): void {
        expect(array_keys(config('load-test-matrix.cdr_transports')))->toBe(['cdr-fpm', 'cdr-octane']);
    });

    it('keeps the capacity ladder ascending and complete', function (): void {
        $ladder = config('load-test-matrix.cps_ladder');

        expect($ladder)->toBe([2, 3, 5, 8, 10, 15, 20, 25, 30, 40])
            ->and($ladder)->toBe(collect($ladder)->sort()->values()->all());
    });

    it('lists every SIPp scenario that ships in tools/sipp', function (): void {
        $files = collect(glob(base_path('tools/sipp/{uac-*,register}.xml'), GLOB_BRACE))
            ->map(fn (string $path): string => basename($path, '.xml'))
            ->sort()->values()->all();

        expect(collect(config('load-test-matrix.sip_scenarios'))->sort()->values()->all())->toBe($files);
    });
});
