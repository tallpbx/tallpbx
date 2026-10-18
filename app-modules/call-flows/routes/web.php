<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CallFlows\Livewire\CallFlowsEdit;
use Modules\CallFlows\Livewire\CallFlowsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/call-flows', CallFlowsList::class)
            ->middleware('admin.can:call-flows.view')
            ->name('call-flows.index');

        Route::get('/call-flows/create', CallFlowsEdit::class)
            ->middleware('admin.can:call-flows.create')
            ->name('call-flows.create');

        Route::get('/call-flows/{callFlowId}/edit', CallFlowsEdit::class)
            ->middleware('admin.can:call-flows.edit')
            ->name('call-flows.edit');
    });
