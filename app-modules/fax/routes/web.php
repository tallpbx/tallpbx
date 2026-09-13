<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Fax\Livewire\FaxInbox;
use Modules\Fax\Livewire\FaxSend;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/fax', FaxInbox::class)->middleware('admin.can:fax.view')->name('fax.index');
    Route::get('/fax/send', FaxSend::class)->middleware('admin.can:fax.send')->name('fax.send');
});
