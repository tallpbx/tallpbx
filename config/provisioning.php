<?php

declare(strict_types=1);

/**
 * Public device provisioning endpoint security.
 *
 * Mirrors the FusionPBX Provision category in Default Settings:
 * auto-provisioning is disabled by default, and when enabled it can
 * be protected with HTTP Basic authentication and/or a CIDR allowlist.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    |
    | When false (the default), /provision/{mac} behaves as if it does
    | not exist (404). Set to true only after auth/CIDR are configured.
    |
    */
    'enabled' => env('PROVISIONING_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | HTTP Basic Authentication
    |--------------------------------------------------------------------------
    |
    | Each non-empty value is a required credential factor: when a value is
    | set, requests must present matching Basic credentials for it. A blank
    | (empty) value is treated as unset, so blanking a factor disables it.
    |
    */
    'http_auth_username' => env('PROVISIONING_HTTP_AUTH_USERNAME'),
    'http_auth_password' => env('PROVISIONING_HTTP_AUTH_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | CIDR Allowlist
    |--------------------------------------------------------------------------
    |
    | Optional comma-separated IPv4 ranges, e.g. "10.0.0.0/8,192.168.1.0/24".
    |
    */
    'cidr' => env('PROVISIONING_CIDR'),
];
