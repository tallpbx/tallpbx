<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ActiveConferences\Livewire\ActiveConferencesList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/active-conferences', ActiveConferencesList::class)
            ->middleware('admin.can:active-conferences.view')
            ->name('active-conferences.index');
    });
