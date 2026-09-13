<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\DialplanTools\Livewire\DialplanTester;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/dialplan-tools', DialplanTester::class)->middleware('admin.can:dialplan-tools.view')->name('dialplan-tools.index');
});
