<?php

declare(strict_types=1);

use App\Support\XmlHandlerFastBootstrap;
use Illuminate\Http\Request;

/*
 * Fast entry point for the FreeSWITCH XML handler.
 *
 * nginx points the /api/v1/xml-handler location at this file (see
 * scripts/resources/nginx.sh), because PHP executes only /index.php on this
 * appliance by default. The handler itself registers its route at whatever
 * path the request arrived on, so no URL rewriting is needed.
 *
 * Failure here must never cost more than the speed it was meant to save, so
 * any error degrades to the full application rather than failing every
 * registration and call.
 */

if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

require __DIR__.'/../vendor/autoload.php';

try {
    $response = XmlHandlerFastBootstrap::handle(Request::createFromGlobals());
    $response->send();
} catch (Throwable $exception) {
    // Report the failure, then serve the request the normal way. index.php
    // starts from a clean container because the fast path restores the
    // previous one before rethrowing.
    error_log('XML handler fast path failed, falling back: '.$exception->getMessage());

    require __DIR__.'/index.php';
}
