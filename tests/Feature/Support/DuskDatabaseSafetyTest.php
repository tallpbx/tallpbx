<?php

declare(strict_types=1);

use App\Support\DuskDatabaseSafety;

// A correctly isolated Dusk connection may boot the browser-test application.
it('accepts a dedicated dusk database and user', function (): void {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'tallpbx_dusk',
        'database.connections.mysql.username' => 'tallpbx_dusk',
    ]);

    DuskDatabaseSafety::enforce();

    expect(true)->toBeTrue();
});

// A configuration typo must fail before Dusk can touch the primary database.
it('rejects the primary application database for Dusk', function (): void {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'tallpbx',
        'database.connections.mysql.username' => 'tallpbx_dusk',
    ]);

    expect(fn (): null => DuskDatabaseSafety::enforce())
        ->toThrow(LogicException::class, 'dedicated *_dusk database');
});

// The browser process must not hold credentials for the primary database user.
it('rejects a non-Dusk database user', function (): void {
    config([
        'database.default' => 'mysql',
        'database.connections.mysql.database' => 'tallpbx_dusk',
        'database.connections.mysql.username' => 'tallpbx',
    ]);

    expect(fn (): null => DuskDatabaseSafety::enforce())
        ->toThrow(LogicException::class, 'dedicated *_dusk database user');
});
