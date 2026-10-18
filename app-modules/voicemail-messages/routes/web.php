<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\VoicemailMessages\Livewire\VoicemailMessagesList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/voicemail-messages', VoicemailMessagesList::class)
            ->middleware('admin.can:voicemail-messages.view')
            ->name('voicemail-messages.index');
    });
