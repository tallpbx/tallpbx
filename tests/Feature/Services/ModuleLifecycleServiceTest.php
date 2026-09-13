<?php

declare(strict_types=1);

use App\Contracts\ModuleUninstaller;
use App\Models\Module;
use App\Models\Permission;
use App\Models\Tenant;
use App\Services\ModuleLifecycleService;
use App\Support\ModuleTableUninstaller;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\PinNumbers\Models\PinNumber;

it('requires an explicit uninstall handler', function () {
    $module = Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0.0',
    ]);

    $preview = app(ModuleLifecycleService::class)->previewUninstall($module);

    expect($preview['can_uninstall'])->toBeFalse()
        ->and($preview['reason'])->toBe('This module does not provide an uninstall handler.');
});

it('prevents protected modules from being uninstalled', function () {
    $module = Module::create([
        'name' => 'admin',
        'display_name' => 'Admin',
        'version' => '1.0.0',
        'protected' => true,
        'required' => true,
    ]);

    $preview = app(ModuleLifecycleService::class)->previewUninstall($module);

    expect($preview['can_uninstall'])->toBeFalse()
        ->and($preview['reason'])->toBe('Required and protected modules cannot be uninstalled.');
});

it('requires the exact confirmation phrase before uninstalling', function () {
    $module = Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0.0',
    ]);

    registerTestUninstaller(testModuleUninstaller('extensions'));

    app(ModuleLifecycleService::class)->uninstall($module, 'wrong phrase');
})->throws(ValidationException::class);

it('uninstalls module data and permissions through the registered handler', function () {
    $module = Module::create([
        'name' => 'extensions',
        'display_name' => 'Extensions',
        'version' => '1.0.0',
    ]);

    Permission::create([
        'name' => 'extensions.view',
        'module' => 'extensions',
        'description' => 'View extensions',
    ]);

    $uninstaller = testModuleUninstaller('extensions');
    registerTestUninstaller($uninstaller);

    Artisan::shouldReceive('call')
        ->once()
        ->with('optimize:clear')
        ->andReturn(0);

    app(ModuleLifecycleService::class)->uninstall($module, 'UNINSTALL extensions');

    $module->refresh();

    expect($uninstaller->uninstalled)->toBeTrue()
        ->and($module->enabled)->toBeFalse()
        ->and($module->status)->toBe(Module::StatusUninstalled)
        ->and(Permission::where('module', 'extensions')->exists())->toBeFalse();
});

