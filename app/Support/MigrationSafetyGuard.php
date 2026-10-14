<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\MigrationCollisionException;
use App\Services\ModuleCollisionGuard;
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

    /** Names of the pending custom-module migrations in the current run. */
    private array $customMigrationNames = [];

    /** Whether a migrator run with custom-module migrations is being watched. */
    private bool $watchingCustomMigrations = false;

    /** Connection used by the custom-migration runtime DDL guard. */
    private ?Connection $customWatchConnection = null;

    /** @var array<int, int> Connections that already have the custom-migration DDL callback. */
    private array $customWatchedConnections = [];

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

    /**
     * Reject pending custom-module migrations that collide with the existing schema.
     *
     * Applies only to files under custom-modules/ (plan §1.7.3): creating a
     * table that already exists, or touching a protected core table, is a
     * packaging bug that would corrupt the appliance, so the migration run is
     * halted with MigrationCollisionException before any SQL executes.
     *
     * @param  array<int, string>  $migrationFiles  Absolute paths to pending migration files.
     * @param  Connection  $connection  Connection used to check table existence.
     */
    public function assertNoCustomTableCollisions(array $migrationFiles, Connection $connection): void
    {
        $customRoot = base_path('custom-modules').DIRECTORY_SEPARATOR;
        $schema = $connection->getSchemaBuilder();
        $guard = new ModuleCollisionGuard;

        // Reset the watched set for this run: the runtime DDL guard uses it
        // to know which executing migrations belong to custom modules.
        $this->customMigrationNames = [];

        foreach ($migrationFiles as $migrationFile) {
            if (! str_starts_with($migrationFile, $customRoot)) {
                continue;
            }

            $this->customMigrationNames[] = basename($migrationFile, '.php');

            foreach ($this->customTableTargets($migrationFile) as [$operation, $table]) {
                // Both creating and altering a protected core table is forbidden.
                if (! $guard->assertNoTableCollision($table, ModuleCollisionGuard::PROTECTED_CORE_TABLES)) {
                    throw new MigrationCollisionException(sprintf(
                        'Custom migration [%s] cannot touch protected core table [%s]. Custom modules must use their own prefixed tables.',
                        basename($migrationFile),
                        $table,
                    ));
                }

                if ($operation === 'create' && $schema->hasTable($table)) {
                    throw new MigrationCollisionException(sprintf(
                        'Custom migration [%s] cannot create table [%s] because it already exists. Use Schema::table to extend it, or rename your table.',
                        basename($migrationFile),
                        $table,
                    ));
                }
            }
        }
    }

    /**
     * Watch a migrator run for DDL issued by custom-module migrations.
     *
     * Static source analysis cannot see raw DB::statement SQL or table
     * names passed as variables, so this attaches a runtime guard: while a
     * custom migration executes, any CREATE TABLE for an existing table or
     * any DDL on a protected core table is halted before the database
     * receives it (plan §1.7.3).
     */
    public function beginCustomMigrationWatch(Connection $connection): void
    {
        $this->watchingCustomMigrations = true;
        $this->customWatchConnection = $connection;
        $connectionId = spl_object_id($connection);

        if (in_array($connectionId, $this->customWatchedConnections, true)) {
            return;
        }

        $connection->beforeExecuting(function (string $query): void {
            $this->assertCustomMigrationSqlIsSafe($query);
        });

        $this->customWatchedConnections[] = $connectionId;
    }

    /** End the custom-migration watch and clear its per-run bookkeeping. */
    public function endCustomMigrationWatch(): void
    {
        $this->watchingCustomMigrations = false;
        $this->customWatchConnection = null;
        $this->customMigrationNames = [];
        // Mirrors endProtectedMigrationRun: a guard thrown mid-up() skips the
        // MigrationEnded event, so the active migration must not linger.
        $this->activeMigration = null;
    }

    /**
     * Reject DDL that a custom migration may not issue, at execution time.
     *
     * Only applies while the currently executing migration belongs to a
     * custom module — core migrations legitimately alter their own tables.
     */
    public function assertCustomMigrationSqlIsSafe(string $query): void
    {
        if ($this->activeMigration === null || ! in_array($this->activeMigration, $this->customMigrationNames, true)) {
            return;
        }

        $table = $this->ddlTargetTable($query);

        if ($table === null) {
            return;
        }

        $guard = new ModuleCollisionGuard;

        if (! $guard->assertNoTableCollision($table, ModuleCollisionGuard::PROTECTED_CORE_TABLES)) {
            throw new MigrationCollisionException(sprintf(
                'Custom migration [%s] attempted prohibited DDL on protected core table [%s]. Custom modules must use their own prefixed tables.',
                $this->activeMigration,
                $table,
            ));
        }

        $isCreate = preg_match('/^\s*CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?!IF\s+NOT\s+EXISTS)/i', $query) === 1;

        if ($isCreate && $this->customWatchConnection?->getSchemaBuilder()->hasTable($table)) {
            throw new MigrationCollisionException(sprintf(
                'Custom migration [%s] attempted to create table [%s] which already exists. Use Schema::table to extend it, or rename your table.',
                $this->activeMigration,
                $table,
            ));
        }
    }

    /**
     * Extract the target table from a CREATE TABLE or ALTER TABLE statement.
     *
     * Handles quoted ("table", `table`, [table]) and bare identifiers;
     * returns null for statements that are not table DDL.
     */
    private function ddlTargetTable(string $query): ?string
    {
        if (preg_match('/^\s*(?:CREATE\s+(?:TEMPORARY\s+)?TABLE|ALTER\s+TABLE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?["`\[]?([A-Za-z0-9_.\-]+)/i', $query, $matches) !== 1) {
            return null;
        }

        $table = trim($matches[1], '"`[]');

        // Schema-qualified names keep only the table segment.
        $dotPosition = strrpos($table, '.');

        return $dotPosition === false ? $table : substr($table, $dotPosition + 1);
    }

    /**
     * Extract the Schema::create / Schema::table targets from a migration's up() method.
     *
     * Returns [operation, table] pairs found by token inspection. Table names
     * passed as variables instead of string literals cannot be resolved
     * statically and are silently skipped.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function customTableTargets(string $migrationFile): array
    {
        $source = file_get_contents($migrationFile);

        if ($source === false) {
            return [];
        }

        $targets = [];
        $tokens = $this->upMethodTokens($source);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ['create', 'table'], true)) {
                continue;
            }

            // Only Schema::create()/Schema::table() calls define schema
            // targets. Look-alikes such as DB::table('users') are
            // query-builder data access, not schema alteration.
            $previous = $tokens[$index - 1] ?? null;
            $beforePrevious = $tokens[$index - 2] ?? null;

            $isSchemaCall = is_array($previous) && $previous[0] === T_DOUBLE_COLON
                && is_array($beforePrevious) && $beforePrevious[0] === T_STRING
                && strcasecmp((string) $beforePrevious[1], 'Schema') === 0;

            if (! $isSchemaCall) {
                continue;
            }

            // Schema::create('users', ...) — the table literal follows the
            // method name and its opening parenthesis.
            $next = $tokens[$index + 1] ?? null;

            if ($next === '(') {
                $next = $tokens[$index + 2] ?? null;
            }

            if (is_array($next) && $next[0] === T_CONSTANT_ENCAPSED_STRING) {
                $targets[] = [$token[1], trim($next[1], "'\"")];
            }
        }

        return $targets;
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
        if ($this->protectingMigrationRun || $this->watchingCustomMigrations) {
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
