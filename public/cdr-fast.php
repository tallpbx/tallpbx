<?php

declare(strict_types=1);

use App\Support\CdrFastBootstrap;
use Illuminate\Http\Request;

/*
 * Fast entry point for CDR ingestion.
 *
 * nginx points the /api/v1/cdr location at this file (see
 * scripts/resources/nginx.sh and letsencrypt.sh), because PHP executes only
 * /index.php on this appliance by default. mod_json_cdr keeps posting to the
 * same URL, so no FreeSWITCH change is involved.
 *
 * Failure here must never cost a record, only the speed the entry was meant
 * to save: a broken fast path falls back to the full application, and if that
 * fails too mod_json_cdr retries and then spools to disk for the drain
 * command. That is why there is no error response of our own.
 */

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

require __DIR__.'/../vendor/autoload.php';

try {
    $response = CdrFastBootstrap::handle(Request::createFromGlobals());
    $response->send();
} catch (Throwable $exception) {
    // Report the failure, then serve the request the normal way. index.php
    // starts from a clean container because the fast path restores the
    // previous one before rethrowing.
    error_log('CDR fast path failed, falling back: '.$exception->getMessage());

    require __DIR__.'/index.php';
}
