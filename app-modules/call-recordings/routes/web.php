<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CallRecordings\Livewire\CallRecordingsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/call-recordings', CallRecordingsList::class)
            ->middleware('admin.can:call-recordings.view')
            ->name('call-recordings.index');
    });
