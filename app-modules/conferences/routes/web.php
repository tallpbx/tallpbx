<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Conferences\Livewire\ConferencesEdit;
use Modules\Conferences\Livewire\ConferencesList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/conferences', ConferencesList::class)
            ->middleware('admin.can:conferences.view')
            ->name('conferences.index');

        Route::get('/conferences/create', ConferencesEdit::class)
            ->middleware('admin.can:conferences.create')
            ->name('conferences.create');

        Route::get('/conferences/{conferenceId}/edit', ConferencesEdit::class)
            ->middleware('admin.can:conferences.edit')
            ->name('conferences.edit');
    });
