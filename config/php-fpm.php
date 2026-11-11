<?php

declare(strict_types=1);

/**
 * PHP-FPM worker pool ceiling.
 *
 * The installer sizes the pool from detected physical memory (3 workers on
 * 1 GB, 6 on 2 GB, 12 on 4 GB+) and `appliance:sync-host` re-applies the same
 * shape after a snapshot restore. Setting this value pins a host to a
 * published tier when its hardware does not match the machine a benchmark was
 * taken on; leaving it empty keeps the memory-derived ceiling.
 */
return [
    'max_children' => env('PHP_FPM_MAX_CHILDREN'),
];
