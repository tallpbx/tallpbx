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
| so the SIPp scenario list cannot drift away from load-testing/tools/sipp.
|
*/

/**
 * Read the declarative matrix.
 *
 * The matrix is test tooling, so it lives with the rest of it under
 * load-testing/ and is read directly rather than through the application's
 * configuration repository.
 *
 * @return array<string, mixed>
 */
function loadTestMatrix(): array
{
    /** @var array<string, mixed> $matrix */
    $matrix = require base_path('load-testing/matrix.php');

    return $matrix;
}

describe('load test matrix configuration', function (): void {
    it('defines every hardware tier and serving variant', function (): void {
        expect(array_keys(loadTestMatrix()['tiers']))
            ->toBe(['h1-1c1g', 'h1-1c2g', 'h2-2c2g', 'h2d-2c8g', 'h4d-4c16g'])
            ->and(collect(loadTestMatrix()['tiers'])->where('stage', 'a')->count())->toBe(3)
            ->and(collect(loadTestMatrix()['tiers'])->where('stage', 'b')->count())->toBe(2)
            ->and(array_keys(loadTestMatrix()['variants']))
            ->toBe(['v-stock-dynamic', 'v-stock-static', 'v-fast', 'v-fast-default', 'v-octane']);
    });

    it('defines both CDR ingestion transports', function (): void {
        expect(array_keys(loadTestMatrix()['cdr_transports']))->toBe(['cdr-fpm', 'cdr-octane']);
    });

    it('keeps the capacity ladder ascending and complete', function (): void {
        $ladder = loadTestMatrix()['cps_ladder'];

        expect($ladder)->toBe([2, 3, 5, 8, 10, 15, 20, 25, 30, 40])
            ->and($ladder)->toBe(collect($ladder)->sort()->values()->all());
    });

    it('resolves the shipped dynamic baseline to the installer ceiling', function (): void {
        // A cell that claims to measure "the shipped default" is only faithful
        // when the tier table resolves the dynamic profile to the ceiling the
        // installer really writes for that much memory. The installer derives
        // it from detected RAM (3 below 1800 MB, 6 below 3500 MB, 12 above),
        // so the matrix must agree; otherwise a dynamic cell silently measures
        // a pool shape nobody deploys.
        $installerCeiling = static fn (int $ramMb): int => $ramMb >= 3500 ? 12 : ($ramMb >= 1800 ? 6 : 3);

        $stageA = collect(loadTestMatrix()['tiers'])->where('stage', 'a');

        $measured = $stageA
            ->map(fn (array $tier): array => [
                'mode' => $tier['fpm_alt'],
                'ceiling' => $tier['fpm_alt_children'],
            ])
            ->all();

        $shipped = $stageA
            ->map(fn (array $tier): array => [
                'mode' => 'dynamic',
                'ceiling' => $installerCeiling((int) $tier['ram_mb']),
            ])
            ->all();

        expect($measured)->toBe($shipped);
    });

    it('lists every SIPp scenario that ships in load-testing/tools/sipp', function (): void {
        $files = collect(glob(base_path('load-testing/tools/sipp/{uac-*,register}.xml'), GLOB_BRACE))
            ->map(fn (string $path): string => basename($path, '.xml'))
            ->sort()->values()->all();

        expect(collect(loadTestMatrix()['sip_scenarios'])->sort()->values()->all())->toBe($files);
    });
});
