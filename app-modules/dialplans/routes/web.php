<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Dialplans\Livewire\DialplansEdit;
use Modules\Dialplans\Livewire\DialplansList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/dialplans', DialplansList::class)
            ->middleware('admin.can:dialplans.view')
            ->name('dialplans.index');

        Route::get('/dialplans/create', DialplansEdit::class)
            ->middleware('admin.can:dialplans.create')
            ->name('dialplans.create');

        Route::get('/dialplans/{dialplanId}/edit', DialplansEdit::class)
            ->middleware('admin.can:dialplans.edit')
            ->name('dialplans.edit');
    });
