<?php

declare(strict_types=1);

use App\Database\SafeMigrator;
use App\Support\MigrationSafetyGuard;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/** Create a temporary migration file containing the supplied up and down methods. */
function temporaryMigrationFile(string $upMethod, string $downMethod = ''): string
{
    $path = tempnam(sys_get_temp_dir(), 'tallpbx-migration-');

    if ($path === false) {
        throw new RuntimeException('Unable to create a temporary migration file.');
    }

    $migrationPath = $path.'.php';

    rename($path, $migrationPath);

    file_put_contents($migrationPath, <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;

return new class extends Migration {
    public function up(): void
    {
        {$upMethod}
    }

    public function down(): void
    {
        {$downMethod}
    }
};
PHP);

    return $migrationPath;
}

afterEach(function (): void {
    app(MigrationSafetyGuard::class)->endProtectedMigrationRun();

    foreach (glob(sys_get_temp_dir().'/tallpbx-migration-*') ?: [] as $path) {
        unlink($path);
    }
});

it('accepts an additive pending migration', function (): void {
    $migration = temporaryMigrationFile('Schema::table(\'backups\', fn ($table) => $table->string(\'label\')->nullable());');

    app(MigrationSafetyGuard::class)->assertPendingMigrationsAreSafe([$migration]);

    expect(true)->toBeTrue();
});

it('rejects a destructive operation in a pending migration up method', function (): void {
    $migration = temporaryMigrationFile('Schema::dropIfExists(\'backups\');');

    expect(fn (): array => app(MigrationSafetyGuard::class)->assertPendingMigrationsAreSafe([$migration]))
        ->toThrow(RuntimeException::class, 'Destructive migration blocked to protect TallPBX data')
        ->toThrow(RuntimeException::class, 'dropIfExists');
});

it('allows destructive operations in a migration down method', function (): void {
    $migration = temporaryMigrationFile('', 'Schema::dropIfExists(\'backups\');');

    app(MigrationSafetyGuard::class)->assertPendingMigrationsAreSafe([$migration]);

    expect(true)->toBeTrue();
});

it('rejects destructive SQL before it is executed while a migration up method is active', function (): void {
    $guard = app(MigrationSafetyGuard::class);
    $guard->beginProtectedMigrationRun(DB::connection());
    $guard->startMigration('remove_backup_data');

    expect(fn (): null => $guard->assertSqlIsSafe('ALTER TABLE backups DROP COLUMN encryption'))
        ->toThrow(RuntimeException::class, 'Destructive migration blocked to protect TallPBX data')
        ->toThrow(RuntimeException::class, 'remove_backup_data');
});

it('blocks artisan migrate before a destructive pending migration can run', function (): void {
    $migration = temporaryMigrationFile('Schema::dropIfExists(\'migration_safety_guard_probe\');');
    $originalPrimaryDatabase = config('app.primary_database');
    $originalDatabaseName = config('database.connections.sqlite.database');

    // Ensure test database is already migrated before simulating a protected database
    DB::table('migrations')->count();

    config([
        'app.primary_database' => 'migration_safety_guard_test',
        'database.connections.sqlite.database' => 'migration_safety_guard_test',
    ]);

    try {
        expect(app('migrator'))->toBeInstanceOf(SafeMigrator::class);

        expect(fn (): int => Artisan::call('migrate', [
            '--force' => true,
            '--path' => [$migration],
            '--realpath' => true,
        ]))->toThrow(RuntimeException::class, 'Destructive migration blocked to protect TallPBX data');
    } finally {
        config([
            'app.primary_database' => $originalPrimaryDatabase,
            'database.connections.sqlite.database' => $originalDatabaseName,
        ]);
    }
});
