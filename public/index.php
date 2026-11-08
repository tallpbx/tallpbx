<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

// Guarded so the fast XML handler can fall back to this file after its own
// bootstrap has already started the clock; redeclaring the constant warns.
if (! defined('LARAVEL_START')) {
    define('LARAVEL_START', microtime(true));
}

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = __DIR__.'/../storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require __DIR__.'/../vendor/autoload.php';

// Bootstrap Laravel and handle the request...
// `require`, not `require_once`: the fast front controllers (public/cdr-fast.php
// and public/xml-handler-fast.php) reach this file as their fail-open fallback
// after they already required bootstrap/app.php in the same process, and
// require_once would hand back `true` instead of the application. Re-evaluating
// bootstrap/app.php builds a fresh application, which is what the fallback needs.
/** @var Application $app */
$app = require __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());
