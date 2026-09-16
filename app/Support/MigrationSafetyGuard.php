<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Prevents migrations from removing application data on the protected database.
 */
final class MigrationSafetyGuard
{
    /** @var array<int, string> Method names that remove schema or stored records. */
    private const PROHIBITED_METHODS = [
        'delete',
        'destroy',
        'drop',
        'dropAllTables',
        'dropAllViews',
        'dropColumn',
        'dropColumns',
        'dropConstrainedForeignId',
        'dropIfExists',
        'truncate',
    ];

    /** @var array<int, int> Connections that already have the SQL safety callback. */
    private array $protectedConnections = [];

    /** The migration currently allowed to issue only non-destructive SQL. */
    private ?string $activeMigration = null;

    /** Whether the current migrator run targets the protected application database. */
    private bool $protectingMigrationRun = false;

    /** The protected connection used to confirm whether the reviewed schema is empty. */
    private ?Connection $protectedConnection = null;

    /**
     * Reject every pending migration that contains a destructive operation in up().
     *
     * @param  array<int, string>  $migrationFiles  Absolute paths to pending migration files.
     */
    public function assertPendingMigrationsAreSafe(array $migrationFiles): void
    {
        foreach ($migrationFiles as $migrationFile) {
            $operation = $this->findProhibitedOperation($migrationFile);

            if ($operation !== null) {
                throw new RuntimeException(sprintf(
                    'Destructive migration blocked to protect TallPBX data: migration [%s] contains prohibited operation [%s] in up(). Create an additive forward migration instead.',
                    basename($migrationFile),
                    $operation,
                ));
            }
        }
    }

    /** Mark a migrator run as protected and attach a callback before it sends SQL. */
    public function beginProtectedMigrationRun(Connection $connection): void
    {
        $this->protectingMigrationRun = true;
        $this->protectedConnection = $connection;
        $connectionId = spl_object_id($connection);

        if (in_array($connectionId, $this->protectedConnections, true)) {
            return;
        }

        $connection->beforeExecuting(function (string $query): void {
            $this->assertSqlIsSafe($query);
        });

        $this->protectedConnections[] = $connectionId;
    }

    /** End the protected migration run and clear any migration left active by an exception. */
    public function endProtectedMigrationRun(): void
    {
        $this->protectingMigrationRun = false;
        $this->activeMigration = null;
        $this->protectedConnection = null;
    }

    /** Record the migration whose up method is about to run. */
    public function startMigration(string $migrationName): void
    {
        if ($this->protectingMigrationRun) {
            $this->activeMigration = $migrationName;
        }
    }

    /** Clear the active migration after its up method completes. */
    public function finishMigration(): void
    {
        $this->activeMigration = null;
    }

    /** Reject destructive SQL immediately before the database receives it. */
    public function assertSqlIsSafe(string $query): void
    {
        if ($this->activeMigration === null || ! $this->isDestructiveSql($query)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Destructive migration blocked to protect TallPBX data: migration [%s] attempted prohibited destructive SQL. Create an additive forward migration instead.',
            $this->activeMigration,
        ));
    }

    /** Find the first prohibited method call or raw SQL statement in a migration up method. */
    private function findProhibitedOperation(string $migrationFile): ?string
    {
        $source = file_get_contents($migrationFile);

        if ($source === false) {
            throw new RuntimeException(sprintf('Unable to read migration [%s] for safety validation.', $migrationFile));
        }

        foreach ($this->upMethodTokens($source) as $token) {
            if (! is_array($token)) {
                continue;
            }

            [$tokenType, $tokenText] = $token;

            if ($tokenType === T_STRING && in_array($tokenText, self::PROHIBITED_METHODS, true)) {
                return $tokenText;
            }

            if ($tokenType === T_CONSTANT_ENCAPSED_STRING && $this->isDestructiveSql($tokenText)) {
                return 'raw destructive SQL';
            }
        }

        return null;
    }

    /** Extract the tokens inside the migration's up method without inspecting its down method. */
    private function upMethodTokens(string $source): array
    {
        $tokens = token_get_all($source);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $methodName = $this->nextMethodName($tokens, $index + 1);

            if ($methodName !== 'up') {
                continue;
            }

            return $this->methodBodyTokens($tokens, $index + 1);
        }

        return [];
    }

    /** Find the name that follows a PHP function declaration. */
    private function nextMethodName(array $tokens, int $start): ?string
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }

            if ($token === '(') {
                return null;
            }
        }

        return null;
    }

    /** Return the tokens between the braces of one PHP method body. */
    private function methodBodyTokens(array $tokens, int $start): array
    {
        $body = [];
        $depth = 0;
        $insideBody = false;

        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];

            if ($token === '{') {
                $depth++;
                $insideBody = true;

                continue;
            }

            if ($token === '}' && $insideBody) {
                $depth--;

                if ($depth === 0) {
                    return $body;
                }
            }

            if ($insideBody) {
                $body[] = $token;
            }
        }

        return $body;
    }

    /** Identify SQL statements that can remove a table, column, or stored records. */
    private function isDestructiveSql(string $query): bool
    {
        $statement = trim($query, " \t\n\r\0\x0B'\\\"");

        return preg_match('/^(?:DROP\\b|TRUNCATE\\b|DELETE\\b|ALTER\\s+TABLE\\b.*\\bDROP\\b)/i', $statement) === 1;
    }
}
