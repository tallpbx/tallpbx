<?php

declare(strict_types=1);

use App\Support\PrimaryDatabaseSafety;

// A local environment may still be connected to the protected application database.
it('prohibits destructive commands for the configured primary database', function (): void {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'tallpbx',
        'app.primary_database' => 'tallpbx',
    ]);

    expect(PrimaryDatabaseSafety::shouldProhibitDestructiveCommands())->toBeTrue();
});

// Disposable databases must remain available to tests and browser-test tooling.
it('allows destructive commands for a non-primary database', function (): void {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'tallpbx_dusk',
        'app.primary_database' => 'tallpbx',
    ]);

    expect(PrimaryDatabaseSafety::shouldProhibitDestructiveCommands())->toBeFalse();
});

// phpunit forces DB_DATABASE=:memory:, which would otherwise make the in-memory
// test database look identical to the protected primary database and block
// migrate:fresh from ever building the test schema.
it('never treats an in-memory database as the protected primary database', function (): void {
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'app.primary_database' => ':memory:',
    ]);

    expect(PrimaryDatabaseSafety::shouldProtectConnection('sqlite'))->toBeFalse();
    expect(PrimaryDatabaseSafety::shouldProhibitDestructiveCommands())->toBeFalse();
});

// A real named database that equals the configured primary must still be protected.
it('still protects a named database that matches the configured primary', function (): void {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'tallpbx',
        'app.primary_database' => 'tallpbx',
    ]);

    expect(PrimaryDatabaseSafety::shouldProtectConnection('mysql'))->toBeTrue();
});
