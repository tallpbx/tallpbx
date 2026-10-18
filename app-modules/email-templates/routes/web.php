<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\EmailTemplates\Livewire\EmailTemplatesEdit;
use Modules\EmailTemplates\Livewire\EmailTemplatesList;

Route::prefix('panel')
    ->name('panel.')
    ->middleware(['web', 'auth.panel', 'throttle:60,1'])
    ->group(function (): void {
        Route::get('/email-templates', EmailTemplatesList::class)
            ->middleware('admin.can:email-templates.view')
            ->name('email-templates.index');

        Route::get('/email-templates/create', EmailTemplatesEdit::class)
            ->middleware('admin.can:email-templates.create')
            ->name('email-templates.create');

        Route::get('/email-templates/{templateId}/edit', EmailTemplatesEdit::class)
            ->middleware('admin.can:email-templates.edit')
            ->name('email-templates.edit');
    });
