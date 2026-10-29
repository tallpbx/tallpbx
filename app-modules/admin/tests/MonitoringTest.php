<?php

declare(strict_types=1);

use App\Models\Admin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Modules\Admin\Livewire\Monitoring;

beforeEach(function (): void {
    DB::table('jobs')->truncate();
    DB::table('failed_jobs')->truncate();

    DB::table('permissions')->insertOrIgnore([
        [
            'id' => Str::uuid()->toString(),
            'name' => 'admin.monitoring.view',
            'module' => 'admin',
            'description' => 'View monitoring metrics.',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => Str::uuid()->toString(),
            'name' => 'admin.queue.view',
            'module' => 'admin',
            'description' => 'View queue worker status.',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => Str::uuid()->toString(),
            'name' => 'admin.queue.retry',
            'module' => 'admin',
            'description' => 'Retry failed queue jobs.',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);
});

it('renders monitoring page and hides queue modal trigger for admins without queue view permission', function (): void {
    $admin = grantAdminPermissions(Admin::factory()->create(), ['admin.monitoring.view']);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'TestFailedJob']),
        'exception' => 'TestException: something failed',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(Monitoring::class)
        ->assertOk()
        ->assertSee(__('admin.monitoring_queues'))
        ->assertDontSee(__('admin.queue_review_failed'))
        ->assertDontSee('wire:click="openQueueModal"', false);
});

it('shows queue modal triggers when admin holds admin.queue.view permission', function (): void {
    $admin = grantAdminPermissions(Admin::factory()->create(), ['admin.monitoring.view', 'admin.queue.view']);

    DB::table('failed_jobs')->insert([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'TestFailedJob']),
        'exception' => 'TestException: something failed',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(Monitoring::class)
        ->assertOk()
        ->assertSee(__('admin.queue_review_failed'))
        ->assertSee('wire:click="openQueueModal"', false);
});

it('aborts with 403 when admin without queue view permission calls openQueueModal', function (): void {
    $admin = grantAdminPermissions(Admin::factory()->create(), ['admin.monitoring.view']);

    Livewire::actingAs($admin, 'admin')
        ->test(Monitoring::class)
        ->call('openQueueModal')
        ->assertForbidden();
});

it('aborts with 403 when admin without queue view permission calls loadRecentFailures', function (): void {
    $admin = grantAdminPermissions(Admin::factory()->create(), ['admin.monitoring.view']);

    Livewire::actingAs($admin, 'admin')
        ->test(Monitoring::class)
        ->call('loadRecentFailures')
        ->assertForbidden();
});

it('allows authorized admin to open queue modal, load failures, and retry', function (): void {
    $admin = grantAdminPermissions(Admin::factory()->create(), ['admin.monitoring.view', 'admin.queue.view', 'admin.queue.retry']);

    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode(['displayName' => 'TestFailedJob']),
        'exception' => 'TestException: something broke',
        'failed_at' => now(),
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test(Monitoring::class)
        ->call('openQueueModal')
        ->assertSet('showQueueModal', true)
        ->assertSee('TestFailedJob')
        ->assertSee('TestException: something broke')
        ->call('retry', $uuid)
        ->assertSet('actionError', fn (?string $err): bool => $err === null || str_contains($err, 'Unable to find failed job'))
        ->call('closeQueueModal')
        ->assertSet('showQueueModal', false);
});
