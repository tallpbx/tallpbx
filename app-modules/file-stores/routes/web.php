<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\FileStores\Http\Controllers\MediaStreamController;
use Modules\FileStores\Http\Middleware\RequireSystemAdmin;
use Modules\FileStores\Livewire\FileStoresEdit;
use Modules\FileStores\Livewire\FileStoresList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1', RequireSystemAdmin::class])
    ->group(function (): void {
        Route::get('/file-stores', FileStoresList::class)
            ->middleware('admin.can:file-stores.view')
            ->name('file-stores.index');

        Route::get('/file-stores/create', FileStoresEdit::class)
            ->middleware('admin.can:file-stores.create')
            ->name('file-stores.create');

        Route::get('/file-stores/{fileStoreId}/edit', FileStoresEdit::class)
            ->middleware('admin.can:file-stores.update')
            ->name('file-stores.edit');
    });

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/media-assets/{mediaAssetId}/stream', [MediaStreamController::class, 'stream'])
            ->name('media-assets.stream');

        Route::get('/media-assets/{mediaAssetId}/download', [MediaStreamController::class, 'download'])
            ->name('media-assets.download');
    });
