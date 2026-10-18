<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ExtensionSettings\Livewire\ExtensionSettingsEdit;
use Modules\ExtensionSettings\Livewire\ExtensionSettingsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/extension-settings', ExtensionSettingsList::class)
            ->middleware('admin.can:extension-settings.view')
            ->name('extension-settings.index');

        Route::get('/extension-settings/create', ExtensionSettingsEdit::class)
            ->middleware('admin.can:extension-settings.create')
            ->name('extension-settings.create');

        Route::get('/extension-settings/{settingId}/edit', ExtensionSettingsEdit::class)
            ->middleware('admin.can:extension-settings.edit')
            ->name('extension-settings.edit');
    });
