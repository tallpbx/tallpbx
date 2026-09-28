<?php

declare(strict_types=1);

use App\Http\Middleware\AdminAuthorize;
use App\Jobs\ReloadFreeSwitchXml;
use App\Models\Admin;
use App\Models\Group;
use App\Models\Permission;
use App\Models\Tenant;
use App\Services\TenantManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Admin\Livewire\QueueStatus;

beforeEach(function (): void {
    DB::table('jobs')->truncate();
    DB::table('failed_jobs')->truncate();

    // Ensure the queue.view permission exists for the admin.
    DB::table('permissions')->insertOrIgnore([
        'id' => Str::uuid()->toString(),
        'name' => 'admin.queue.view',
        'module' => 'admin',
        'description' => 'View queue worker status.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('shows queue status page to authenticated admin', function (): void {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')
        ->withoutMiddleware(AdminAuthorize::class)
        ->get('/panel/queue')
        ->assertOk()
        ->assertSee('Queue Status');
});

it('shows zero counts when no jobs exist', function (): void {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')
        ->withoutMiddleware(AdminAuthorize::class)
        ->get('/panel/queue')
        ->assertSee('No failed jobs');
});

it('shows pending and failed job counts', function (): void {
    $admin = Admin::factory()->create();

    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['data' => ['command' => serialize(new ReloadFreeSwitchXml)]]),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->timestamp,
        'created_at' => now()->timestamp,
    ]);

    DB::table('failed_jobs')->insert([
        'uuid' => 'test-uuid',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['data' => ['command' => serialize(new ReloadFreeSwitchXml)]]),
        'exception' => "TestException: Something went wrong\n#0 /var/www/tallpbx/vendor/laravel/framework/src/Illuminate/Queue/Worker.php:123\n",
        'failed_at' => now(),
    ]);

    $this->actingAs($admin, 'admin')
        ->withoutMiddleware(AdminAuthorize::class)
        ->get('/panel/queue')
        ->assertOk()
        ->assertSee('TestException');
});

it('denies the queue page to an admin without the permission', function (): void {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')
        ->get('/panel/queue')
        ->assertForbidden();
});

it('denies the queue page and retry actions to tenant users', function (): void {
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);
    // Grant attempts are inert: the user permission model excludes admin.*.
    $permission = Permission::firstWhere('name', 'admin.queue.view');
    $group = Group::factory()->system()->create();
    $group->permissions()->attach($permission);
    $user->groups()->attach($group);

    DB::table('failed_jobs')->insert([
        'uuid' => 'job-uuid-1',
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['data' => ['command' => serialize(new ReloadFreeSwitchXml)]]),
        'exception' => 'TestException: boom',
        'failed_at' => now(),
    ]);

    // The route gate denies tenant users regardless of grant attempts...
    $this->actingAs($user, 'web')
        ->get('/panel/queue')
        ->assertForbidden();

    // ...and the component re-authorizes even on direct calls.
    Livewire::actingAs($user, 'web')
        ->test(QueueStatus::class)
        ->call('retryAll')
        ->assertSet('actionError', 'You do not have permission to retry failed jobs.');

    $this->assertDatabaseCount('failed_jobs', 1);
    app(TenantManager::class)->setTenantId(null);
});

it('retries a failed job when an authorized admin acts', function (): void {
    $admin = grantAdminPermissions(Admin::factory()->create(), ['admin.queue.view']);

    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['data' => ['command' => serialize(new ReloadFreeSwitchXml)]]),
        'exception' => 'TestException: boom',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(QueueStatus::class)
        ->call('retry', $uuid)
        ->assertSet('retryResult', "Job {$uuid} pushed back to the queue.");

    // queue:retry moves the row back to the jobs table (worker not running).
    $this->assertDatabaseCount('failed_jobs', 0);
    $this->assertDatabaseCount('jobs', 1);
});

it('rejects a non-numeric retry id without requeueing', function (): void {
    $admin = grantAdminPermissions(Admin::factory()->create(), ['admin.queue.view']);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['data' => ['command' => serialize(new ReloadFreeSwitchXml)]]),
        'exception' => 'TestException: boom',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(QueueStatus::class)
        ->call('retry', 'all')
        ->assertSet('actionError', 'The job id is not valid.');

    // The failed job survives — nothing was requeued.
    $this->assertDatabaseCount('failed_jobs', 1);
    $this->assertDatabaseCount('jobs', 0);
});

it('exposes the failed job uuid for the retry action', function (): void {
    $admin = grantAdminPermissions(Admin::factory()->create(), ['admin.queue.view']);

    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['data' => ['command' => serialize(new ReloadFreeSwitchXml)]]),
        'exception' => 'TestException: boom',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(QueueStatus::class)
        ->assertSet('recentFailures', fn (array $rows): bool => $rows[0]['uuid'] === $uuid);
});

it('reports an error when retrying an unknown job id', function (): void {
    $admin = grantAdminPermissions(Admin::factory()->create(), ['admin.queue.view']);

    Livewire::actingAs($admin, 'admin')
        ->test(QueueStatus::class)
        ->call('retry', (string) Str::uuid())
        ->assertSet('actionError', fn (string $message): bool => str_contains($message, 'Unable to find failed job'));
});
