<?php

use App\Support\TestDatabaseSafety;
use Symfony\Component\Process\Process;

it('always uses an in-memory SQLite database while testing', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});

it('refuses a test database configuration that is not in-memory SQLite', function (): void {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'tallpbx',
    ]);

    expect(fn (): null => TestDatabaseSafety::enforce())
        ->toThrow(LogicException::class, 'Tests must use an in-memory SQLite database.');
});

it('forces every Artisan test mode to use in-memory SQLite', function (bool $parallel): void {
    $externalDatabase = sys_get_temp_dir().'/tallpbx-test-command-'.bin2hex(random_bytes(8)).'.sqlite';
    $arguments = [PHP_BINARY, 'artisan', 'test'];

    if ($parallel) {
        $arguments[] = '--parallel';
    }

    $arguments = [
        ...$arguments,
        '--compact',
        'tests/Feature/TestCommandEnvironmentProbeTest.php',
    ];

    try {
        $process = new Process(
            $arguments,
            base_path(),
            [
                'APP_ENV' => 'local',
                'DB_CONNECTION' => 'sqlite',
                'DB_DATABASE' => $externalDatabase,
            ],
        );

        $process->run();

        expect($process->getExitCode())
            ->toBe(0, $process->getOutput().$process->getErrorOutput());
    } finally {
        @unlink($externalDatabase);
    }
})->with([
    'standard test command' => false,
    'parallel test command' => true,
]);
