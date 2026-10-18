<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\HotDesking\Livewire\HotDeskingEdit;
use Modules\HotDesking\Livewire\HotDeskingList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/hot-desking', HotDeskingList::class)
            ->middleware('admin.can:hot-desking.view')
            ->name('hot-desking.index');

        Route::get('/hot-desking/create', HotDeskingEdit::class)
            ->middleware('admin.can:hot-desking.create')
            ->name('hot-desking.create');

        Route::get('/hot-desking/{sessionId}/edit', HotDeskingEdit::class)
            ->middleware('admin.can:hot-desking.edit')
            ->name('hot-desking.edit');
    });
