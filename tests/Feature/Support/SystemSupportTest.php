<?php

declare(strict_types=1);

namespace Tests\Feature\Support;

use App\Support\PrimaryDatabaseSafety;
use App\Support\SystemProcessRunner;
use App\Support\UpdateResult;

/*
|--------------------------------------------------------------------------
| System Process Runner Tests
|--------------------------------------------------------------------------
*/

describe('system process runner', function (): void {
    it('runs a command and returns the exit code and output', function (): void {
        $runner = new SystemProcessRunner;

        $result = $runner->run(['php', '-r', "echo 'hello';"]);

        expect($result->exitCode)->toBe(0)
            ->and($result->output)->toContain('hello');
    });

    it('captures a failing command exit code', function (): void {
        $runner = new SystemProcessRunner;

        $result = $runner->run(['php', '-r', 'exit(3);']);

        expect($result->exitCode)->toBe(3);
    });

    it('kills a hung command at the per-step timeout', function (): void {
        config(['git-update.step_timeout_seconds' => 1]);
        $runner = new SystemProcessRunner;

        $result = $runner->run(['php', '-r', 'sleep(5);']);

        expect($result->exitCode)->toBe(124);
    });

    it('finds and executes binaries in /usr/local/bin such as composer', function (): void {
        $runner = new SystemProcessRunner;

        $result = $runner->run(['composer', '--version']);

        expect($result->exitCode)->toBe(0)
            ->and($result->output)->toContain('Composer');
    });
});

/*
|--------------------------------------------------------------------------
| Update Result Tests
|--------------------------------------------------------------------------
*/

describe('update result value object', function (): void {
    it('builds a successful update result with steps', function (): void {
        $result = UpdateResult::success('main', [
            ['label' => 'Pull', 'status' => 'ok', 'output' => 'Already up to date.'],
        ]);

        expect($result->success)->toBeTrue()
            ->and($result->target)->toBe('main')
            ->and($result->steps)->toHaveCount(1)
            ->and($result->rollbackReport)->toBeNull();
    });

    it('builds a failed update result with a rollback report', function (): void {
        $result = UpdateResult::failed('main', 'Migrate failed.', [], 'Rolled back to abc123.');

        expect($result->success)->toBeFalse()
            ->and($result->reason)->toBe('Migrate failed.')
            ->and($result->rollbackReport)->toBe('Rolled back to abc123.');
    });
});

/*
|--------------------------------------------------------------------------
| Primary Database Safety Tests
|--------------------------------------------------------------------------
*/

describe('primary database safety guard', function (): void {
    it('prohibits destructive commands for the configured primary database', function (): void {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'tallpbx',
            'app.primary_database' => 'tallpbx',
        ]);

        expect(PrimaryDatabaseSafety::shouldProhibitDestructiveCommands())->toBeTrue();
    });

    it('allows destructive commands for a non-primary database', function (): void {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'tallpbx_test',
            'app.primary_database' => 'tallpbx',
        ]);

        expect(PrimaryDatabaseSafety::shouldProhibitDestructiveCommands())->toBeFalse();
    });

    it('never treats an in-memory database as the protected primary database', function (): void {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.primary_database' => ':memory:',
        ]);

        expect(PrimaryDatabaseSafety::shouldProtectConnection('sqlite'))->toBeFalse();
        expect(PrimaryDatabaseSafety::shouldProhibitDestructiveCommands())->toBeFalse();
    });

    it('still protects a named database that matches the configured primary', function (): void {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'tallpbx',
            'app.primary_database' => 'tallpbx',
        ]);

        expect(PrimaryDatabaseSafety::shouldProtectConnection('mysql'))->toBeTrue();
    });
});
