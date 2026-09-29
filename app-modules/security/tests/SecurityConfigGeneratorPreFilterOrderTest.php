<?php

declare(strict_types=1);

namespace Tests\Feature\Modules\Security;

use Database\Seeders\SecurityServiceSeeder;
use Modules\Security\Models\SecuritySetting;
use Modules\Security\Services\SecurityConfigGenerator;

/**
 * Feature tests for the stored pre-filter stage order.
 *
 * The seven pre-filter stages can be reordered within safety constraints:
 * loopback is always first, the whitelist always precedes every drop stage,
 * and the order must contain exactly the seven known keys.
 */
beforeEach(function (): void {
    $this->seed(SecurityServiceSeeder::class);
});

/**
 * Build every permutation of the seven stage keys.
 *
 * @param  array<int, string>|null  $items
 * @return array<int, array<int, string>>
 */
function securityPreFilterPermutations(?array $items = null): array
{
    $items ??= SecurityConfigGenerator::DEFAULT_PRE_FILTER_ORDER;

    if (count($items) <= 1) {
        return [$items];
    }

    $result = [];
    foreach ($items as $index => $item) {
        $rest = $items;
        array_splice($rest, $index, 1);
        foreach (securityPreFilterPermutations($rest) as $tail) {
            $result[] = array_merge([$item], $tail);
        }
    }

    return $result;
}

it('accepts exactly the permutations that satisfy the safety constraints', function (): void {
    $generator = new SecurityConfigGenerator;
    $dropStages = ['invalid', 'blacklist', 'banned', 'threat_feeds'];
    $accepted = 0;
    $rejected = 0;

    // Enumerate all 5040 permutations of the seven stage keys and assert the
    // validator accepts exactly those that satisfy the constraints. This is
    // the test that stops a future refactor from quietly relaxing them.
    foreach (securityPreFilterPermutations() as $permutation) {
        $whitelistPosition = array_search('whitelist', $permutation, true);
        $expected = $permutation[0] === 'loopback';

        foreach ($dropStages as $dropStage) {
            if (array_search($dropStage, $permutation, true) < $whitelistPosition) {
                $expected = false;
            }
        }

        try {
            $generator->assertValidPreFilterOrder($permutation);
            $isValid = true;
        } catch (\RuntimeException) {
            $isValid = false;
        }

        expect($isValid)->toBe($expected);
        $expected ? $accepted++ : $rejected++;
    }

    // Sanity: the enumeration covered both outcomes.
    expect($accepted)->toBeGreaterThan(0)->and($rejected)->toBeGreaterThan(0);
});

it('emits the pre-filter stages in the stored order', function (): void {
    // A fully safe custom order: the whitelist stays above every drop stage
    // while the fast path, the invalid drop, and the blocklists trade places.
    $order = ['loopback', 'whitelist', 'fast_path', 'invalid', 'banned', 'blacklist', 'threat_feeds'];
    SecuritySetting::set('pre_filter_order', json_encode($order));

    $tempDir = sys_get_temp_dir().'/tallpbx_prefilter_order_test_'.uniqid();
    $generator = new SecurityConfigGenerator($tempDir);
    $nft = $generator->generate();

    $whitelistAccept = strpos($nft, 'ip saddr @whitelist_ips accept');
    $fastPath = strpos($nft, 'ct state established,related accept');
    $invalidDrop = strpos($nft, 'ct state invalid drop');
    $bannedDrop = strpos($nft, 'ip saddr @banned_ips drop');
    $blacklistDrop = strpos($nft, 'ip saddr @blacklist_ips drop');

    expect($whitelistAccept)->toBeLessThan($fastPath)
        ->and($fastPath)->toBeLessThan($invalidDrop)
        ->and($invalidDrop)->toBeLessThan($bannedDrop)
        ->and($bannedDrop)->toBeLessThan($blacklistDrop);

    // Stage numbers follow actual evaluation order, so the reordered build
    // renumbers its comments instead of lying about the pipeline.
    expect($nft)->toContain('# STAGE 3: STATEFUL FAST PATH')
        ->and($nft)->toContain('# STAGE 4: DROP INVALID PACKETS');

    $pending = $generator->writePending();
    try {
        expect($generator->validateSyntax($pending))->toBeTrue();
    } finally {
        @unlink($pending);
        @rmdir($tempDir);
    }
});

it('refuses to compile a stored order placing a drop stage above the whitelist', function (): void {
    SecuritySetting::set('pre_filter_order', json_encode([
        'loopback', 'invalid', 'whitelist', 'fast_path', 'blacklist', 'banned', 'threat_feeds',
    ]));

    expect(fn () => (new SecurityConfigGenerator)->generate())
        ->toThrow(\RuntimeException::class, 'whitelist');
});

it('refuses a stored order that is not exactly the seven known stages', function (): void {
    // Missing entry, duplicate entry, and garbage payloads must all refuse
    // loudly instead of silently compiling something the panel never showed.
    SecuritySetting::set('pre_filter_order', json_encode([
        'loopback', 'whitelist', 'invalid', 'fast_path', 'blacklist', 'banned',
    ]));

    expect(fn () => (new SecurityConfigGenerator)->generate())
        ->toThrow(\RuntimeException::class, 'pre-filter order');

    SecuritySetting::set('pre_filter_order', json_encode([
        'loopback', 'loopback', 'whitelist', 'invalid', 'fast_path', 'blacklist', 'banned',
    ]));

    expect(fn () => (new SecurityConfigGenerator)->generate())
        ->toThrow(\RuntimeException::class, 'pre-filter order');

    SecuritySetting::set('pre_filter_order', '{not-json');

    expect(fn () => (new SecurityConfigGenerator)->generate())
        ->toThrow(\RuntimeException::class, 'pre-filter order');
});

it('refuses a stored order that does not start with loopback', function (): void {
    SecuritySetting::set('pre_filter_order', json_encode([
        'whitelist', 'loopback', 'invalid', 'fast_path', 'blacklist', 'banned', 'threat_feeds',
    ]));

    expect(fn () => (new SecurityConfigGenerator)->generate())
        ->toThrow(\RuntimeException::class, 'loopback');
});

it('keeps the ruleset byte-identical when the default order is stored explicitly', function (): void {
    $default = (new SecurityConfigGenerator)->generate();

    SecuritySetting::set('pre_filter_order', json_encode(SecurityConfigGenerator::DEFAULT_PRE_FILTER_ORDER));

    expect((new SecurityConfigGenerator)->generate())->toBe($default);
});
