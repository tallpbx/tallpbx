<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Security\Livewire\SecurityManager;

/*
|--------------------------------------------------------------------------
| Web Routes for Security Module
|--------------------------------------------------------------------------
|
| Registers the unified Security Center route in the admin panel.
| Access requires session authentication ('web', 'auth.panel'), rate limiting,
| and permission to view the security module ('admin.can:security.view').
|
*/

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function (): void {
    Route::get('/security', SecurityManager::class)
        ->middleware('admin.can:security.view')
        ->name('security.index');
});
