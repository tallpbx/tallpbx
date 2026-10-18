<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\TenantLimits\Livewire\TenantLimitsEdit;
use Modules\TenantLimits\Livewire\TenantLimitsList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/tenant-limits', TenantLimitsList::class)
            ->middleware('admin.can:tenant-limits.view')
            ->name('tenant-limits.index');

        Route::get('/tenant-limits/create', TenantLimitsEdit::class)
            ->middleware('admin.can:tenant-limits.create')
            ->name('tenant-limits.create');

        Route::get('/tenant-limits/{limitId}/edit', TenantLimitsEdit::class)
            ->middleware('admin.can:tenant-limits.edit')
            ->name('tenant-limits.edit');
    });
