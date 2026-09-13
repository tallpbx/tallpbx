<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Logs\Livewire\LogViewer;

/*
|--------------------------------------------------------------------------
| logs Module Routes
|--------------------------------------------------------------------------
|
| Admin route for the Log Viewer. Accessible at /admin/logs.
|
*/

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/logs', LogViewer::class)->middleware('admin.can:logs.view')->name('logs.index');
});
