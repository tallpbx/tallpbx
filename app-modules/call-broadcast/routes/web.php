<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CallBroadcast\Livewire\BroadcastCreate;
use Modules\CallBroadcast\Livewire\BroadcastList;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/call-broadcast', BroadcastList::class)->middleware('admin.can:call-broadcast.view')->name('call-broadcast.index');
    Route::get('/call-broadcast/create', BroadcastCreate::class)->middleware('admin.can:call-broadcast.create')->name('call-broadcast.create');
});
