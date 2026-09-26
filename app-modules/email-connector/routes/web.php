<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\EmailConnector\Livewire\EmailConnectorEdit;

Route::middleware(['web', 'auth.panel', 'throttle:30,1'])
    ->prefix('panel')
    ->name('panel.')
    ->group(function (): void {
        Route::get('/email-connector', EmailConnectorEdit::class)
            ->middleware('admin.can:email-connector.view')
            ->name('email-connector.edit');

        // OAuth 2.0 callback — the provider redirects the browser here
        // after the admin grants consent. The EmailConnectorEdit component
        // detects the ?code= and ?state= query params in mount().
        Route::get('/email-connector/oauth-callback', EmailConnectorEdit::class)
            ->middleware('admin.can:email-connector.view')
            ->name('email-connector.oauth-callback');
    });
