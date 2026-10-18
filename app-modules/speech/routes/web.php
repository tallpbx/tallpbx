<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Speech\Livewire\SpeechEdit;
use Modules\Speech\Livewire\SpeechList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/speech', SpeechList::class)
            ->middleware('admin.can:speech.view')
            ->name('speech.index');

        Route::get('/speech/create', SpeechEdit::class)
            ->middleware('admin.can:speech.create')
            ->name('speech.create');

        Route::get('/speech/{configId}/edit', SpeechEdit::class)
            ->middleware('admin.can:speech.edit')
            ->name('speech.edit');
    });
