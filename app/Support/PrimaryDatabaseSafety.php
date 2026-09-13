<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Decides whether Laravel must block destructive commands for the primary database.
 */
final class PrimaryDatabaseSafety
{
    /**
     * Block destructive commands whenever the active connection uses the protected database.
     */
    public static function shouldProhibitDestructiveCommands(): bool
    {
        return self::shouldProtectConnection((string) config('database.default'));
    }

    /** Decide whether a named connection points to the protected primary database. */
    public static function shouldProtectConnection(?string $connection): bool
    {
        $connection ??= (string) config('database.default');
        $activeDatabase = (string) config("database.connections.{$connection}.database");
        $primaryDatabase = (string) config('app.primary_database');

        // An in-memory database is never the durable primary database. Without
        // this guard, phpunit's forced DB_DATABASE=:memory: makes the test
        // database match the primary fallback and blocks migrate:fresh.
        if ($activeDatabase === ':memory:') {
            return false;
        }

        return $primaryDatabase !== '' && $activeDatabase === $primaryDatabase;
    }
}
