<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\XmlCdr\Livewire\CdrDetail;
use Modules\XmlCdr\Livewire\CdrList;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/cdr', CdrList::class)->middleware('admin.can:cdr.view')->name('cdr.index');
    Route::get('/cdr/{cdr}', CdrDetail::class)->middleware('admin.can:cdr.view')->name('cdr.detail');
});
