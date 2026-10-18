<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Registrations\Livewire\RegistrationsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/registrations', RegistrationsList::class)
            ->middleware('admin.can:registrations.view')
            ->name('registrations.index');
    });
