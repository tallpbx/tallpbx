<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\RingGroups\Livewire\RingGroupsEdit;
use Modules\RingGroups\Livewire\RingGroupsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/ring-groups', RingGroupsList::class)
            ->middleware('admin.can:ring-groups.view')
            ->name('ring-groups.index');

        Route::get('/ring-groups/create', RingGroupsEdit::class)
            ->middleware('admin.can:ring-groups.create')
            ->name('ring-groups.create');

        Route::get('/ring-groups/{ringGroupId}/edit', RingGroupsEdit::class)
            ->middleware('admin.can:ring-groups.edit')
            ->name('ring-groups.edit');
    });
