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
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'SESSION_DRIVER' => 'array',
            'PULSE_ENABLED' => 'false',
            'TELESCOPE_ENABLED' => 'false',
            'NIGHTWATCH_ENABLED' => 'false',
        ];
    }
}
