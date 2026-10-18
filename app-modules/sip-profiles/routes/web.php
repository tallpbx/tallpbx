<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SipProfiles\Livewire\SipProfilesEdit;
use Modules\SipProfiles\Livewire\SipProfilesList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/sip-profiles', SipProfilesList::class)
            ->middleware('admin.can:sip-profiles.view')
            ->name('sip-profiles.index');

        Route::get('/sip-profiles/create', SipProfilesEdit::class)
            ->middleware('admin.can:sip-profiles.create')
            ->name('sip-profiles.create');

        Route::get('/sip-profiles/{profileId}/edit', SipProfilesEdit::class)
            ->middleware('admin.can:sip-profiles.edit')
            ->name('sip-profiles.edit');
    });
