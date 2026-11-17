<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Resolves FreeSWITCH core and module database DSNs from configuration.
 *
 * Returns null for default SQLite mode so FreeSWITCH uses its local
 * /var/lib/freeswitch/db files, or returns the configured external DSN
 * (such as mariadb://...) for core-db-dsn and module odbc-dsn parameters.
 */
final class FreeSwitchDatabaseDsn
{
    /**
     * Get the configured FreeSWITCH runtime database driver.
     */
    public static function driver(): string
    {
        return strtolower((string) config('freeswitch.database.driver', 'sqlite'));
    }

    /**
     * Get the DSN used by FreeSWITCH core switch settings, or null for SQLite.
     */
    public static function coreDsn(): ?string
    {
        $configuredDsn = self::nullableString(config('freeswitch.database.dsn'));
        if ($configuredDsn !== null) {
            return $configuredDsn;
        }

        return match (self::driver()) {
            'mariadb', 'mysql' => self::buildMariaDbDsn(),
            'pgsql', 'postgres', 'postgresql' => self::buildPostgresDsn(),
            'odbc' => self::nullableString(config('freeswitch.database.odbc_dsn')),
            default => null,
        };
    }

    /**
     * Get the DSN used by FreeSWITCH module odbc-dsn parameters, or null for SQLite.
     */
    public static function moduleDsn(): ?string
    {
        return self::nullableString(config('freeswitch.database.odbc_dsn'))
            ?? self::coreDsn();
    }

    /**
     * Build a native mod_mariadb connection string from configured parts.
     */
    private static function buildMariaDbDsn(): string
    {
        $host = (string) config('freeswitch.database.host', '127.0.0.1');
        $port = (int) config('freeswitch.database.port', 3306);
        $database = (string) config('freeswitch.database.database', 'freeswitch');
        $username = (string) config('freeswitch.database.username', 'freeswitch');
        $password = (string) config('freeswitch.database.password', '');
        $options = self::nullableString(config('freeswitch.database.options'));

        $dsn = "mariadb://Server={$host};Port={$port};Database={$database};Uid={$username};Pwd={$password};";

        return $options !== null ? $dsn.$options : $dsn;
    }

    /**
     * Build a native mod_pgsql connection string from configured parts.
     */
    private static function buildPostgresDsn(): string
    {
        $host = (string) config('freeswitch.database.host', '127.0.0.1');
        $port = (int) config('freeswitch.database.port', 5432);
        $database = (string) config('freeswitch.database.database', 'freeswitch');
        $username = (string) config('freeswitch.database.username', 'freeswitch');
        $password = (string) config('freeswitch.database.password', '');
        $options = (string) config('freeswitch.database.options', '');

        return "pgsql://hostaddr={$host} port={$port} dbname={$database} user={$username} password={$password} options={$options}";
    }

    /**
     * Normalize non-empty configuration strings, treating blank strings as null.
     */
    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
