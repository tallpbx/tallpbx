<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CallBlocks\Livewire\CallBlocksEdit;
use Modules\CallBlocks\Livewire\CallBlocksList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/call-blocks', CallBlocksList::class)
            ->middleware('admin.can:call-blocks.view')
            ->name('call-blocks.index');

        Route::get('/call-blocks/create', CallBlocksEdit::class)
            ->middleware('admin.can:call-blocks.create')
            ->name('call-blocks.create');

        Route::get('/call-blocks/{blockId}/edit', CallBlocksEdit::class)
            ->middleware('admin.can:call-blocks.edit')
            ->name('call-blocks.edit');
    });
