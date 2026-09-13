<?php

it('receives an in-memory SQLite database from the Artisan test command', function (): void {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:');
});
