<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\EventRateLimits\Livewire\EventRateLimitsSettings;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/event-rate-limits', EventRateLimitsSettings::class)->middleware('admin.can:event-rate-limits.view')->name('event-rate-limits.index');
});
