<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\MigrationSafetyGuard;
use App\Support\PrimaryDatabaseSafety;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Filesystem\Filesystem;

/**
 * Runs Laravel migrations only after checking pending protected-database migrations for data loss.
 */
final class SafeMigrator extends Migrator
{
    /** Store the guard that validates source code and database statements. */
    public function __construct(
        MigrationRepositoryInterface $repository,
        ConnectionResolverInterface $resolver,
        Filesystem $files,
        ?Dispatcher $dispatcher,
        private readonly MigrationSafetyGuard $migrationSafetyGuard,
    ) {
        parent::__construct($repository, $resolver, $files, $dispatcher);
    }

    /** Check pending migrations before Laravel runs any of them on the protected database. */
    public function run($paths = [], array $options = []): array
    {
        $pendingMigrations = array_values(array_filter(
            $this->getMigrationFiles($paths),
            fn (string $file, string $name): bool => ! in_array($name, $this->getRepository()->getRan(), true),
            ARRAY_FILTER_USE_BOTH,
        ));

        // Custom-module migrations are checked on every connection: creating
        // an existing table or altering a protected core table is a packaging
        // bug, not just a data-loss risk (plan §1.7.3).
        $connection = $this->resolveConnection($this->getConnection());
        $this->migrationSafetyGuard->assertNoCustomTableCollisions($pendingMigrations, $connection);
        // Runtime watch catches raw DB::statement DDL and variable table
        // names that static source analysis cannot see.
        $this->migrationSafetyGuard->beginCustomMigrationWatch($connection);

        try {
            if (! PrimaryDatabaseSafety::shouldProtectConnection($this->getConnection())) {
                return parent::run($paths, $options);
            }

            $this->migrationSafetyGuard->assertPendingMigrationsAreSafe($pendingMigrations);
            $this->migrationSafetyGuard->beginProtectedMigrationRun($connection);

            try {
                return parent::run($paths, $options);
            } finally {
                $this->migrationSafetyGuard->endProtectedMigrationRun();
            }
        } finally {
            $this->migrationSafetyGuard->endCustomMigrationWatch();
        }
    }
}
