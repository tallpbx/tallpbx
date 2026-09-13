<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\OutboundRoutes\Livewire\OutboundRoutesEdit;
use Modules\OutboundRoutes\Livewire\OutboundRoutesList;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/outbound-routes', OutboundRoutesList::class)->name('outbound-routes.index');
    Route::get('/outbound-routes/create', OutboundRoutesEdit::class)->middleware('admin.can:outbound-routes.create')->name('outbound-routes.create');
    Route::get('/outbound-routes/{routeId}/edit', OutboundRoutesEdit::class)->middleware('admin.can:outbound-routes.edit')->name('outbound-routes.edit');
});
