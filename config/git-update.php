<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Git Update Pipeline
    |--------------------------------------------------------------------------
    |
    | step_timeout_seconds: per-step timeout applied to every pipeline
    | command (composer install, npm build, artisan, git). A hung step is
    | killed by GNU timeout (exit 124) and fails the pipeline, which
    | triggers the rollback path instead of blocking forever.
    */

    'step_timeout_seconds' => (int) env('GIT_UPDATE_STEP_TIMEOUT_SECONDS', 600),

    /*
     * Runtime artefacts (lock, status file, progress log). The defaults live
     * under storage/framework; tests redirect them so parallel processes never
     * contend for one lock file.
     */
    'runtime_path' => env('GIT_UPDATE_RUNTIME_PATH', storage_path('framework')),
];
