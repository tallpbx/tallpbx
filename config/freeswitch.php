<?php

declare(strict_types=1);

/**
 * FreeSWITCH Integration Configuration
 *
 * Manages the Event Socket Layer (ESL) connection settings,
 * the mod_xml_curl XML Handler API configuration, and the
 * SIP server hostname used for device provisioning templates.
 */
return [

    /*
    |--------------------------------------------------------------------------
    | SIP Server
    |--------------------------------------------------------------------------
    |
    | The hostname or IP address of the FreeSWITCH SIP server that
    | devices will register to. This is used by the provisioning
    | module to generate device configuration templates.
    |
    */
    'server' => env('FREESWITCH_SERVER', env('APP_URL', 'pbx.example.com')),

    /*
    |--------------------------------------------------------------------------
    | Fresh Install SIP Defaults
    |--------------------------------------------------------------------------
    |
    | The database seeder uses these values to create the first callable
    | tenant realm and demo extensions. The installer writes deployment-
    | specific values so phones can register without manual setup.
    |
    */
    'default_sip_realm' => env('FREESWITCH_DEFAULT_SIP_REALM'),
    'default_sip_password' => env('PBX_DEFAULT_SIP_PASSWORD'),

    /*
    |--------------------------------------------------------------------------
    | Installer Demo Data
    |--------------------------------------------------------------------------
    |
    | The installer sets this before database seeding. Keeping the environment
    | lookup in configuration lets the seeder behave correctly when Laravel's
    | configuration has been cached.
    |
    */
    'demo_mode' => filter_var(env('FSPBX_DEMO_MODE', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | ESL Connection
    |--------------------------------------------------------------------------
    |
    | Connection details for the FreeSWITCH Event Socket Layer.
    | The ESL socket is used to subscribe to events and execute
    | FreeSWITCH API commands from the application.
    |
    */
    'esl' => [
        'host' => env('FREESWITCH_ESL_HOST', '127.0.0.1'),
        'port' => (int) env('FREESWITCH_ESL_PORT', 8021),

        /*
        |--------------------------------------------------------------------------
        | ESL Password
        |--------------------------------------------------------------------------
        |
        | The password for the FreeSWITCH Event Socket Layer connection.
        | The default 'ClueCon' is FreeSWITCH's well-known default password.
        | In production, this MUST be changed to a strong, unique value via
        | the FREESWITCH_ESL_PASSWORD environment variable, and the matching
        | password must be set in FreeSWITCH's event_socket.conf.xml.
        |
        */
        'password' => env('FREESWITCH_ESL_PASSWORD', 'ClueCon'),

        /*
        |--------------------------------------------------------------------------
        | Reconnection
        |--------------------------------------------------------------------------
        |
        | Number of seconds to wait before attempting to reconnect
        | after the ESL connection is lost. Set to null to disable
        | automatic reconnection.
        |
        */
        'reconnect_interval' => (int) env('FREESWITCH_ESL_RECONNECT_INTERVAL', 5),

        /*
        |--------------------------------------------------------------------------
        | Connection Timeout
        |--------------------------------------------------------------------------
        |
        | Maximum time in seconds to wait for an ESL connection
        | to be established before timing out.
        |
        */
        'timeout' => (int) env('FREESWITCH_ESL_TIMEOUT', 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Event Subscriptions
    |--------------------------------------------------------------------------
    |
    | FreeSWITCH event types to subscribe to. The listener command
    | will subscribe to all events listed here. Common event types:
    |
    |   CHANNEL_CREATE, CHANNEL_DESTROY, CHANNEL_ANSWER,
    |   CHANNEL_HANGUP, CHANNEL_HANGUP_COMPLETE, CHANNEL_BRIDGE,
    |   CHANNEL_UNBRIDGE, CHANNEL_OUTGOING, CHANNEL_PROGRESS,
    |   CHANNEL_PROGRESS_MEDIA, CHANNEL_EXECUTE,
    |   CHANNEL_EXECUTE_COMPLETE, CHANNEL_STATE,
    |   CALL_UPDATE, RECORD_START, RECORD_STOP,
    |   CUSTOM, HEARTBEAT, DTMF, CODEC, BACKGROUND_JOB,
    |   SESSION_HEARTBEAT, PRESENCE_IN
    |
    */
    'subscribe' => [
        'CHANNEL_CREATE',
        'CHANNEL_DESTROY',
        'CHANNEL_ANSWER',
        'CHANNEL_HANGUP',
        'CHANNEL_HANGUP_COMPLETE',
        'CHANNEL_BRIDGE',
        'CHANNEL_UNBRIDGE',
        'CHANNEL_OUTGOING',
        'CHANNEL_PROGRESS',
        'CHANNEL_PROGRESS_MEDIA',
        'CHANNEL_EXECUTE',
        'CHANNEL_EXECUTE_COMPLETE',
        'CHANNEL_STATE',
        'CALL_UPDATE',
        'RECORD_START',
        'RECORD_STOP',
        'CUSTOM',
        'HEARTBEAT',
        'DTMF',
        'CODEC',
        'BACKGROUND_JOB',
        'SESSION_HEARTBEAT',
        'PRESENCE_IN',
        'SOFIA_EXPIRE',
        'SOFIA_REGISTER',
    ],

    /*
    |--------------------------------------------------------------------------
    | FreeSWITCH Runtime Database
    |--------------------------------------------------------------------------
    |
    | FreeSWITCH uses SQLite for its internal runtime databases by default.
    | Set the driver to mariadb, pgsql, or odbc and provide a DSN or connection
    | parts to move core, db, fifo, and other FreeSWITCH-owned tables to an
    | external database. These settings do not affect Laravel's application DB.
    |
    */
    'database' => [
        'driver' => env('FREESWITCH_DATABASE_DRIVER', 'sqlite'),
        'dsn' => env('FREESWITCH_DATABASE_DSN'),
        'odbc_dsn' => env('FREESWITCH_DATABASE_ODBC_DSN'),
        'host' => env('FREESWITCH_DATABASE_HOST', '127.0.0.1'),
        'port' => (int) env('FREESWITCH_DATABASE_PORT', 3306),
        'database' => env('FREESWITCH_DATABASE_NAME', 'freeswitch'),
        'username' => env('FREESWITCH_DATABASE_USERNAME', 'freeswitch'),
        'password' => env('FREESWITCH_DATABASE_PASSWORD', ''),
        'options' => env('FREESWITCH_DATABASE_OPTIONS', ''),
        'core_db_name' => env('FREESWITCH_CORE_DB_NAME'),
        'auto_create_schemas' => (bool) env('FREESWITCH_AUTO_CREATE_SCHEMAS', true),
        'auto_clear_sql' => env('FREESWITCH_AUTO_CLEAR_SQL'),
        'core_non_sqlite_db_required' => (bool) env('FREESWITCH_CORE_NON_SQLITE_DB_REQUIRED', false),
        'odbc_skip_autocommit_flip' => (bool) env('FREESWITCH_ODBC_SKIP_AUTOCOMMIT_FLIP', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | FreeSWITCH Core Switch Settings
    |--------------------------------------------------------------------------
    |
    | These values are served through mod_xml_curl for switch.conf.
    |
    | FreeSWITCH log levels, from most chatty to quietest:
    | - debug: detailed internal call/session state. Best while validating or
    |   diagnosing SIP behavior, but noisy during load tests.
    | - info: normal operational detail without most debug-level internals.
    | - notice: important normal call lifecycle messages. Useful for cleaner
    |   capacity comparisons because it reduces log work while preserving call
    |   progress visibility.
    | - warning: unexpected but non-fatal conditions.
    | - err: errors that should be investigated.
    | - crit: serious failures that may affect service.
    | - alert: urgent conditions requiring immediate attention.
    |
    | Keep debug as the default while the PBX is still being validated. For
    | capacity comparison runs, temporarily lower the runtime log level to
    | notice or warning to reduce log overhead.
    |
    */
    'switch' => [
        'loglevel' => env('FREESWITCH_SWITCH_LOG_LEVEL', 'debug'),
        'sessions_per_second' => (int) env('FREESWITCH_SWITCH_SESSIONS_PER_SECOND', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sofia Global Settings
    |--------------------------------------------------------------------------
    |
    | Server-level Sofia settings served through mod_xml_curl. Tenant-specific
    | SIP profile params are stored in sip_profiles.settings and rendered
    | under the profiles section of sofia.conf.
    |
    */
    'sofia' => [
        'log_level' => env('FREESWITCH_SOFIA_LOG_LEVEL', '0'),
        'auto_restart' => (bool) env('FREESWITCH_SOFIA_AUTO_RESTART', true),
        'debug_presence' => (bool) env('FREESWITCH_SOFIA_DEBUG_PRESENCE', false),
        'capture_server' => env('FREESWITCH_SOFIA_CAPTURE_SERVER', ''),
        'inbound_reg_in_new_thread' => (bool) env('FREESWITCH_SOFIA_INBOUND_REG_THREAD', true),
        'max_reg_threads' => (int) env('FREESWITCH_SOFIA_MAX_REG_THREADS', 8),
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway Default Settings
    |--------------------------------------------------------------------------
    |
    | Conservative default values for FreeSWITCH gateway parameters.
    | Applied as the lowest-priority layer when generating gateway XML.
    | Provider-specific values belong in gateway.settings, not here.
    |
    */
    'gateway_defaults' => [
        'expire-seconds' => 600,
        'retry-seconds' => 30,
        'timeout-seconds' => 10,
        'ping' => 25,
        'caller-id-in-from' => false,
        'extension-in-contact' => false,
        'dtmf-type' => 'rfc2833',
        'codec-prefs' => 'PCMU,PCMA',
        'register-transport' => 'udp',
    ],

    /*
    |--------------------------------------------------------------------------
    | XML Handler
    |--------------------------------------------------------------------------
    |
    | Configuration for the mod_xml_curl XML Handler API endpoint.
    | FreeSWITCH's mod_xml_curl module sends HTTP requests to this
    | endpoint to retrieve dynamic directory, dialplan, and
    | configuration XML.
    |
    */
    'xml_handler' => [
        /*
        |--------------------------------------------------------------------------
        | API Path
        |--------------------------------------------------------------------------
        |
        | The URI path prefix for the XML Handler API. FreeSWITCH
        | mod_xml_curl bindings should point to this path.
        |
        */
        'path' => env('FREESWITCH_XML_HANDLER_PATH', '/api/v1/xml-handler'),

        /*
        |--------------------------------------------------------------------------
        | Authentication
        |--------------------------------------------------------------------------
        |
        | Whether to require authentication for XML handler requests.
        | When enabled, FreeSWITCH must include credentials in its
        | mod_xml_curl configuration.
        |
        */
        'auth' => filter_var(env('FREESWITCH_XML_HANDLER_AUTH', true), FILTER_VALIDATE_BOOLEAN),

        /*
        |--------------------------------------------------------------------------
        | Request Debug Logging
        |--------------------------------------------------------------------------
        |
        | Whether to log successful per-request XML handler diagnostics. Keep this
        | disabled for load testing and production because FreeSWITCH may call this
        | endpoint on every directory, dialplan, and configuration lookup.
        |
        */
        'log_requests' => filter_var(env('FREESWITCH_XML_HANDLER_LOG_REQUESTS', false), FILTER_VALIDATE_BOOLEAN),
        'log_timing' => filter_var(env('FREESWITCH_XML_HANDLER_LOG_TIMING', false), FILTER_VALIDATE_BOOLEAN),

        /*
        |--------------------------------------------------------------------------
        | FreeSWITCH XML Handler Caching
        |--------------------------------------------------------------------------
        |
        | Short-lived caching avoids rebuilding identical XML responses during
        | call bursts. 'cache_ttl' acts as the master default (in seconds) for
        | all XML handler caches. Individual caches inherit this duration unless
        | an explicit granular override is defined in the environment.
        |
        */
        'cache_ttl' => (int) env('XML_CACHE_TTL', 5),
        'dialplan_cache_ttl' => (int) env('XML_CACHE_DIALPLAN_TTL', env('XML_CACHE_TTL', 5)),
        'dialplan_contributor_cache_ttl' => (int) env('XML_CACHE_CONTRIBUTOR_TTL', env('XML_CACHE_TTL', 5)),
        'directory_cache_ttl' => (int) env('XML_CACHE_DIRECTORY_TTL', env('XML_CACHE_TTL', 5)),
        'acl_cache_ttl' => (int) env('XML_CACHE_ACL_TTL', env('XML_CACHE_TTL', 5)),

        'cache_store' => env('XML_CACHE_STORE'),
        'dialplan_cache_store' => env('XML_CACHE_DIALPLAN_STORE', env('XML_CACHE_STORE')),
        'directory_cache_store' => env('XML_CACHE_DIRECTORY_STORE', env('XML_CACHE_STORE')),
        'acl_cache_store' => env('XML_CACHE_ACL_STORE', env('XML_CACHE_STORE')),
        'pin_trigger' => env('FREESWITCH_PIN_TRIGGER', '*97'),
        'routing_version_store' => env('FREESWITCH_XML_HANDLER_ROUTING_VERSION_STORE'),

        /*
        |--------------------------------------------------------------------------
        | Optional FreeSWITCH mod_hiredis Dialplan Actions
        |--------------------------------------------------------------------------
        |
        | mod_hiredis is installed and loaded by default so FreeSWITCH can use
        | Redis-backed limits/counters when needed. These settings control
        | whether normal generated dialplans actually execute Redis-backed
        | FreeSWITCH actions during calls.
        |
        */
        'hiredis_limit_enabled' => filter_var(env('FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
        'hiredis_limit_max' => max(1, (int) env('FREESWITCH_HIREDIS_DIALPLAN_LIMIT_MAX', 100000)),
        'hiredis_marker_enabled' => filter_var(env('FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

        /*
        |--------------------------------------------------------------------------
        | Allowed Dialplan Detail Tags
        |--------------------------------------------------------------------------
        |
        | FreeSWITCH XML element names that are safe to emit inside
        | dialplan extensions. Database-backed detail records with tags
        | outside this list are skipped to prevent arbitrary XML injection.
        |
        | Standard FreeSWITCH dialplan elements: condition, action, anti-action.
        |
        */
        'allowed_dialplan_tags' => [
            'condition',
            'action',
            'anti-action',
        ],

        /*
        |--------------------------------------------------------------------------
        | Authentication Token
        |--------------------------------------------------------------------------
        |
        | The shared secret token used to authenticate FreeSWITCH
        | requests to the XML handler. Only used when auth is enabled.
        |
        */
        'token' => env('FREESWITCH_XML_HANDLER_TOKEN'),
    ],

];
