<?php

declare(strict_types=1);

use App\Database\SafeMigrator;
use App\Models\Tenant;
use App\Models\TenantDomain;
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

it('allows the reviewed tenant-domain index replacement only on an empty schema', function (): void {
    $migration = database_path('migrations/2026_07_13_222217_replace_tenant_domains_domain_unique_with_composite.php');
    $guard = app(MigrationSafetyGuard::class);

    DB::table('tenant_domains')->delete();

    $guard->assertPendingMigrationsAreSafe([$migration]);
    $guard->beginProtectedMigrationRun(DB::connection());
    $guard->startMigration('2026_07_13_222217_replace_tenant_domains_domain_unique_with_composite');

    expect(fn (): null => $guard->assertSqlIsSafe('ALTER TABLE tenant_domains DROP INDEX tenant_domains_domain_unique'))
        ->not->toThrow(RuntimeException::class);
});

it('blocks the reviewed tenant-domain index replacement when domains already exist', function (): void {
    $migration = database_path('migrations/2026_07_13_222217_replace_tenant_domains_domain_unique_with_composite.php');
    $tenant = Tenant::factory()->create();
    $guard = app(MigrationSafetyGuard::class);

    TenantDomain::factory()->create(['tenant_id' => $tenant->id]);

    $guard->assertPendingMigrationsAreSafe([$migration]);
    $guard->beginProtectedMigrationRun(DB::connection());
    $guard->startMigration('2026_07_13_222217_replace_tenant_domains_domain_unique_with_composite');

    expect(fn (): null => $guard->assertSqlIsSafe('ALTER TABLE tenant_domains DROP INDEX tenant_domains_domain_unique'))
        ->toThrow(RuntimeException::class, 'Destructive migration blocked to protect TallPBX data');
});

it('allows no other destructive SQL during the reviewed fresh-schema migration', function (): void {
    $migration = database_path('migrations/2026_07_13_222217_replace_tenant_domains_domain_unique_with_composite.php');
    $guard = app(MigrationSafetyGuard::class);

    DB::table('tenant_domains')->delete();

    $guard->assertPendingMigrationsAreSafe([$migration]);
    $guard->beginProtectedMigrationRun(DB::connection());
    $guard->startMigration('2026_07_13_222217_replace_tenant_domains_domain_unique_with_composite');

    expect(fn (): null => $guard->assertSqlIsSafe('ALTER TABLE tenant_domains DROP COLUMN domain'))
        ->toThrow(RuntimeException::class, 'Destructive migration blocked to protect TallPBX data');
});

it('blocks artisan migrate before a destructive pending migration can run', function (): void {
    $migration = temporaryMigrationFile('Schema::dropIfExists(\'migration_safety_guard_probe\');');
    $originalPrimaryDatabase = config('app.primary_database');
    $originalDatabaseName = config('database.connections.sqlite.database');

    // The shared test connection is in-memory, which is intentionally never
    // protected. Name it as a protected connection while retaining its PDO so
    // this test can exercise the protected-database migrator path.
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
