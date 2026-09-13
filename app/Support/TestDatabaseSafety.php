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
     * Require every test process to use its private in-memory SQLite database.
     */
    public static function enforce(): void
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if ($connection !== 'sqlite' || $database !== ':memory:') {
            throw new LogicException('Tests must use an in-memory SQLite database.');
        }
    }
}
