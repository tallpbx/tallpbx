<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Certificates\Livewire\CertificateManager;

/*
|--------------------------------------------------------------------------
| Web Routes for Certificates Module
|--------------------------------------------------------------------------
|
| Registers the unified Certificate Manager route in the admin panel.
| Access requires session authentication ('web', 'auth.panel'), rate limiting,
| and permission to view the certificates module ('admin.can:certificates.view').
|
*/

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function (): void {
    Route::get('/certificates', CertificateManager::class)
        ->middleware('admin.can:certificates.view')
        ->name('certificates.index');
});
