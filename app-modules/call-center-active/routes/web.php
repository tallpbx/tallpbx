<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CallCenterActive\Livewire\CallCenterActiveList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/call-center-active', CallCenterActiveList::class)
            ->middleware('admin.can:call-center-active.view')
            ->name('call-center-active.index');
    });
