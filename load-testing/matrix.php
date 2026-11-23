<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Load-Test Refresh Matrix
    |--------------------------------------------------------------------------
    |
    | Single declarative source for the two-stage refresh series documented in
    | load-testing/README.md and docs/load-testing-results.md. The runner
    | (load-testing/scripts/load-test-matrix.sh) and its tests read every tier,
    | serving variant, load profile, cache policy and SIPp scenario from here,
    | so a cell can never be skipped by ad-hoc shell logic.
    |
    | Active runtime serving modes:
    |   - v-fast-default: Shipped default appliance runtime mode (dynamic PHP-FPM
    |     pool sized to tier RAM + fast XML controller).
    |   - v-octane: Opt-in resident RoadRunner worker mode on 127.0.0.1:8090.
    |
    | Comparative benchmarking modes:
    |   - v-fast, v-stock-static, v-stock-dynamic: Static pool and baseline profiles
    |     retained for sweep benchmarking and ablation comparisons.
    |
    | Compare both active serving modes on shared and dedicated hardware.
    */

    /*
    |--------------------------------------------------------------------------
    | Hardware Tiers
    |--------------------------------------------------------------------------
    |
    | stage:        a = shared CPU (Stage A), b = dedicated CPU (Stage B).
    | vcpu/ram_mb:  the provisioned size of the cloud instance.
    | cpu:          whether the hypervisor shares the physical cores.
    | swap_mb:      swap configured by the installer before tests start.
    | fpm:          canonical (sweep) static PHP-FPM pool mode; fpm_children is its cap.
    | fpm_alt:      the shipped installer dynamic pool default for that tier's RAM,
    |               measured by the v-fast-default variant to test the real appliance
    |               deployment. Always dynamic; fpm_alt_children is the installer's
    |               RAM-derived ceiling (3 below 1800 MB, 6 below 3500 MB, 12 above).
    */

    'tiers' => [
        'h1-1c1g' => [
            'stage' => 'a',
            'vcpu' => 1,
            'ram_mb' => 1024,
            'cpu' => 'shared',
            'swap_mb' => 2048,
            'fpm' => 'static',
            'fpm_children' => 5,
            'fpm_alt' => 'dynamic',
            'fpm_alt_children' => 3,
            'cps_ladder' => [2, 4, 5, 6, 8],
        ],
        'h1-1c2g' => [
            'stage' => 'a',
            'vcpu' => 1,
            'ram_mb' => 2048,
            'cpu' => 'shared',
            'swap_mb' => 2048,
            'fpm' => 'static',
            'fpm_children' => 6,
            'fpm_alt' => 'dynamic',
            'fpm_alt_children' => 6,
            'cps_ladder' => [2, 4, 5, 6, 8],
        ],
        'h2-2c2g' => [
            'stage' => 'a',
            'vcpu' => 2,
            'ram_mb' => 2048,
            'cpu' => 'shared',
            'swap_mb' => 2048,
            'fpm' => 'static',
            'fpm_children' => 6,
            'fpm_alt' => 'dynamic',
            'fpm_alt_children' => 6,
            'cps_ladder' => [5, 8, 10, 12, 15, 20],
        ],
        'h4-4c8g' => [
            'stage' => 'a',
            'vcpu' => 4,
            'ram_mb' => 8192,
            'cpu' => 'shared',
            'swap_mb' => 2048,
            'fpm' => 'static',
            'fpm_children' => 12,
            'fpm_alt' => 'dynamic',
            'fpm_alt_children' => 12,
            'cps_ladder' => [5, 10, 15, 20, 25, 30, 40],
        ],
        'h2d-2c8g' => [
            'stage' => 'b',
            'vcpu' => 2,
            'ram_mb' => 8192,
            'cpu' => 'dedicated',
            'swap_mb' => 2048,
            'fpm' => 'static',
            'fpm_children' => 12,
            'fpm_alt' => 'dynamic',
            'fpm_alt_children' => 12,
            'cps_ladder' => [5, 10, 15, 20, 25, 30],
        ],
        'h4d-4c16g' => [
            'stage' => 'b',
            'vcpu' => 4,
            'ram_mb' => 16384,
            'cpu' => 'dedicated',
            'swap_mb' => 2048,
            'fpm' => 'static',
            'fpm_children' => 24,
            'fpm_alt' => 'dynamic',
            'fpm_alt_children' => 12,
            'cps_ladder' => [10, 15, 20, 25, 30, 40, 50],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | XML-Serving Variants
    |--------------------------------------------------------------------------
    |
    | Defines the XML runtime serving modes.
    |
    | Runtime serving modes:
    |   - v-fast-default: Shipped default appliance runtime mode (dynamic PHP-FPM
    |     pool sized to tier RAM + fast XML controller).
    |   - v-octane: Opt-in resident RoadRunner worker mode on 127.0.0.1:8090.
    |   - v-fast, v-stock-static, v-stock-dynamic: Static pool and baseline profiles
    |     retained for sweep benchmarking comparisons.
    |
    | requires: the switch settings a cell must apply before it runs. The
    | runner aborts the cell when one of them cannot be asserted.
    |   fpm:<mode>            PHP-FPM pool mode
    |   xml-path:<stock|fast> nginx front controller for /api/v1/xml-handler
    |   octane:<only|off>     XML service under test on the benchmark host
    */

    'variants' => [
        'v-stock-dynamic' => [
            'label' => 'Stock PHP-FPM with the Debian dynamic pool',
            'requires' => ['fpm:dynamic', 'xml-path:stock', 'octane:off'],
        ],
        'v-stock-static' => [
            'label' => 'Stock PHP-FPM with a static pool',
            'requires' => ['fpm:static', 'xml-path:stock', 'octane:off'],
        ],
        'v-fast' => [
            'label' => 'Fast front controller through PHP-FPM',
            'requires' => ['fpm:static', 'xml-path:fast', 'octane:off'],
        ],
        // The shipped default appliance runtime mode: dynamic pool sized to
        // available RAM (via tier fpm_alt_children) + fast XML controller.
        'v-fast-default' => [
            'label' => 'Shipped default: dynamic PHP-FPM pool, fast XML controller',
            'requires' => ['fpm:dynamic', 'xml-path:fast', 'octane:off'],
        ],
        // Opt-in resident RoadRunner worker mode for low-latency XML serving.
        'v-octane' => [
            'label' => 'Persistent RoadRunner worker',
            'requires' => ['fpm:dynamic', 'xml-path:fast', 'octane:only'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | XML Load Profiles
    |--------------------------------------------------------------------------
    |
    | requests x concurrency, matching the notation used in the results
    | document (for example 100x5 = 100 requests at 5 in flight).
    */

    'xml_profiles' => [
        '25x1' => ['label' => 'Warm-up', 'requests' => 25, 'concurrency' => 1],
        '100x5' => ['label' => 'Moderate burst', 'requests' => 100, 'concurrency' => 5],
        '1000x25' => ['label' => 'Sustained ceiling', 'requests' => 1000, 'concurrency' => 25],
    ],

    // Sections of the XML handler each variant is measured against.
    'xml_sections' => ['dialplan', 'directory', 'mixed'],

    // Query scenarios understood by pbx:load-test:dialplan.
    'xml_scenarios' => ['mixed', 'cache-hit'],

    /*
    |--------------------------------------------------------------------------
    | Cache-Policy Profiles
    |--------------------------------------------------------------------------
    |
    | Values are the FS_XML_HANDLER_*_CACHE_TTL overrides (seconds) applied to
    | .env before the cell runs. 'scenario' selects the request mix the runner
    | passes to pbx:load-test:dialplan.
    */

    'cache_ttl_profiles' => [
        'cold' => [
            'label' => 'Cold cache (dialplan 0s, contributor 0s)',
            'dialplan' => 0,
            'contributor' => 0,
            'directory' => 60,
            'acl' => 60,
            'scenario' => 'mixed',
        ],
        'dialplan-off' => [
            'label' => 'Dialplan cache off, contributor cache on',
            'dialplan' => 0,
            'contributor' => 60,
            'directory' => 60,
            'acl' => 60,
            'scenario' => 'mixed',
        ],
        'production-60' => [
            'label' => 'Production default (60s / 60s)',
            'dialplan' => 60,
            'contributor' => 60,
            'directory' => 60,
            'acl' => 60,
            'scenario' => 'mixed',
        ],
        'cache-hit-60' => [
            'label' => 'Cache-hit ceiling (one destination)',
            'dialplan' => 60,
            'contributor' => 60,
            'directory' => 60,
            'acl' => 60,
            'scenario' => 'cache-hit',
        ],
        'extended-30' => [
            'label' => 'Extended retention (30s / 30s)',
            'dialplan' => 30,
            'contributor' => 30,
            'directory' => 30,
            'acl' => 30,
            'scenario' => 'mixed',
        ],
        'uncached-db' => [
            'label' => 'No XML caching anywhere (all TTL 0s)',
            'dialplan' => 0,
            'contributor' => 0,
            'directory' => 0,
            'acl' => 0,
            'scenario' => 'mixed',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Failure Ramp
    |--------------------------------------------------------------------------
    |
    | Fixed concurrency, doubling the request total until the error rate or
    | the p99 tail crosses the boundary. The runner records the last healthy
    | step and the first failing step, never an interpolated guess.
    */

    'failure_ramp' => [
        'concurrency' => 25,
        'start_requests' => 100,
        'multiplier' => 2,
        'max_error_rate_percent' => 1.0,
        'max_p99_ms' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | SIPp Feature Scenarios
    |--------------------------------------------------------------------------
    |
    | Must list every load-testing/tools/sipp/uac-*.xml plus register.xml; the config test
    | fails when this list and the shipped scenario files disagree.
    */

    'sip_scenarios' => [
        'register',
        'uac-call-block',
        'uac-call-forward',
        'uac-conference',
        'uac-emergency',
        'uac-extension',
        'uac-extension-capacity',
        'uac-follow-me',
        'uac-media-client-hangup',
        'uac-media-server-hangup',
        'uac-outbound',
        'uac-ring-group',
        'uac-time-condition',
        'uac-voicemail',
    ],

    // Offered call attempts per second for the capacity ladder.
    'cps_ladder' => [2, 3, 5, 8, 10, 15, 20, 25, 30, 40],

    /*
    |--------------------------------------------------------------------------
    | Standard Publishable Comparison Contract
    |--------------------------------------------------------------------------
    |
    | Single uniform test matrix executed across every required tier on the
    | same build SHA so docs/load-testing-results.md is generated from one
    | consistent series instead of cobbled together across dates and pools.
    */

    'standard_comparison' => [
        'tiers' => ['h1-1c1g', 'h2-2c2g', 'h4-4c8g'],
        'variants' => ['v-fast-default', 'v-octane'],
        'xml_profiles' => ['100x5', '1000x25'],
        'xml_section' => 'mixed',
        'xml_ttl' => 'production-60',
        'xml_repeats' => 3,
        'sip_extensions' => 30,
        'sip_duration_seconds' => 60,
        'include_extended_validation' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Single-Knob Ablations (Stage A tiers only)
    |--------------------------------------------------------------------------
    |
    | Each entry changes exactly one knob against the tier's best variant and
    | doubles as a remediation test: the measured delta feeds the register.
    */

    'ablations' => [
        'fs-loglevel' => [
            'label' => 'FreeSWITCH log level',
            'values' => ['debug', 'info', 'notice'],
            'question' => 'CPU cost of verbose telephony logging',
        ],
        'laravel-loglevel' => [
            'label' => 'Laravel log level',
            'values' => ['debug', 'info', 'notice'],
            'question' => 'CPU and IO cost of application logging',
        ],
        'fs-db' => [
            'label' => 'FreeSWITCH runtime database',
            'values' => ['sqlite', 'mariadb'],
            'question' => 'SQLite lock contention vs. MariaDB (mod_mariadb) under call and registration load',
        ],
        'esl-subscriptions' => [
            'label' => 'ESL event subscriptions',
            'values' => ['essential', 'full'],
            'question' => 'CPU recovered by event filtering',
        ],
        'broadcast-throttle' => [
            'label' => 'Reverb broadcast debounce window',
            'values' => ['500', '0'],
            'question' => 'Broadcast cost the debounce hides during call bursts',
        ],
        'reverb' => [
            'label' => 'Reverb broadcasting',
            'values' => ['on', 'off'],
            'question' => 'WebSocket broadcast cost during calls',
        ],
        'opcache-jit' => [
            'label' => 'OPcache JIT',
            'values' => ['off', 'on'],
            'question' => 'Whether JIT helps XML assembly',
        ],
        'redis-aof' => [
            'label' => 'Redis append-only file',
            'values' => ['off', 'everysec'],
            'question' => 'Durability cost for CDR safety',
        ],
    ],
];
