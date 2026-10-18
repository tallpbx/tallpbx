<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SipStatus\Livewire\SipStatusList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/sip-status', SipStatusList::class)
            ->middleware('admin.can:sip-status.view')
            ->name('sip-status.index');
    });
