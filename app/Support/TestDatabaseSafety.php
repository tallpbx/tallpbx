<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * Stops tests before they can use a configured application database.
 */
final class TestDatabaseSafety
{
    /**
     * Detect whether the current PHP process is a test runner (Pest/PHPUnit).
     *
     * Test runners are recognized by the PHPUnit bootstrap constant (defined
     * in ParaTest workers and process-isolation templates) and by the entry
     * script name (vendor/bin/pest, vendor/bin/phpunit, vendor/bin/paratest).
     * APP_ENV is deliberately NOT consulted: a cached configuration overrides
     * APP_ENV, and an APP_ENV-based check would also fire for ordinary Artisan
     * commands run with APP_ENV=testing — including the "php artisan
     * config:clear" that is the remedy for a cached configuration, which would
     * deadlock its own repair because providers boot before the command runs.
     */
    public static function isRunningTests(): bool
    {
        // Paratest workers and PHPUnit process-isolation templates define this.
        if (defined('PHPUNIT_COMPOSER_INSTALL')) {
            return true;
        }

        $entry = basename((string) ($_SERVER['argv'][0] ?? ''));

        return in_array($entry, ['pest', 'phpunit', 'paratest'], true);
    }

    /**
     * Require every test process to use its private in-memory SQLite database.
     */
    public static function enforce(): void
    {
        $app = app();
        $runningTests = self::isRunningTests();
        $loadedFromCache = self::configurationLoadedFromCache();

        // A cached configuration must never block the commands that clear it:
        // outside a test run the cache is a deployment state, not a test-safety
        // signal, so leave the process alone to perform its repair.
        if ($loadedFromCache && ! $runningTests) {
            return;
        }

        // Inside a test run a cached configuration silently overrides every
        // isolation setting (APP_ENV, DB_CONNECTION, DB_DATABASE), so the run
        // must stop before any query reaches the configured database.
        if ($loadedFromCache) {
            throw new LogicException('Cached configuration detected during a test run. Run "php artisan config:clear" before running tests.');
        }

        // Keep the historical protection for APP_ENV=testing processes that are
        // not test runners (manual Artisan commands, for example): they may not
        // touch a real database either.
        if (! $runningTests && ! $app->environment('testing')) {
            return;
        }

        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new LogicException('Tests must use an in-memory SQLite database.');
        }
    }

    /**
     * Report whether the booted configuration came from a cached config file.
     *
     * Laravel's LoadConfiguration binds the answer at every boot; the file
     * check is only a fallback for containers that skipped that bootstrap.
     */
    private static function configurationLoadedFromCache(): bool
    {
        $app = app();

        if ($app->bound('config_loaded_from_cache')) {
            return $app->make('config_loaded_from_cache') === true;
        }

        return is_file($app->getCachedConfigPath());
    }
}
