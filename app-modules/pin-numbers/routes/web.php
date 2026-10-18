<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\PinNumbers\Livewire\PinNumbersEdit;
use Modules\PinNumbers\Livewire\PinNumbersList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/pin-numbers', PinNumbersList::class)
            ->middleware('admin.can:pin-numbers.view')
            ->name('pin-numbers.index');

        Route::get('/pin-numbers/create', PinNumbersEdit::class)
            ->middleware('admin.can:pin-numbers.create')
            ->name('pin-numbers.create');

        Route::get('/pin-numbers/{pinId}/edit', PinNumbersEdit::class)
            ->middleware('admin.can:pin-numbers.edit')
            ->name('pin-numbers.edit');
    });
