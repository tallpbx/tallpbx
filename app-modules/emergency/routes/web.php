<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Emergency\Livewire\EmergencyEdit;
use Modules\Emergency\Livewire\EmergencyList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/emergency', EmergencyList::class)
            ->middleware('admin.can:emergency.view')
            ->name('emergency.index');

        Route::get('/emergency/create', EmergencyEdit::class)
            ->middleware('admin.can:emergency.create')
            ->name('emergency.create');

        Route::get('/emergency/{emergencyId}/edit', EmergencyEdit::class)
            ->middleware('admin.can:emergency.edit')
            ->name('emergency.edit');
    });
