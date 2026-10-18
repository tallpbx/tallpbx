<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ActiveCalls\Livewire\ActiveCallsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/active-calls', ActiveCallsList::class)
            ->middleware('admin.can:active-calls.view')
            ->name('active-calls.index');
    });
