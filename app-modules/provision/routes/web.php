<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Provision\Http\Controllers\ProvisionController;
use Modules\Provision\Http\Middleware\ProvisioningAccess;
use Modules\Provision\Livewire\TemplateEdit;
use Modules\Provision\Livewire\TemplateList;

// Admin routes (authenticated)
Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/provision/templates', TemplateList::class)->middleware('admin.can:provision.view')->name('provision.templates.index');
    Route::get('/provision/templates/create', TemplateEdit::class)->middleware('admin.can:provision.create')->name('provision.templates.create');
    Route::get('/provision/templates/{templateId}/edit', TemplateEdit::class)->middleware('admin.can:provision.edit')->name('provision.templates.edit');
});

// Public provisioning endpoint (phones access directly — gated by
// ProvisioningAccess per the FusionPBX model: switch, auth, CIDR).
// The gate runs before the throttle so unauthenticated probes never
// consume the per-IP budget shared by phones behind the same NAT.
Route::get('/provision/{mac}', ProvisionController::class)
    ->middleware([ProvisioningAccess::class, 'throttle:60,1'])
    ->name('provision.serve');
