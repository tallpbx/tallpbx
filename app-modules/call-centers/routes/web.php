<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\CallCenters\Livewire\QueueEdit;
use Modules\CallCenters\Livewire\QueueList;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/call-centers/queues', QueueList::class)->middleware('admin.can:call-centers.view')->name('call-centers.queues.index');
    Route::get('/call-centers/queues/create', QueueEdit::class)->middleware('admin.can:call-centers.create')->name('call-centers.queues.create');
    Route::get('/call-centers/queues/{queueId}/edit', QueueEdit::class)->middleware('admin.can:call-centers.edit')->name('call-centers.queues.edit');
});
