<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Devices\Livewire\DevicesEdit;
use Modules\Devices\Livewire\DevicesList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/devices', DevicesList::class)
            ->middleware('admin.can:devices.view')
            ->name('devices.index');

        Route::get('/devices/create', DevicesEdit::class)
            ->middleware('admin.can:devices.create')
            ->name('devices.create');

        Route::get('/devices/{deviceId}/edit', DevicesEdit::class)
            ->middleware('admin.can:devices.edit')
            ->name('devices.edit');
    });
