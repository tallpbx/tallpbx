<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\NumberTranslations\Livewire\NumberTranslationsEdit;
use Modules\NumberTranslations\Livewire\NumberTranslationsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/number-translations', NumberTranslationsList::class)
            ->middleware('admin.can:number-translations.view')
            ->name('number-translations.index');

        Route::get('/number-translations/create', NumberTranslationsEdit::class)
            ->middleware('admin.can:number-translations.create')
            ->name('number-translations.create');

        Route::get('/number-translations/{translationId}/edit', NumberTranslationsEdit::class)
            ->middleware('admin.can:number-translations.edit')
            ->name('number-translations.edit');
    });
