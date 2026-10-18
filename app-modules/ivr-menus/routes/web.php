<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\IvrMenus\Livewire\IvrMenusEdit;
use Modules\IvrMenus\Livewire\IvrMenusList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/ivr-menus', IvrMenusList::class)
            ->middleware('admin.can:ivr-menus.view')
            ->name('ivr-menus.index');

        Route::get('/ivr-menus/create', IvrMenusEdit::class)
            ->middleware('admin.can:ivr-menus.create')
            ->name('ivr-menus.create');

        Route::get('/ivr-menus/{menuUuid}/edit', IvrMenusEdit::class)
            ->middleware('admin.can:ivr-menus.edit')
            ->name('ivr-menus.edit');
    });
