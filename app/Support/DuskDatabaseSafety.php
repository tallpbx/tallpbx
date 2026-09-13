<?php

declare(strict_types=1);

namespace App\Support;

use LogicException;

/**
 * Stops Dusk from running when its configuration points at a non-disposable database.
 */
final class DuskDatabaseSafety
{
    /**
     * Require Dusk to use a dedicated database and database user ending in _dusk.
     */
    public static function enforce(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $username = (string) config("database.connections.{$connection}.username");

        if (! str_ends_with($database, '_dusk')) {
            throw new LogicException('Dusk must use a dedicated *_dusk database.');
        }

        if (! str_ends_with($username, '_dusk')) {
            throw new LogicException('Dusk must use a dedicated *_dusk database user.');
        }
    }
}
