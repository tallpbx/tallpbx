<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\FeatureCodes\Livewire\FeatureCodesEdit;
use Modules\FeatureCodes\Livewire\FeatureCodesList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/feature-codes', FeatureCodesList::class)
            ->middleware('admin.can:feature-codes.view')
            ->name('feature-codes.index');

        Route::get('/feature-codes/create', FeatureCodesEdit::class)
            ->middleware('admin.can:feature-codes.create')
            ->name('feature-codes.create');

        Route::get('/feature-codes/{codeId}/edit', FeatureCodesEdit::class)
            ->middleware('admin.can:feature-codes.edit')
            ->name('feature-codes.edit');
    });
