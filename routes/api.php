<?php

declare(strict_types=1);

use App\Http\Controllers\Api\CdrIngestionController;
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
    ->middleware([LogXmlHandlerTiming::class, 'throttle:xml-handler'])
    ->name('api.xml-handler');

// ─── FreeSWITCH CDR Ingestion (mod_json_cdr) ────────────────────────────
// mod_json_cdr posts one raw JSON document per call leg. The controller only
// verifies the shared token and appends to the Redis stream on database 3,
// so the answer returns before any database work happens. A 503 response is
// the failover contract: FreeSWITCH retries, then spools the record to disk
// for the drain command.
Route::post('/v1/cdr', [CdrIngestionController::class, 'store'])
    ->middleware('throttle:cdr-ingestion')
    ->name('api.v1.cdr.store');
