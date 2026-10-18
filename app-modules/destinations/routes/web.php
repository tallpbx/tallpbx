<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Destinations\Livewire\DestinationsEdit;
use Modules\Destinations\Livewire\DestinationsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/destinations', DestinationsList::class)
            ->middleware('admin.can:destinations.view')
            ->name('destinations.index');

        Route::get('/destinations/create', DestinationsEdit::class)
            ->middleware('admin.can:destinations.create')
            ->name('destinations.create');

        Route::get('/destinations/{destinationId}/edit', DestinationsEdit::class)
            ->middleware('admin.can:destinations.edit')
            ->name('destinations.edit');
    });
