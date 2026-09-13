<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\EventGuard\Livewire\EventGuardSettings;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/event-guard', EventGuardSettings::class)->middleware('admin.can:event-guard.view')->name('event-guard.index');
});
