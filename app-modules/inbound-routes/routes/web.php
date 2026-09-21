<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\InboundRoutes\Livewire\InboundRoutesEdit;
use Modules\InboundRoutes\Livewire\InboundRoutesList;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/inbound-routes', InboundRoutesList::class)->middleware('admin.can:inbound-routes.view')->name('inbound-routes.index');
    Route::get('/inbound-routes/create', InboundRoutesEdit::class)->middleware('admin.can:inbound-routes.create')->name('inbound-routes.create');
    Route::get('/inbound-routes/{routeId}/edit', InboundRoutesEdit::class)->middleware('admin.can:inbound-routes.edit')->name('inbound-routes.edit');
});
