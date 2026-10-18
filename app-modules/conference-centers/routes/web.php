<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ConferenceCenters\Livewire\ConferenceCentersEdit;
use Modules\ConferenceCenters\Livewire\ConferenceCentersList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/conference-centers', ConferenceCentersList::class)
            ->middleware('admin.can:conference-centers.view')
            ->name('conference-centers.index');

        Route::get('/conference-centers/create', ConferenceCentersEdit::class)
            ->middleware('admin.can:conference-centers.create')
            ->name('conference-centers.create');

        Route::get('/conference-centers/{conferenceCenterId}/edit', ConferenceCentersEdit::class)
            ->middleware('admin.can:conference-centers.edit')
            ->name('conference-centers.edit');
    });
