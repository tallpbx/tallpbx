<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\ClickToCall\Livewire\ClickToCallForm;

Route::prefix('panel')->name('panel.')->middleware(['web', 'auth.panel', 'throttle:60,1'])->group(function () {
    Route::get('/click-to-call', ClickToCallForm::class)->middleware('admin.can:click-to-call.view')->name('click-to-call.index');
});
