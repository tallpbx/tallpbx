<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\SmtpConnector\Livewire\SmtpConnectorEdit;

Route::middleware(['web', 'auth.panel', 'throttle:30,1'])
    ->prefix('panel')
    ->name('panel.')
    ->group(function (): void {
        Route::get('/smtp-connector', SmtpConnectorEdit::class)
            ->middleware('admin.can:smtp-connector.view')
            ->name('smtp-connector.edit');

        // OAuth 2.0 callback — the provider redirects the browser here
        // after the admin grants consent. The SmtpConnectorEdit component
        // detects the ?code= and ?state= query params in mount().
        Route::get('/smtp-connector/oauth-callback', SmtpConnectorEdit::class)
            ->middleware('admin.can:smtp-connector.view')
            ->name('smtp-connector.oauth-callback');
    });
