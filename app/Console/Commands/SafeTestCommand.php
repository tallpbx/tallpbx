<?php

declare(strict_types=1);

namespace App\Console\Commands;

use NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand as CollisionTestCommand;

/**
 * Replaces Laravel's standard test command with one that isolates test data.
 *
 * Collision starts Pest in a child process. Supplying the safe values here
 * ensures that child process cannot inherit the server's application database.
 */
class SafeTestCommand extends CollisionTestCommand
{
    /**
     * Clear stale cached configuration before any test process starts.
     *
     * A cached configuration would silently re-point the spawned test
     * processes at the server's real database (the boot-time fail-fast would
     * stop them), so the cache is removed first. Harmless when none exists.
     */
    public function handle()
    {
        $this->callSilent('config:clear');

        return parent::handle();
    }

    /**
     * Supply safe test settings to a normal Pest or PHPUnit process.
     *
     * @return array<string, string|false>
     */
    protected function phpunitEnvironmentVariables(): array
    {
        return [
            ...parent::phpunitEnvironmentVariables(),
            ...$this->testingEnvironmentVariables(),
        ];
    }

    /**
     * Supply safe test settings to every parallel Pest worker.
     *
     * @return array<string, string|bool|false|null>
     */
    protected function paratestEnvironmentVariables(): array
    {
        return [
            ...parent::paratestEnvironmentVariables(),
            ...$this->testingEnvironmentVariables(),
        ];
    }

    /**
     * Return settings that isolate tests from the server's application services.
     *
     * DB_URL is removed because it can override the SQLite connection settings.
     *
     * @return array<string, string|false>
     */
    private function testingEnvironmentVariables(): array
    {
        return [
            'APP_ENV' => 'testing',
            'APP_MAINTENANCE_DRIVER' => 'file',
            'BCRYPT_ROUNDS' => '4',
            'BROADCAST_CONNECTION' => 'null',
            'CACHE_STORE' => 'array',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => false,
            // Redis isolation: keep tests off the production security-state
            // databases (0/1) and the production key prefix.
            'REDIS_DB' => '15',
            'REDIS_CACHE_DB' => '14',
            'REDIS_SESSION_DB' => '13',
            'REDIS_PREFIX' => 'tallpbx-test-',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'SESSION_CONNECTION' => 'session',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];
    }
}