it('reinstalls an uninstalled module from its discovered manifest', function () {
    $module = Module::create([
        'name' => 'extensions',
        'display_name' => 'Old Extensions',
        'version' => '0.1.0',
        'enabled' => false,
        'status' => Module::StatusUninstalled,
    ]);

    Artisan::shouldReceive('call')
        ->once()
        ->with('migrate', [
            '--path' => base_path('app-modules/extensions/database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ])
        ->andReturn(0);

    Artisan::shouldReceive('call')
        ->once()
        ->with('optimize:clear')
        ->andReturn(0);

    $reinstalled = app(ModuleLifecycleService::class)->reinstall('extensions');

    expect($reinstalled->id)->toBe($module->id);

    $module->refresh();

    expect($module->display_name)->toBe('Extensions')
        ->and($module->enabled)->toBeTrue()
        ->and($module->status)->toBe(Module::StatusEnabled);
});

it('previews the first reviewed table-owned module uninstall handlers', function (string $moduleName): void {
    $module = Module::create([
        'name' => $moduleName,
        'display_name' => str($moduleName)->replace('-', ' ')->title()->toString(),
        'version' => '1.0.0',
    ]);

    $preview = app(ModuleLifecycleService::class)->previewUninstall($module);

    expect($preview['can_uninstall'])->toBeTrue()
        ->and($preview['reason'])->toBeNull()
        ->and($preview['items'])->not->toBeEmpty();
})->with([
    'PIN numbers' => 'pin-numbers',
    'access controls' => 'access-controls',
    'email templates' => 'email-templates',
    'email queue' => 'email-queue',
    'tenant limits' => 'tenant-limits',
    'call broadcast' => 'call-broadcast',
]);

it('uninstalls only reviewed module schema and recreates usable schema on reinstall', function (
    string $moduleName,
    array $tables,
    string $migration,
): void {
    $tenant = Tenant::factory()->create();
    $unrelatedTenant = Tenant::factory()->create();
    $unrelatedPinNumber = PinNumber::factory()->forTenant($unrelatedTenant->id)->create();
    $unrelatedPermission = Permission::create([
        'name' => 'unrelated.view',
        'module' => 'unrelated',
        'description' => 'View unrelated module data',
    ]);

    $module = Module::create([
        'name' => $moduleName,
        'display_name' => str($moduleName)->replace('-', ' ')->title()->toString(),
        'version' => '1.0.0',
    ]);

    Permission::create([
        'name' => $moduleName.'.view',
        'module' => $moduleName,
        'description' => 'View module data',
    ]);

    createReviewedModuleRecords($moduleName, $tenant->id);

    expect(DB::table('migrations')->where('migration', $migration)->exists())->toBeTrue();

    app(ModuleLifecycleService::class)->uninstall($module, 'UNINSTALL '.$moduleName);

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }

    expect(DB::table('migrations')->where('migration', $migration)->exists())->toBeFalse()
        ->and(Permission::where('module', $moduleName)->exists())->toBeFalse();

    $this->assertModelExists($tenant);
    $this->assertModelExists($unrelatedTenant);
    $this->assertModelExists($unrelatedPinNumber);
    $this->assertModelExists($unrelatedPermission);

    $reinstalled = app(ModuleLifecycleService::class)->reinstall($moduleName);

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    expect($reinstalled->enabled)->toBeTrue()
        ->and($reinstalled->status)->toBe(Module::StatusEnabled)
        ->and(DB::table('migrations')->where('migration', $migration)->exists())->toBeTrue();

    createReviewedModuleRecords($moduleName, $tenant->id);

    foreach ($tables as $table) {
        expect(DB::table($table)->count())->toBe(1);
    }
})->with([
    'email queue' => [
        'email-queue',
        ['email_queue'],
        '2026_07_06_000021_create_email_queue_table',
    ],
    'tenant limits' => [
        'tenant-limits',
        ['tenant_limits'],
        '2026_07_06_000016_create_tenant_limits_table',
    ],
    'call broadcast' => [
        'call-broadcast',
        ['call_broadcasts', 'call_broadcast_recipients'],
        '2026_07_06_000011_create_call_broadcasts_table',
    ],
]);

it('drops table-owned module tables and clears their migration records', function (): void {
    Schema::create('test_owned_parent', fn ($table) => $table->id());
    Schema::create('test_owned_child', fn ($table) => $table->id());

    DB::table('migrations')->insert([
        'migration' => '2099_01_01_000001_create_test_owned_tables',
        'batch' => 1,
    ]);

    $module = Module::create([
        'name' => 'test-owned',
        'display_name' => 'Test Owned',
        'version' => '1.0.0',
    ]);

    testTableUninstaller()->uninstall($module);

    expect(Schema::hasTable('test_owned_child'))->toBeFalse()
        ->and(Schema::hasTable('test_owned_parent'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', '2099_01_01_000001_create_test_owned_tables')->exists())->toBeFalse();
});

function registerTestUninstaller(ModuleUninstaller $uninstaller): void
{
    $binding = 'tests.module-lifecycle.uninstaller';

    app()->instance($binding, $uninstaller);
    app()->tag([$binding], 'module.uninstallers');
}

function testModuleUninstaller(string $moduleName): ModuleUninstaller
{
    return new class($moduleName) implements ModuleUninstaller
    {
        public bool $uninstalled = false;

        public function __construct(private readonly string $moduleName) {}

        public function moduleName(): string
        {
            return $this->moduleName;
        }

        public function canUninstall(Module $module): bool
        {
            return true;
        }

        public function previewUninstall(Module $module): array
        {
            return ['Drop extension-owned tables'];
        }

        public function uninstall(Module $module): void
        {
            $this->uninstalled = true;
        }
    };
}

function testTableUninstaller(): ModuleTableUninstaller
{
    return new class extends ModuleTableUninstaller
    {
        public function moduleName(): string
        {
            return 'test-owned';
        }

        protected function tables(): array
        {
            return ['test_owned_parent', 'test_owned_child'];
        }

        protected function migrations(): array
        {
            return ['2099_01_01_000001_create_test_owned_tables'];
        }
    };
}

/**
 * Insert representative records into a reviewed module's owned tables.
 */
function createReviewedModuleRecords(string $moduleName, int $tenantId): void
{
    $now = now();

    match ($moduleName) {
        'email-queue' => DB::table('email_queue')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'to' => 'recipient@example.com',
            'subject' => 'Lifecycle test',
            'body' => 'Module-owned queued email.',
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]),
        'tenant-limits' => DB::table('tenant_limits')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'resource' => 'extensions',
            'soft_limit' => 10,
            'hard_limit' => 20,
            'created_at' => $now,
            'updated_at' => $now,
        ]),
        'call-broadcast' => createCallBroadcastRecords($tenantId, $now),
        default => throw new InvalidArgumentException("Unsupported reviewed module [{$moduleName}]."),
    };
}

/**
 * Insert a call broadcast and its owned recipient record.
 */
function createCallBroadcastRecords(int $tenantId, DateTimeInterface $now): bool
{
    $broadcastId = (string) Str::uuid();

    DB::table('call_broadcasts')->insert([
        'id' => $broadcastId,
        'tenant_id' => $tenantId,
        'name' => 'Lifecycle test broadcast',
        'status' => 'draft',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    return DB::table('call_broadcast_recipients')->insert([
        'id' => (string) Str::uuid(),
        'broadcast_id' => $broadcastId,
        'phone_number' => '+15555550100',
        'call_status' => 'pending',
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
