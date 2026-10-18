<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\MusicOnHold\Livewire\MusicOnHoldEdit;
use Modules\MusicOnHold\Livewire\MusicOnHoldList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/music-on-hold', MusicOnHoldList::class)
            ->middleware('admin.can:music-on-hold.view')
            ->name('music-on-hold.index');

        Route::get('/music-on-hold/create', MusicOnHoldEdit::class)
            ->middleware('admin.can:music-on-hold.create')
            ->name('music-on-hold.create');

        Route::get('/music-on-hold/{mohId}/edit', MusicOnHoldEdit::class)
            ->middleware('admin.can:music-on-hold.edit')
            ->name('music-on-hold.edit');
    });
