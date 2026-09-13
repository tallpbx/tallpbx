<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Managed media roots
    |--------------------------------------------------------------------------
    |
    | Task 3 provisions these paths for the PHP and FreeSWITCH service users.
    | Keeping them in configuration lets the service be tested with isolated
    | roots and avoids reading environment values outside configuration.
    |
    */
    'store_root' => env('TALLPBX_MEDIA_ROOT', '/var/lib/tallpbx/media').'/store',
    'spool_root' => env('TALLPBX_MEDIA_ROOT', '/var/lib/tallpbx/media').'/spool',
];
