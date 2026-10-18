<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Acl\Livewire\AclEdit;
use Modules\Acl\Livewire\AclList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/acl', AclList::class)
            ->middleware('admin.can:acl.view')
            ->name('acl.index');

        Route::get('/acl/create', AclEdit::class)
            ->middleware('admin.can:acl.create')
            ->name('acl.create');

        Route::get('/acl/{ruleId}/edit', AclEdit::class)
            ->middleware('admin.can:acl.edit')
            ->name('acl.edit');
    });
