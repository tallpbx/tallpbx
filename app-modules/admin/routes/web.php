<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Admin\Http\Controllers\ImpersonationController;
use Modules\Admin\Livewire\AdminsEdit;
use Modules\Admin\Livewire\AdminsList;
use Modules\Admin\Livewire\Dashboard;
use Modules\Admin\Livewire\GitUpdate;
use Modules\Admin\Livewire\GroupsEdit;
use Modules\Admin\Livewire\GroupsList;
use Modules\Admin\Livewire\ImpersonationLogsList;
use Modules\Admin\Livewire\ModulesList;
use Modules\Admin\Livewire\Monitoring;
use Modules\Admin\Livewire\NotificationsList;
use Modules\Admin\Livewire\PermissionsList;
use Modules\Admin\Livewire\QueueStatus;
use Modules\Admin\Livewire\SettingsEdit;
use Modules\Admin\Livewire\TenantDomainsEdit;
use Modules\Admin\Livewire\TenantDomainsList;
use Modules\Admin\Livewire\TenantsEdit;
use Modules\Admin\Livewire\TenantsList;
use Modules\Admin\Livewire\UsersEdit;
use Modules\Admin\Livewire\UsersList;
use Modules\Backups\Livewire\BackupsEdit;
use Modules\Backups\Livewire\BackupsList;
use Modules\Backups\Livewire\RestoreArchive;

/*
|--------------------------------------------------------------------------
| Admin Module Routes
|--------------------------------------------------------------------------
|
| All admin panel Livewire page routes and admin-protected controller
| actions. Auth routes (login/logout) remain in the main routes file.
|
*/

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    // ── Dashboard ─────────────────────────────────────────────────────────
    Route::get('/dashboard', Dashboard::class)->middleware('admin.can:admin.dashboard.view')->name('dashboard');

    // ── Monitoring ────────────────────────────────────────────────────────
    Route::get('/monitoring', Monitoring::class)->middleware('admin.can:admin.monitoring.view')->name('monitoring');

    // ── Users ─────────────────────────────────────────────────────────────
    Route::get('/users', UsersList::class)->middleware('admin.can:admin.users.view')->name('users.index');
    Route::get('/users/create', UsersEdit::class)->middleware('admin.can:admin.users.create')->name('users.create');
    Route::get('/users/{userId}/edit', UsersEdit::class)->middleware('admin.can:admin.users.update')->name('users.edit');
    Route::post('/users/{user}/impersonate', [ImpersonationController::class, 'impersonate'])
        ->middleware('admin.can:admin.impersonate')
        ->name('users.impersonate');
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop'])
        ->name('impersonation.stop');
    Route::get('/impersonation-logs', ImpersonationLogsList::class)
        ->middleware('admin.can:admin.impersonate')
        ->name('impersonation-logs.index');

    // ── Administrators ────────────────────────────────────────────────────
    Route::get('/admins', AdminsList::class)->middleware('admin.can:admin.users.view')->name('admins.index');
    Route::get('/admins/create', AdminsEdit::class)->middleware('admin.can:admin.users.create')->name('admins.create');
    Route::get('/admins/{adminId}/edit', AdminsEdit::class)->middleware('admin.can:admin.users.update')->name('admins.edit');

    // ── Tenants ───────────────────────────────────────────────────────────
    Route::get('/tenants', TenantsList::class)->middleware('admin.can:admin.tenants.view')->name('tenants.index');
    Route::get('/tenants/create', TenantsEdit::class)->middleware('admin.can:admin.tenants.create')->name('tenants.create');
    Route::get('/tenants/{tenantId}/edit', TenantsEdit::class)->middleware('admin.can:admin.tenants.update')->name('tenants.edit');

    // ── Tenant Domains ────────────────────────────────────────────────────
    Route::get('/tenant-domains', TenantDomainsList::class)->middleware('admin.can:admin.tenant-domains.view')->name('tenant-domains.index');
    Route::get('/tenant-domains/create', TenantDomainsEdit::class)->middleware('admin.can:admin.tenant-domains.create')->name('tenant-domains.create');
    Route::get('/tenant-domains/{domainId}/edit', TenantDomainsEdit::class)->middleware('admin.can:admin.tenant-domains.update')->name('tenant-domains.edit');

    // ── Groups ────────────────────────────────────────────────────────────
    Route::get('/groups', GroupsList::class)->middleware('admin.can:admin.groups.view')->name('groups.index');
    Route::get('/groups/create', GroupsEdit::class)->middleware('admin.can:admin.groups.create')->name('groups.create');
    Route::get('/groups/{group}/edit', GroupsEdit::class)->middleware('admin.can:admin.groups.update')->name('groups.edit');

    // ── Permissions ───────────────────────────────────────────────────────
    Route::get('/permissions', PermissionsList::class)->middleware('admin.can:admin.permissions.view')->name('permissions.index');

    // ── Settings ──────────────────────────────────────────────────────────
    Route::get('/settings', SettingsEdit::class)->middleware('admin.can:admin.settings.view')->name('settings.index');

    // ── Modules ───────────────────────────────────────────────────────────
    Route::get('/modules', ModulesList::class)->middleware('admin.can:admin.modules.view')->name('modules.index');

    // ── Notifications ─────────────────────────────────────────────────────
    Route::get('/notifications', NotificationsList::class)->middleware('admin.can:admin.notifications.view')->name('notifications.index');

    // ── Queue Status ──────────────────────────────────────────────────────
    Route::get('/queue', QueueStatus::class)->middleware('admin.can:admin.queue.view')->name('queue.index');

    // ── Git Update ────────────────────────────────────────────────────────
    Route::get('/git-update', GitUpdate::class)->middleware('admin.can:admin.git-update.view')->name('git-update');

    // ── Backups ───────────────────────────────────────────────────────────
    Route::get('/backups', BackupsList::class)->middleware('admin.can:backups.view')->name('backups.index');
    Route::get('/backups/create', BackupsEdit::class)->middleware('admin.can:backups.create')->name('backups.create');
    Route::get('/backups/{backupId}/edit', BackupsEdit::class)->middleware('admin.can:backups.edit')->name('backups.edit');
    Route::get('/backups/restore', RestoreArchive::class)->middleware('admin.can:backups.restore')->name('backups.restore');
});
