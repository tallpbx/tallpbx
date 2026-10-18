<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Voicemails\Livewire\VoicemailsEdit;
use Modules\Voicemails\Livewire\VoicemailsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/voicemails', VoicemailsList::class)
            ->middleware('admin.can:voicemails.view')
            ->name('voicemails.index');

        Route::get('/voicemails/create', VoicemailsEdit::class)
            ->middleware('admin.can:voicemails.create')
            ->name('voicemails.create');

        Route::get('/voicemails/{voicemailUuid}/edit', VoicemailsEdit::class)
            ->middleware('admin.can:voicemails.edit')
            ->name('voicemails.edit');
    });
