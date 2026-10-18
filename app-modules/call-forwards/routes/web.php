<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CallForwards\Livewire\CallForwardsEdit;
use Modules\CallForwards\Livewire\CallForwardsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/call-forwards', CallForwardsList::class)
            ->middleware('admin.can:call-forwards.view')
            ->name('call-forwards.index');

        Route::get('/call-forwards/create', CallForwardsEdit::class)
            ->middleware('admin.can:call-forwards.create')
            ->name('call-forwards.create');

        Route::get('/call-forwards/{forwardId}/edit', CallForwardsEdit::class)
            ->middleware('admin.can:call-forwards.edit')
            ->name('call-forwards.edit');
    });
