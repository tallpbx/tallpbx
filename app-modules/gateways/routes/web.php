<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Gateways\Livewire\GatewaysEdit;
use Modules\Gateways\Livewire\GatewaysList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/gateways', GatewaysList::class)
            ->middleware('admin.can:gateways.view')
            ->name('gateways.index');

        Route::get('/gateways/create', GatewaysEdit::class)
            ->middleware('admin.can:gateways.create')
            ->name('gateways.create');

        Route::get('/gateways/{gatewayId}/edit', GatewaysEdit::class)
            ->middleware('admin.can:gateways.edit')
            ->name('gateways.edit');
    });
