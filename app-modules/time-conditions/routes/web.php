<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TimeConditions\Livewire\TimeConditionsEdit;
use Modules\TimeConditions\Livewire\TimeConditionsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/time-conditions', TimeConditionsList::class)
            ->middleware('admin.can:time-conditions.view')
            ->name('time-conditions.index');

        Route::get('/time-conditions/create', TimeConditionsEdit::class)
            ->middleware('admin.can:time-conditions.create')
            ->name('time-conditions.create');

        Route::get('/time-conditions/{timeConditionId}/edit', TimeConditionsEdit::class)
            ->middleware('admin.can:time-conditions.edit')
            ->name('time-conditions.edit');
    });
