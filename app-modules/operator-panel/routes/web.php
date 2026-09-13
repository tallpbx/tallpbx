<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\OperatorPanel\Livewire\OperatorPanelIndex;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/operator-panel', OperatorPanelIndex::class)->middleware('admin.can:operator-panel.view')->name('operator-panel.index');
});
