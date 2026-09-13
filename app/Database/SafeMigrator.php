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
        if (! PrimaryDatabaseSafety::shouldProtectConnection($this->getConnection())) {
            return parent::run($paths, $options);
        }

        $pendingMigrations = array_filter(
            $this->getMigrationFiles($paths),
            fn (string $file, string $name): bool => ! in_array($name, $this->getRepository()->getRan(), true),
            ARRAY_FILTER_USE_BOTH,
        );

        $this->migrationSafetyGuard->assertPendingMigrationsAreSafe(array_values($pendingMigrations));
        $this->migrationSafetyGuard->beginProtectedMigrationRun($this->resolveConnection($this->getConnection()));

        try {
            return parent::run($paths, $options);
        } finally {
            $this->migrationSafetyGuard->endProtectedMigrationRun();
        }
    }
}
