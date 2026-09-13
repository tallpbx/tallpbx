<?php

declare(strict_types=1);

use App\Http\Controllers\Api\XmlHandlerController;
use App\Http\Middleware\LogXmlHandlerTiming;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| API routes for the TallPBX platform, including the FreeSWITCH
| mod_xml_curl XML Handler endpoint at /api/v1/xml-handler.
|
*/

// ─── FreeSWITCH XML Handler (mod_xml_curl) ──────────────────────────────────
// FreeSWITCH's mod_xml_curl module sends HTTP requests to this endpoint
// to retrieve dynamic directory, dialplan, and configuration XML.
// This is the primary integration point between the application and
// the FreeSWITCH telephony engine.
Route::match(['get', 'post'], '/v1/xml-handler', [XmlHandlerController::class, 'handle'])
    ->middleware(LogXmlHandlerTiming::class)
    ->name('api.xml-handler');
