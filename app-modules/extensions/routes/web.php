<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Extensions\Livewire\ExtensionsBulkCreate;
use Modules\Extensions\Livewire\ExtensionsEdit;
use Modules\Extensions\Livewire\ExtensionsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/extensions', ExtensionsList::class)
            ->middleware('admin.can:extensions.view')
            ->name('extensions.index');

        Route::get('/extensions/create', ExtensionsEdit::class)
            ->middleware('admin.can:extensions.create')
            ->name('extensions.create');

        Route::get('/extensions/create-multiple', ExtensionsBulkCreate::class)
            ->middleware('admin.can:extensions.create')
            ->name('extensions.create-multiple');

        Route::get('/extensions/{extensionId}/edit', ExtensionsEdit::class)
            ->middleware('admin.can:extensions.edit')
            ->name('extensions.edit');
    });
