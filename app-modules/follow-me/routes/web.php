<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\FollowMe\Livewire\FollowMeEdit;
use Modules\FollowMe\Livewire\FollowMeList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/follow-me', FollowMeList::class)
            ->middleware('admin.can:follow-me.view')
            ->name('follow-me.index');

        Route::get('/follow-me/create', FollowMeEdit::class)
            ->middleware('admin.can:follow-me.create')
            ->name('follow-me.create');

        Route::get('/follow-me/{followMeId}/edit', FollowMeEdit::class)
            ->middleware('admin.can:follow-me.edit')
            ->name('follow-me.edit');
    });
