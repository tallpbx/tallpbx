<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Transcribe\Livewire\TranscriptionList;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/transcribe', TranscriptionList::class)->middleware('admin.can:transcribe.view')->name('transcribe.index');
});
