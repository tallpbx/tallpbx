<?php

declare(strict_types=1);

use App\Support\XmlHandlerFastBootstrap;
use Illuminate\Http\Request;

/*
 * Fast entry point for the FreeSWITCH XML handler.
 *
 * PHP executes only /index.php on this appliance (there is no generic `.php`
 * handler in the nginx configuration), so this file is reachable only through
 * an explicit nginx location that sets SCRIPT_FILENAME to it.
 *
 * The timing middleware measures from LARAVEL_START, which /index.php defines;
 * it is defined here too so measured request times stay comparable.
 */

define('LARAVEL_START', microtime(true));

require __DIR__.'/../vendor/autoload.php';

$response = XmlHandlerFastBootstrap::handle(Request::createFromGlobals());

$response->send();
