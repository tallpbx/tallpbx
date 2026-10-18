<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Bridges\Livewire\BridgesEdit;
use Modules\Bridges\Livewire\BridgesList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/bridges', BridgesList::class)
            ->middleware('admin.can:bridges.view')
            ->name('bridges.index');

        Route::get('/bridges/create', BridgesEdit::class)
            ->middleware('admin.can:bridges.create')
            ->name('bridges.create');

        Route::get('/bridges/{bridgeId}/edit', BridgesEdit::class)
            ->middleware('admin.can:bridges.edit')
            ->name('bridges.edit');
    });
