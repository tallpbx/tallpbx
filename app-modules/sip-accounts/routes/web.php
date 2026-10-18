<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SipAccounts\Livewire\SipAccountsEdit;
use Modules\SipAccounts\Livewire\SipAccountsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/sip-accounts', SipAccountsList::class)
            ->middleware('admin.can:sip-accounts.view')
            ->name('sip-accounts.index');

        Route::get('/sip-accounts/create', SipAccountsEdit::class)
            ->middleware('admin.can:sip-accounts.create')
            ->name('sip-accounts.create');

        Route::get('/sip-accounts/{accountId}/edit', SipAccountsEdit::class)
            ->middleware('admin.can:sip-accounts.edit')
            ->name('sip-accounts.edit');
    });
