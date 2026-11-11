<?php

declare(strict_types=1);

/*
 * Guards the split between appliance:sync-host and the shared pool reconciler.
 *
 * The command used to rewrite pm.max_children on its own: one directive out of
 * six, a hardcoded php8.5-fpm unit, and every failure swallowed. The shape and
 * the memory tier table now live in scripts/resources/php-fpm-pool.sh, which
 * the installer also runs, so the command must stay a caller and never grow a
 * second copy of the table.
 */

it('delegates PHP-FPM pool reconciliation to the shared script', function (): void {
    $source = (string) file_get_contents(app_path('Console/Commands/ApplianceSyncHostCommand.php'));

    expect($source)->toContain('scripts/resources/php-fpm-pool.sh')
        // The administrator override reaches the script through the config key,
        // never through a literal ceiling in the command.
        ->and($source)->toContain("config('php-fpm.max_children'")
        // No second implementation of the shape or the RAM table.
        ->and($source)->not->toContain('pm.max_children =')
        ->and($source)->not->toContain('/proc/meminfo')
        // No hardcoded unit: the script derives php<version>-fpm from the path.
        ->and($source)->not->toContain('php8.5-fpm');
});

it('fails the restore step when the pool cannot be reconciled', function (): void {
    $source = (string) file_get_contents(app_path('Console/Commands/ApplianceSyncHostCommand.php'));

    // A restore that leaves an unverified ceiling behind must not report
    // "synchronization complete", so the failure has to reach the exit code.
    expect($source)->toContain('$result->failed()')
        ->and($source)->toContain('return self::FAILURE;');
});
