<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SipTrunks\Livewire\SipTrunksEdit;
use Modules\SipTrunks\Livewire\SipTrunksList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/sip-trunks', SipTrunksList::class)
            ->middleware('admin.can:sip-trunks.view')
            ->name('sip-trunks.index');

        Route::get('/sip-trunks/create', SipTrunksEdit::class)
            ->middleware('admin.can:sip-trunks.create')
            ->name('sip-trunks.create');

        Route::get('/sip-trunks/{trunkId}/edit', SipTrunksEdit::class)
            ->middleware('admin.can:sip-trunks.edit')
            ->name('sip-trunks.edit');
    });
