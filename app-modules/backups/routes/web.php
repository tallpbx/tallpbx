<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Backups Module Routes
|--------------------------------------------------------------------------
|
| Panel routes for the autonomous system-superadmin backup utility. The
| Backups module owns its routes, menu entry, and permissions so disaster
| recovery remains a self-contained Core Administration capability.
|
*/

use Illuminate\Support\Facades\Route;
use Modules\Backups\Livewire\BackupsEdit;
use Modules\Backups\Livewire\BackupsList;
use Modules\Backups\Livewire\RestoreArchive;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    // ── Backups ───────────────────────────────────────────────────────────
    Route::get('/backups', BackupsList::class)->middleware('admin.can:backups.view')->name('backups.index');
    Route::get('/backups/create', BackupsEdit::class)->middleware('admin.can:backups.create')->name('backups.create');
    Route::get('/backups/{backupId}/edit', BackupsEdit::class)->middleware('admin.can:backups.edit')->name('backups.edit');
    Route::get('/backups/restore', RestoreArchive::class)->middleware('admin.can:backups.restore')->name('backups.restore');
});
