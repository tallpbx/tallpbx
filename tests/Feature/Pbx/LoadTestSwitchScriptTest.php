<?php

declare(strict_types=1);

namespace Tests\Feature\Pbx;

use Symfony\Component\Process\Process;

/*
|--------------------------------------------------------------------------
| Load-Test Symmetric Switch Helpers
|--------------------------------------------------------------------------
|
| The refresh series trusts apply_* only because assert_* reads the value
| back. These checks keep that pairing, the teardown trap, and the
| "never touch the live firewall" rule from regressing.
|
*/

describe('load test switch helpers', function (): void {
    it('is a syntactically valid bash script', function (): void {
        $process = new Process(['bash', '-n', base_path('load-testing/scripts/lib/switch.sh')], base_path());
        $process->run();

        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
    });

    it('pairs every apply helper with an assert helper', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/lib/switch.sh'));

        preg_match_all('/^([a-z][a-z0-9_]*)\(\) \{/m', $script, $matches);
        $functions = $matches[1];

        $applyHelpers = array_values(array_filter(
            $functions,
            fn (string $name): bool => str_starts_with($name, 'apply_'),
        ));

        expect($applyHelpers)->not->toBeEmpty();

        foreach ($applyHelpers as $applyHelper) {
            $assertHelper = 'assert_'.substr($applyHelper, strlen('apply_'));

            expect($functions)->toContain($assertHelper);
        }
    });

    it('defines every switch named by the refresh plan', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/lib/switch.sh'));

        preg_match_all('/^([a-z][a-z0-9_]*)\(\) \{/m', $script, $matches);

        expect($matches[1])->toContain(
            'apply_fpm_profile',
            'assert_fpm_profile',
            'apply_fs_loglevel',
            'assert_fs_loglevel',
            'apply_fs_db_mode',
            'assert_fs_db_mode',
            'apply_xml_path',
            'assert_xml_path',
            'apply_lua_bindings',
            'assert_lua_bindings',
            'apply_ttl',
            'assert_ttl',
            'apply_cdr_transport',
            'assert_cdr_transport',
            'restore_baseline',
        );
    });

    it('restores the baseline from a teardown trap', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/lib/switch.sh'));

        expect($script)->toContain('trap restore_baseline EXIT INT TERM')
            // Clearing the trap first keeps a failing restore from recursing.
            ->and($script)->toContain('trap - EXIT INT TERM');
    });

    it('corrects a half-applied Lua pair before declaring the baseline restored', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/lib/switch.sh'));

        preg_match('/^restore_baseline\(\) \{.*?^\}/ms', $script, $match);

        // The bindings are the baseline; the .env flag follows them. A restore
        // that reproduced a captured mismatch left Laravel writing a Redis
        // mirror FreeSWITCH never read.
        expect($match[0] ?? '')->toContain('coherent_baseline')
            ->and($script)->toContain('baseline-corrected');
    });

    it('measures a FreeSWITCH lookup with the CLI overhead subtracted', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/lib/switch.sh'));

        // A Lua hit never reaches nginx or PHP, so the HTTP client cannot see
        // it. find_user_xml is the only probe that asks FreeSWITCH itself.
        expect($script)->toContain('probe_lookup')
            ->and($script)->toContain('find_user_xml')
            ->and($script)->toContain('status');
    });

    it('never mutates live nftables state', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/lib/switch.sh'));

        foreach (['nft add', 'nft insert', 'nft delete', 'nft flush', 'nft chain', 'nft -f', 'nftables flush'] as $mutation) {
            expect($script)->not->toContain($mutation);
        }
    });

    it('reports a failed switch with the marker the runner greps for', function (): void {
        $script = (string) file_get_contents(base_path('load-testing/scripts/lib/switch.sh'));

        expect($script)->toContain('SWITCH-FAILED')
            ->and($script)->toContain('set -euo pipefail');
    });
});
