<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\EmailQueue\Livewire\EmailQueueList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/email-queue', EmailQueueList::class)
            ->middleware('admin.can:email-queue.view')
            ->name('email-queue.index');
    });
