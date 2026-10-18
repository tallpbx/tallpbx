<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Recordings\Livewire\RecordingsEdit;
use Modules\Recordings\Livewire\RecordingsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/recordings', RecordingsList::class)
            ->middleware('admin.can:recordings.view')
            ->name('recordings.index');

        Route::get('/recordings/create', RecordingsEdit::class)
            ->middleware('admin.can:recordings.create')
            ->name('recordings.create');

        Route::get('/recordings/{recordingId}/edit', RecordingsEdit::class)
            ->middleware('admin.can:recordings.edit')
            ->name('recordings.edit');
    });
