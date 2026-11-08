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
    // One appended line per fallback in a dedicated file. The second tier is
    // silent by design, so without this counter an operator cannot tell a
    // healthy fast entry from one that has fallen back for days.
    @file_put_contents(
        __DIR__.'/../storage/logs/cdr-fast-fallback.log',
        sprintf("%s fast-entry fell back: %s: %s\n", gmdate('c'), $exception::class, $exception->getMessage()),
        FILE_APPEND | LOCK_EX
    );

    // Report the failure, then serve the request the normal way. index.php
    // starts from a clean container because the fast path restores the
    // previous one before rethrowing.
    error_log('CDR fast path failed, falling back: '.$exception->getMessage());

    require __DIR__.'/index.php';
}
