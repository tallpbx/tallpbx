# Environment Variable Reference Guide

This document provides a comprehensive, authoritative reference for all environment variables supported by TallPBX. It serves as the single source of truth for system administrators, DevOps engineers, and developers configuring TallPBX in development, staging, and high-availability production environments.

---

## Table of Contents

1. [Configuration Architecture & Operational Rules](#1-configuration-architecture--operational-rules)
2. [FreeSWITCH & Telephony Engine](#2-freeswitch--telephony-engine)
   - [2.1 Event Socket Layer (ESL) & Sofia Networking](#21-event-socket-layer-esl--sofia-networking)
   - [2.2 Telephony XML Handler & Dynamic Dialplan Engine](#22-telephony-xml-handler--dynamic-dialplan-engine)
   - [2.3 FreeSWITCH Runtime Database & DSN](#23-freeswitch-runtime-database--dsn)
   - [2.4 Core Telephony Engine, Performance & Hiredis](#24-core-telephony-engine-performance--hiredis)
   - [2.5 Outbound Call Broadcast Campaigns](#25-outbound-call-broadcast-campaigns)
   - [2.6 PBX Defaults & Demo Mode](#26-pbx-defaults--demo-mode)
3. [Security & Application Core](#3-security--application-core)
   - [3.1 Application Runtime & Maintenance](#31-application-runtime--maintenance)
   - [3.2 Authentication & Access Control](#32-authentication--access-control)
   - [3.3 Architectural Boundary & Primary Database Guards](#33-architectural-boundary--primary-database-guards)
4. [Database & Primary SQL Storage](#4-database--primary-sql-storage)
   - [4.1 SQL Connection & Authentication](#41-sql-connection--authentication)
   - [4.2 TLS/SSL Hardening & Drivers](#42-tlsssl-hardening--drivers)
5. [In-Memory Caching & Key-Value Stores](#5-in-memory-caching--key-value-stores)
   - [5.1 Redis Cluster, Connection & Resiliency](#51-redis-cluster-connection--resiliency)
   - [5.2 Application Cache Stores & Namespacing](#52-application-cache-stores--namespacing)
   - [5.3 Memcached & DynamoDB Backends](#53-memcached--dynamodb-backends)
6. [Real-Time Broadcasting & WebSockets](#6-real-time-broadcasting--websockets)
   - [6.1 Laravel Reverb WebSocket Server](#61-laravel-reverb-websocket-server)
   - [6.2 Reverb Rate Limiting, Scaling & Telemetry](#62-reverb-rate-limiting-scaling--telemetry)
   - [6.3 External Broadcast Transports (Pusher, Ably)](#63-external-broadcast-transports-pusher-ably)
7. [Filesystem, Media & Backup Storage](#7-filesystem-media--backup-storage)
   - [7.1 Local Storage Roots & Disks](#71-local-storage-roots--disks)
   - [7.2 Amazon S3 & Object Storage](#72-amazon-s3--object-storage)
   - [7.3 Livewire Temporary Uploads](#73-livewire-temporary-uploads)
8. [Queue Workers & Asynchronous Job Processing](#8-queue-workers--asynchronous-job-processing)
   - [8.1 Queue Connection & Failure Handling](#81-queue-connection--failure-handling)
   - [8.2 Database & Redis Queue Drivers](#82-database--redis-queue-drivers)
   - [8.3 Amazon SQS & Beanstalkd Drivers](#83-amazon-sqs--beanstalkd-drivers)
9. [Session Management & Web Security](#9-session-management--web-security)
   - [9.1 Session Storage & Lifetime](#91-session-storage--lifetime)
   - [9.2 Cookie Security & Transport Isolation](#92-cookie-security--transport-isolation)
10. [Logging, Telemetry & Observability](#10-logging-telemetry--observability)
    - [10.1 Logging Stack & Retention](#101-logging-stack--retention)
    - [10.2 Remote Centralized Logging & Slack Alerts](#102-remote-centralized-logging--slack-alerts)
11. [Mail & Notification Transports](#11-mail--notification-transports)
    - [11.1 SMTP & Standard Mail Transports](#111-smtp--standard-mail-transports)
    - [11.2 Cloud Mail & Webhook Notifications](#112-cloud-mail--webhook-notifications)
12. [SIP Device Auto-Provisioning](#12-sip-device-auto-provisioning)
13. [System Updater Subsystem](#13-system-updater-subsystem)
14. [Installer CLI, Build Pipeline & Testing](#14-installer-cli-build-pipeline--testing)
    - [14.1 Installer Credentials & Development Modes](#141-installer-credentials--development-modes)
    - [14.2 Vite Frontend Asset Pipeline](#142-vite-frontend-asset-pipeline)
    - [14.3 Documentation Testing Harness](#143-documentation-testing-harness)
15. [Production Sizing & Recommended Settings Reference](#15-production-sizing--recommended-settings-reference)

---

## 1. Configuration Architecture & Operational Rules

### Precedence & Hydration Flow
TallPBX resolves application configuration in a three-tier pipeline:
1. **Host Environment / `.env` File**: Host-level environment variables (and entries in `/var/www/tallpbx/.env`) provide physical deployment inputs.
2. **Laravel Configuration Hierarchy (`config/*.php`)**: The framework reads `.env` variables using `env('VAR', default)` inside `config/` files. Never call `env()` directly in domain controllers, models, or service classes; domain code must consume settings via `config('subsystem.key')`.
3. **Optimized Configuration Cache (`bootstrap/cache/config.php`)**: When configuration caching is active (`php artisan config:cache`), Laravel compiles the entire configuration tree into a single optimized PHP file. In this state, runtime `getenv()` calls are skipped by Laravel for performance.

> [!IMPORTANT]
> **Cache Invalidation Requirement**: Whenever modifying `/var/www/tallpbx/.env` or any `config/*.php` file on a running system, you **MUST** run:
> ```bash
> php artisan optimize:clear
> ```
> Failing to clear caches will result in stale runtime parameters, failed connections, and mismatched firewall or FreeSWITCH state.

### Security Best Practices
- **Restrict File Permissions**: The `.env` file contains sensitive credentials (database passwords, ESL tokens, encryption keys). It must be owned by `root:www-data` with mode `0640` or `0600` on production servers.
- **Never Commit Secrets**: Never commit real passwords, secret keys, or API tokens to version control. Default sample values belong strictly in `.env.example`.
- **Live Firewall Protection**: Changes to security, networking, or SIP port configurations flow through the application ruleset pipeline. Never mutate live kernel `nftables` chains directly.

---

## 2. FreeSWITCH & Telephony Engine

The FreeSWITCH telephony subsystem manages real-time call control, SIP signaling, RTP media handling, Event Socket Layer (ESL) integration, and dynamic XML configuration curl requests.

### 2.1 Event Socket Layer (ESL) & Sofia Networking

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `FS_SERVER` | `string` | `http://127.0.0.1` | `freeswitch.server` | Base URL used by FreeSWITCH to communicate with TallPBX via HTTP (e.g. XML curl requests). In multi-host topologies, set to the internal reachable IP/hostname of the web application. |
| `FS_DEFAULT_SIP_REALM` | `string` | `127.0.0.1` | `freeswitch.default_sip_realm` | Default SIP domain/realm used when authenticating registrations and generating directory XML when no tenant domain match is found. |
| `FS_ESL_HOST` | `string` | `127.0.0.1` | `freeswitch.esl.host` | FreeSWITCH Event Socket Layer (ESL) TCP listening host. Default `127.0.0.1` binds exclusively to loopback. |
| `FS_ESL_PORT` | `integer` | `8021` | `freeswitch.esl.port` | ESL TCP management port (standard FreeSWITCH default: `8021`). |
| `FS_ESL_PASSWORD` | `string` | `ClueCon` | `freeswitch.esl.password` | **CRITICAL SECRET**: Authentication password for ESL connections (`autoload_configs/event_socket.conf.xml`). **Must be changed from `ClueCon` in production** to prevent unauthorized full remote code execution on the PBX. |
| `FS_ESL_TIMEOUT` | `integer` | `5` | `freeswitch.esl.timeout` | Connection and socket read timeout in seconds for synchronous ESL command execution. |
| `FS_ESL_RECONNECT_INTERVAL`| `integer` | `5` | `freeswitch.esl.reconnect_interval`| Delay in seconds between connection retries when the background ESL event loop listener disconnects. |
| `FS_ESL_QUEUE_NAME` | `string` | `default` | `freeswitch.esl.queue_name` | Name of the background queue channel utilized for offloading event listeners (e.g. security incident recording, call recordings, voicemail persistence). Default: `default`. |
| `FS_ESL_BUFFER_SIZE` | `integer` | `1000` | `freeswitch.esl.buffer_size` | In-memory queue buffer capacity for non-blocking ESL event processing in `FreeSwitchListenCommand`, preventing socket drops during high-concurrency event bursts. |
| `FS_SOFIA_LOG_LEVEL` | `integer` | `0` | `freeswitch.sofia.log_level` | Debug verbosity level for Sofia SIP engine (0–9). Level 0 disables verbose packet logging; higher values log full SIP packet traces to `/var/log/freeswitch/freeswitch.log`. |
| `FS_SOFIA_AUTO_RESTART` | `boolean` | `true` | `freeswitch.sofia.auto_restart` | Whether to automatically restart Sofia SIP profiles upon runtime reload or configuration mutation. |
| `FS_SOFIA_DEBUG_PRESENCE` | `boolean` | `false` | `freeswitch.sofia.debug_presence` | Enables detailed debugging logs for BLF (Busy Lamp Field) and SIP SUBSCRIBE/NOTIFY dialog presence. |
| `FS_SOFIA_CAPTURE_SERVER` | `string` | `null` | `freeswitch.sofia.capture_server` | Remote HEP/Homer capture server destination (e.g. `udp:10.0.0.50:9060`) for centralized SIP packet inspection. |
| `FS_SOFIA_INBOUND_REG_THREAD`| `boolean` | `true` | `freeswitch.sofia.inbound_reg_in_new_thread` | Spawns a dedicated thread for each inbound SIP REGISTER transaction, significantly increasing registration concurrency under heavy traffic. |
| `FS_SOFIA_MAX_REG_THREADS` | `integer` | `8` | `freeswitch.sofia.max_reg_threads` | Maximum concurrent worker threads allocated for inbound SIP registration processing. |

---

### 2.2 Telephony XML Handler & Dynamic Dialplan Engine

TallPBX serves dynamic directory, dialplan, and configuration XML to FreeSWITCH via `mod_xml_curl` through `/api/v1/xml-handler`.

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `FS_XML_HANDLER_AUTH` | `boolean` | `true` | `freeswitch.xml_handler.auth` | Enforces shared-token authentication on `/api/v1/xml-handler`. Set to `true` in all production systems. |
| `FS_XML_HANDLER_PATH` | `string` | `/api/v1/xml-handler` | `freeswitch.xml_handler.path` | API endpoint path targeted by `xml_curl.conf.xml` bindings. |
| `FS_XML_HANDLER_TOKEN` | `string` | `null` | `freeswitch.xml_handler.token` | **CRITICAL SECRET**: Bearer token or query parameter token required to access the XML handler. Failed authentication attempts trigger immediate security alerts and automated firewall banning. |
| `FS_XML_HANDLER_LOG_REQUESTS`| `boolean`| `false` | `freeswitch.xml_handler.log_requests` | Logs all incoming XML curl requests and raw POST parameters to Laravel logs for debugging. |
| `FS_XML_HANDLER_LOG_TIMING` | `boolean` | `false` | `freeswitch.xml_handler.log_timing` | Records request-to-response generation latency benchmarks in logs. |
| `FS_XML_HANDLER_CACHE_STORE` | `string` | `redis` | `freeswitch.xml_handler.cache.store` | Master cache store (e.g. `redis`, `file`) for FreeSWITCH XML responses. Defaults to system `CACHE_STORE` if omitted. |
| `FS_XML_HANDLER_CACHE_TTL` | `integer` | `5` | `freeswitch.xml_handler.cache.ttl` | Master cache time-to-live in seconds for XML responses. Changing this setting synchronizes dialplan, directory, and ACL caches simultaneously. |
| `FS_XML_HANDLER_DIALPLAN_CACHE_STORE` | `string` | *(master)* | `freeswitch.xml_handler.cache.dialplan.store` | Specific cache store override for dialplan XML generation. |
| `FS_XML_HANDLER_DIALPLAN_CACHE_TTL` | `integer` | *(master)* | `freeswitch.xml_handler.cache.dialplan.ttl` | Specific TTL in seconds for dialplan XML responses. |
| `FS_XML_HANDLER_CONTRIBUTOR_CACHE_TTL`| `integer` | *(master)* | `freeswitch.xml_handler.cache.contributor.ttl` | Specific TTL in seconds for modular dialplan contributor fragments. |
| `FS_XML_HANDLER_DIRECTORY_CACHE_STORE`| `string` | *(master)* | `freeswitch.xml_handler.cache.directory.store`| Specific cache store override for directory XML (user lookups, SIP credentials). |
| `FS_XML_HANDLER_DIRECTORY_CACHE_TTL` | `integer` | *(master)* | `freeswitch.xml_handler.cache.directory.ttl` | Specific TTL in seconds for user directory XML. |
| `FS_XML_HANDLER_ACL_CACHE_STORE` | `string` | *(master)* | `freeswitch.xml_handler.cache.acl.store` | Specific cache store override for IP Access Control List (ACL) configurations. |
| `FS_XML_HANDLER_ACL_CACHE_TTL` | `integer` | *(master)* | `freeswitch.xml_handler.cache.acl.ttl` | Specific TTL in seconds for ACL configurations. |
| `FS_XML_HANDLER_ROUTING_VERSION_STORE`| `string` | `redis` | `freeswitch.xml_handler.cache.routing_version_store` | Fast key-value store used to hold global routing invalidation version tags. |
| `FS_XML_HANDLER_THROTTLE_ENABLED` | `boolean` | `true` | `freeswitch.xml_handler.throttle.enabled` | Enables rate limiting on `/api/v1/xml-handler` to protect against token brute forcing and denial of service. |
| `FS_XML_HANDLER_THROTTLE_LIMIT` | `integer` | `600` | `freeswitch.xml_handler.throttle.limit` | Maximum allowed XML curl requests per minute per IP address. |
| `FS_XML_HANDLER_THROTTLE_DECAY_MINUTES`| `integer` | `1` | `freeswitch.xml_handler.throttle.decay_minutes` | Decay window in minutes for XML handler rate limiting. |

---

### 2.3 FreeSWITCH Runtime Database & DSN

Configures FreeSWITCH's internal state storage engine (Sofia registrations, active channels, NAT mappings).

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `FS_DB_DRIVER` | `string` | `sqlite` | `freeswitch.db.driver` | FreeSWITCH internal state storage driver (`sqlite`, `mariadb`, `pgsql`, or `odbc`). |
| `FS_DB_DSN` | `string` | `null` | `freeswitch.db.dsn` | Direct connection string URI for MariaDB/PostgreSQL (e.g. `mariadb://Server=127.0.0.1;Port=3306;Database=freeswitch;Uid=...;Pwd=...`). |
| `FS_DB_ODBC_DSN` | `string` | `null` | `freeswitch.db.odbc_dsn` | ODBC DSN string formatted as `dsn:username:password` when using unixODBC. |
| `FS_DB_HOST` | `string` | `127.0.0.1` | `freeswitch.db.host` | Database hostname for FreeSWITCH runtime tables. |
| `FS_DB_PORT` | `integer` | `3306` | `freeswitch.db.port` | Database port for FreeSWITCH runtime tables. |
| `FS_DB_NAME` | `string` | `freeswitch` | `freeswitch.db.name` | Database name dedicated to FreeSWITCH runtime tables. |
| `FS_DB_USERNAME` | `string` | `freeswitch` | `freeswitch.db.username` | Database user for FreeSWITCH runtime tables. |
| `FS_DB_PASSWORD` | `string` | `null` | `freeswitch.db.password` | Database password for FreeSWITCH runtime tables. |
| `FS_DB_OPTIONS` | `string` | `null` | `freeswitch.db.options` | Optional database driver options string appended to DSN. |
| `FS_CORE_DB_NAME` | `string` | `null` | `freeswitch.db.core_db_name` | Custom path or database name for core FreeSWITCH SQLite database (`/var/lib/freeswitch/db/core.db`). |
| `FS_AUTO_CREATE_SCHEMAS` | `boolean` | `true` | `freeswitch.db.auto_create_schemas` | Whether FreeSWITCH automatically creates missing schema tables in external database on startup. |
| `FS_CORE_NON_SQLITE_DB_REQUIRED`| `boolean`| `false` | `freeswitch.db.core_non_sqlite_db_required` | Forces FreeSWITCH to halt startup if a non-SQLite external database is unavailable. |
| `FS_ODBC_SKIP_AUTOCOMMIT_FLIP` | `boolean`| `false` | `freeswitch.db.odbc_skip_autocommit_flip` | Skips ODBC autocommit flipping for specialized database drivers. |
| `FS_AUTO_CLEAR_SQL` | `boolean` | `false` | `freeswitch.db.auto_clear_sql` | Automatically drops and recreates runtime SQL tables on system reboot. |

---

### 2.4 Core Telephony Engine, Performance & Hiredis

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `FS_LOG_LEVEL` | `string` | `debug` | `freeswitch.log_level` | FreeSWITCH console log level (`console`, `alert`, `crit`, `err`, `warning`, `notice`, `info`, `debug`). |
| `FS_SESSIONS_PER_SECOND` | `integer` | `60` | `freeswitch.sessions_per_second` | Global call origination rate limit (SPS) enforced in `switch.conf.xml` to prevent CPU starvation. |
| `FS_HIREDIS_LIMIT_ENABLED` | `boolean` | `false` | `freeswitch.hiredis.limit_enabled` | Enables `mod_hiredis` distributed call concurrency and rate limits across PBX clusters. |
| `FS_HIREDIS_LIMIT_MAX` | `integer` | `100000` | `freeswitch.hiredis.limit_max` | Maximum total active limits tracked in Hiredis. |
| `FS_HIREDIS_MARKER_ENABLED`| `boolean` | `false` | `freeswitch.hiredis.marker_enabled`| Enables cluster heartbeat markers in Redis for multi-PBX node health detection. |
| `FS_PIN_TRIGGER` | `string` | `*99` | `freeswitch.pin_trigger` | Feature code prefix dialed to initiate user PIN management IVR. |

---

### 2.5 Outbound Call Broadcast Campaigns

Configures automated outbound dialing, campaign pacing, and outcome tracking.

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `FS_CALL_BROADCAST_CALLER_ID` | `string` | `0000000000` | `call-broadcast.caller_id` | Default outbound caller ID number utilized for campaigns if unspecified. |
| `FS_CALL_BROADCAST_MEDIA` | `string` | `ivr/ivr-welcome.wav` | `call-broadcast.media` | Default audio prompt played to recipients upon call answer. |
| `FS_CALL_BROADCAST_PACING_SECONDS` | `integer` | `1` | `call-broadcast.pacing_seconds` | Interval in seconds between sequential outbound campaign call originations. |
| `FS_CALL_BROADCAST_OUTCOME_WINDOW_MINUTES` | `integer` | `120` | `call-broadcast.outcome_window_minutes` | Time window in minutes before unanswered or orphaned broadcast calls expire. |

---

### 2.6 PBX Defaults & Demo Mode

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `PBX_DEFAULT_SIP_PASSWORD` | `string` | `null` | `freeswitch.default_sip_password` | Default SIP password assigned when creating extensions if left blank. Must be strong if set. |
| `PBX_DEMO_MODE` | `boolean` | `false` | `freeswitch.demo_mode` | Populates sample demo extensions, dialplans, and IVR configurations during initial installer seeding. |

---

## 3. Security & Application Core

Manages core Laravel application lifecycle, encryption keys, admin authentication, and strict multi-tenant isolation.

### 3.1 Application Runtime & Maintenance

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `APP_NAME` | `string` | `TallPBX` | `app.name` | Display name of the PBX application across panel headings, emails, and page titles. |
| `APP_ENV` | `string` | `production`| `app.env` | Application environment (`production`, `local`, `testing`). In `production`, debug traces are disabled. |
| `APP_KEY` | `string` | `null` | `app.key` | **CRITICAL SECRET**: 32-character base64 AES encryption key (`base64:...`). Used to encrypt user passwords, sessions, credentials, and tokens. |
| `APP_PREVIOUS_KEYS` | `string` | `""` | `app.previous_keys` | Comma-delimited list of previous encryption keys, enabling zero-downtime key rotation. |
| `APP_DEBUG` | `boolean` | `false` | `app.debug` | Displays detailed stack traces and system variables upon exception. **MUST be `false` in production**. |
| `APP_URL` | `string` | `http://localhost` | `app.url` | Canonical fully-qualified URL for the PBX administration panel. |
| `APP_LOCALE` | `string` | `en` | `app.locale` | Primary default interface language (`en`, `es`, `fr`). |
| `APP_FALLBACK_LOCALE` | `string` | `en` | `app.fallback_locale` | Fallback language when a specific translation string is missing in the chosen locale. |
| `APP_FAKER_LOCALE` | `string` | `en_US` | `app.faker_locale` | Locale used for generating test and factory data. |
| `APP_MAINTENANCE_DRIVER` | `string` | `file` | `app.maintenance.driver` | Maintenance mode lock storage driver (`file`, `cache`). |
| `APP_MAINTENANCE_STORE` | `string` | `null` | `app.maintenance.store` | Specific cache store used when maintenance driver is set to `cache`. |

---

### 3.2 Authentication & Access Control

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `AUTH_REGISTRATION_ENABLED` | `boolean` | `false` | `auth.registration_enabled` | **SECURITY GATE**: Enables public user self-registration at `/panel/register`. Default `false` blocks unauthorized account creation. |
| `AUTH_GUARD` | `string` | `web` | `auth.defaults.guard` | Default authentication guard for user sessions. |
| `AUTH_PASSWORD_BROKER` | `string` | `users` | `auth.defaults.passwords` | Default password broker definition. |
| `AUTH_MODEL` | `string` | `App\Models\User` | `auth.providers.users.model` | User Eloquent model for user authentication. |
| `AUTH_PASSWORD_RESET_TOKEN_TABLE` | `string` | `password_reset_tokens` | `auth.passwords.users.table` | Database table storing password reset tokens. |
| `AUTH_PASSWORD_TIMEOUT` | `integer` | `10800` | `auth.password_timeout` | Duration in seconds before password confirmation re-prompt expires (3 hours). |
| `BCRYPT_ROUNDS` | `integer` | `12` | *(hashing)* | Work factor for Bcrypt password hashing. Increasing above 12 increases resistance to brute forcing at the cost of higher CPU time. |

---

### 3.3 Architectural Boundary & Primary Database Guards

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `TALLPBX_PRIMARY_DATABASE` | `string` | `tallpbx` | `app.primary_database` | **SAFETY GUARD**: Name of the primary live database. TallPBX's database protection middleware rejects destructive Artisan commands (`migrate:fresh`, `db:wipe`) if executed against this database. |

---

## 4. Database & Primary SQL Storage

TallPBX utilizes MariaDB or MySQL for application data, tenant configuration, call records, and security telemetry.

### 4.1 SQL Connection & Authentication

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `DB_CONNECTION` | `string` | `mysql` | `database.default` | Primary database connection driver (`mysql`, `sqlite`, `pgsql`, `sqlsrv`). |
| `DB_HOST` | `string` | `127.0.0.1` | `database.connections.mysql.host` | Database server host. Using `127.0.0.1` avoids Unix socket fallback overhead. |
| `DB_PORT` | `integer` | `3306` | `database.connections.mysql.port` | Database server port. |
| `DB_DATABASE` | `string` | `tallpbx` | `database.connections.mysql.database`| Name of the primary TallPBX SQL database. |
| `DB_USERNAME` | `string` | `tallpbx` | `database.connections.mysql.username`| Database user credentials. |
| `DB_PASSWORD` | `string` | `null` | `database.connections.mysql.password`| **CRITICAL SECRET**: Database user password. |
| `DB_SOCKET` | `string` | `null` | `database.connections.mysql.unix_socket`| Path to local MySQL Unix socket file (e.g. `/var/run/mysqld/mysqld.sock`). |
| `DB_URL` | `string` | `null` | `database.connections.mysql.url` | Database connection URL string combining host, user, and password. |
| `DB_CHARSET` | `string` | `utf8mb4` | `database.connections.mysql.charset` | Database character set encoding (standard `utf8mb4`). |
| `DB_COLLATION` | `string` | `utf8mb4_unicode_ci` | `database.connections.mysql.collation` | Database collation sorting rule. |
| `DB_FOREIGN_KEYS` | `boolean` | `true` | `database.connections.sqlite.foreign_key_constraints` | Enforces relational foreign key constraints in SQLite test databases. |

---

### 4.2 TLS/SSL Hardening & Drivers

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `MYSQL_ATTR_SSL_CA` | `path` | `null` | `database.connections.mysql.options.MYSQL_ATTR_SSL_CA` | File path to SSL CA certificate bundle for encrypted database connections. |
| `DB_SSLMODE` | `string` | `prefer` | `database.connections.pgsql.sslmode` | SSL connection mode for PostgreSQL (`disable`, `prefer`, `require`, `verify-ca`, `verify-full`). |
| `DB_TRUST_SERVER_CERTIFICATE` | `boolean`| `false` | `database.connections.sqlsrv.trust_server_certificate`| Whether to trust self-signed database certificates in SQL Server connections. |
| `DB_ENCRYPT` | `string` | `'yes'` | `database.connections.sqlsrv.encrypt` | Connection encryption flag for SQL Server. |

---

## 5. In-Memory Caching & Key-Value Stores

TallPBX relies heavily on Redis for high-throughput XML response caching, session management, WebSockets, and real-time state synchronization.

### 5.1 Redis Cluster, Connection & Resiliency

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `REDIS_CLIENT` | `string` | `phpredis` | `database.redis.client` | Redis PHP extension client (`phpredis` recommended for C-level speed, or `predis`). |
| `REDIS_HOST` | `string` | `127.0.0.1` | `database.redis.default.host` | Redis server hostname. |
| `REDIS_PORT` | `integer` | `6379` | `database.redis.default.port` | Redis server TCP port. |
| `REDIS_PASSWORD` | `string` | `null` | `database.redis.default.password`| Authentication password for Redis server. Highly recommended in multi-server networks. |
| `REDIS_USERNAME` | `string` | `null` | `database.redis.default.username`| ACL username for Redis 6+ authenticated connections. |
| `REDIS_DB` | `integer` | `0` | `database.redis.default.database`| Default Redis database index (0–15). |
| `REDIS_PREFIX` | `string` | `tallpbx_database_` | `database.redis.options.prefix` | Key prefix for database Redis connections. |
| `REDIS_URL` | `string` | `null` | `database.redis.default.url` | Redis connection URL string. |
| `REDIS_PERSISTENT` | `boolean` | `false` | `database.redis.default.persistent`| Maintains persistent socket connections across HTTP requests, eliminating TCP handshake overhead. |
| `REDIS_TIMEOUT` | `float` | `null` | `database.redis.default.timeout` | Connection timeout in seconds. |
| `REDIS_MAX_RETRIES` | `integer` | `null` | `database.redis.default.max_retries` | Maximum command retry attempts upon transient Redis connection drop. |
| `REDIS_BACKOFF_ALGORITHM`| `string` | `null` | `database.redis.default.backoff_algorithm` | Exponential backoff algorithm for Redis connection retries. |
| `REDIS_BACKOFF_BASE` | `integer` | `null` | `database.redis.default.backoff_base` | Base backoff duration in milliseconds. |
| `REDIS_BACKOFF_CAP` | `integer` | `null` | `database.redis.default.backoff_cap` | Maximum backoff cap duration in milliseconds. |
| `REDIS_CLUSTER` | `string` | `redis` | `database.redis.options.cluster` | Redis cluster driver name when deploying clustered topologies. |
| `REDIS_CACHE_DB` | `integer` | `1` | `database.redis.cache.database` | Redis database index isolated specifically for application and XML cache keys. |
| `REDIS_CACHE_CONNECTION` | `string` | `cache` | `cache.stores.redis.connection` | Named Redis connection configuration mapped to cache operations. |
| `REDIS_CACHE_LOCK_CONNECTION` | `string` | `default` | `cache.stores.redis.lock_connection` | Redis connection used for atomic distributed mutex locks. |

---

### 5.2 Application Cache Stores & Namespacing

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `CACHE_STORE` | `string` | `redis` | `cache.default` | Application cache backend (`redis`, `file`, `database`, `memcached`, `array`). |
| `CACHE_PREFIX` | `string` | `tallpbx_cache_` | `cache.prefix` | Global namespace prefix prepended to all cache keys to avoid collision on shared Redis servers. |
| `CACHE_STORAGE_DISK` | `string` | `null` | `cache.stores.file.disk` | Storage disk allocated for file-based caching. |
| `CACHE_STORAGE_PATH` | `path` | `null` | `cache.stores.file.path` | Custom local filesystem directory path for file-based cache artifacts. |
| `DB_CACHE_CONNECTION` | `string` | `null` | `cache.stores.database.connection` | SQL database connection used when `CACHE_STORE=database`. |
| `DB_CACHE_TABLE` | `string` | `cache` | `cache.stores.database.table` | Database table name storing serialized cache items. |
| `DB_CACHE_LOCK_CONNECTION`| `string` | `null` | `cache.stores.database.lock_connection` | Database connection used for database-backed locks. |
| `DB_CACHE_LOCK_TABLE` | `string` | `cache_locks` | `cache.stores.database.lock_table` | Database table storing atomic lock records. |

---

### 5.3 Memcached & DynamoDB Backends

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `MEMCACHED_HOST` | `string` | `127.0.0.1` | `cache.stores.memcached.servers.0.host` | Memcached server hostname. |
| `MEMCACHED_PORT` | `integer` | `11211` | `cache.stores.memcached.servers.0.port` | Memcached server TCP port. |
| `MEMCACHED_USERNAME` | `string` | `null` | `cache.stores.memcached.options.OPT_USERNAME` | SASL username for Memcached authentication. |
| `MEMCACHED_PASSWORD` | `string` | `null` | `cache.stores.memcached.options.OPT_PASSWORD` | SASL password for Memcached authentication. |
| `MEMCACHED_PERSISTENT_ID`| `string` | `null` | `cache.stores.memcached.persistent_id` | Persistent connection ID for Memcached client pooling. |
| `DYNAMODB_CACHE_TABLE` | `string` | `cache` | `cache.stores.dynamodb.table` | AWS DynamoDB table name for distributed cloud caching. |
| `DYNAMODB_ENDPOINT` | `string` | `null` | `cache.stores.dynamodb.endpoint` | Custom AWS DynamoDB endpoint URL (e.g. for local DynamoDB testing). |

---

## 6. Real-Time Broadcasting & WebSockets

TallPBX utilizes Laravel Reverb and Laravel Echo for real-time push updates across telephony status views, operator panels, and security cockpit alerts, eliminating aggressive HTTP polling.

### 6.1 Laravel Reverb WebSocket Server

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `BROADCAST_CONNECTION` | `string` | `reverb` | `broadcasting.default` | Default broadcast connection (`reverb`, `pusher`, `ably`, `null`). Set to `reverb` in TallPBX. |
| `REVERB_APP_ID` | `string` | `tallpbx` | `broadcasting.connections.reverb.app_id` | Application identifier registered with Reverb. |
| `REVERB_APP_KEY` | `string` | `null` | `broadcasting.connections.reverb.key` | Public application key used by client-side browser Echo instances. |
| `REVERB_APP_SECRET` | `string` | `null` | `broadcasting.connections.reverb.secret` | **CRITICAL SECRET**: Secret key used by the application server to sign broadcast payloads and private channel authorizations. |
| `REVERB_HOST` | `string` | `127.0.0.1` | `broadcasting.connections.reverb.options.host` | Hostname or IP where Reverb is reachable by the PBX web backend. |
| `REVERB_PORT` | `integer` | `8080` | `broadcasting.connections.reverb.options.port` | Port where Reverb listens for incoming broadcast events from the backend. |
| `REVERB_SCHEME` | `string` | `http` | `broadcasting.connections.reverb.options.scheme` | Protocol scheme (`http` or `https`) for backend-to-Reverb broadcast transmission. |
| `REVERB_SERVER` | `string` | `reverb` | `reverb.default` | Reverb server execution driver. |
| `REVERB_SERVER_HOST` | `string` | `0.0.0.0` | `reverb.servers.reverb.host` | Network interface IP bound by the `php artisan reverb:start` daemon (`0.0.0.0` binds all interfaces). |
| `REVERB_SERVER_PORT` | `integer` | `8080` | `reverb.servers.reverb.port` | Local TCP port bound by the Reverb daemon. |
| `REVERB_SERVER_PATH` | `string` | `""` | `reverb.servers.reverb.options.path` | Optional URL subpath prefix for WebSocket connections. |
| `REVERB_APP_PING_INTERVAL` | `integer` | `60` | `reverb.apps.apps.0.ping_interval` | Ping heartbeat interval in seconds to keep WebSocket connections alive across stateful firewalls. |
| `REVERB_APP_ACTIVITY_TIMEOUT` | `integer` | `30` | `reverb.apps.apps.0.activity_timeout` | Duration in seconds of client inactivity before closing disconnected sockets. |
| `REVERB_APP_MAX_CONNECTIONS` | `integer` | `null` | `reverb.apps.apps.0.max_connections` | Hard ceiling on concurrent client WebSocket connections (unlimited if null). |
| `REVERB_APP_MAX_MESSAGE_SIZE` | `integer` | `10000` | `reverb.apps.apps.0.max_message_size` | Maximum allowable incoming WebSocket frame payload size in bytes. |
| `REVERB_MAX_REQUEST_SIZE` | `integer` | `10000` | `reverb.servers.reverb.max_request_size` | Maximum allowable incoming HTTP request size in bytes to Reverb. |

---

### 6.2 Reverb Rate Limiting, Scaling & Telemetry

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `REVERB_APP_RATE_LIMITING_ENABLED` | `boolean` | `false` | `reverb.apps.apps.0.rate_limiting.enabled` | Enables client message rate limiting. |
| `REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS` | `integer` | `60` | `reverb.apps.apps.0.rate_limiting.max_attempts` | Maximum client messages allowed within decay window. |
| `REVERB_APP_RATE_LIMIT_DECAY_SECONDS` | `integer` | `60` | `reverb.apps.apps.0.rate_limiting.decay_seconds` | Decay window in seconds for message rate limiter. |
| `REVERB_APP_RATE_LIMIT_TERMINATE` | `boolean` | `false` | `reverb.apps.apps.0.rate_limiting.terminate_connection` | Immediately disconnects clients that breach rate limits. |
| `REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM` | `string` | `members` | `reverb.apps.apps.0.accept_client_events_from` | Restricts client-side whisper events (`members` requires channel presence). |
| `REVERB_SCALING_ENABLED` | `boolean` | `false` | `reverb.servers.reverb.scaling.enabled` | Enables horizontal multi-server Reverb scaling using Redis Pub/Sub. |
| `REVERB_SCALING_CHANNEL` | `string` | `reverb` | `reverb.servers.reverb.scaling.channel` | Redis Pub/Sub channel used to fan out WebSocket broadcasts across PBX nodes. |
| `REVERB_PULSE_INGEST_INTERVAL` | `integer` | `15` | `reverb.servers.reverb.pulse_ingest_interval` | Metric ingestion interval in seconds for Laravel Pulse performance monitoring. |
| `REVERB_TELESCOPE_INGEST_INTERVAL` | `integer` | `15` | `reverb.servers.reverb.telescope_ingest_interval`| Ingestion interval in seconds for Laravel Telescope debugging records. |

---

### 6.3 External Broadcast Transports (Pusher, Ably)

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `PUSHER_APP_ID` | `string` | `null` | `broadcasting.connections.pusher.app_id` | Application ID for hosted Pusher Channels. |
| `PUSHER_APP_KEY` | `string` | `null` | `broadcasting.connections.pusher.key` | Public key for hosted Pusher Channels. |
| `PUSHER_APP_SECRET` | `string` | `null` | `broadcasting.connections.pusher.secret` | **CRITICAL SECRET**: Secret key for hosted Pusher Channels. |
| `PUSHER_APP_CLUSTER` | `string` | `mt1` | `broadcasting.connections.pusher.options.cluster` | Geographic cluster region for Pusher (e.g. `mt1`, `eu`). |
| `PUSHER_HOST` | `string` | `null` | `broadcasting.connections.pusher.options.host` | Custom endpoint host for Pusher protocol emulators (Soketi). |
| `PUSHER_PORT` | `integer` | `443` | `broadcasting.connections.pusher.options.port` | Custom port for Pusher protocol connections. |
| `PUSHER_SCHEME` | `string` | `https` | `broadcasting.connections.pusher.options.scheme` | Protocol scheme (`http` or `https`) for Pusher connections. |
| `ABLY_KEY` | `string` | `null` | `broadcasting.connections.ably.key` | API access key for Ably Realtime broadcasting service. |

---

## 7. Filesystem, Media & Backup Storage

Configures local storage directories and cloud object storage integrations for audio recordings, voicemails, sound prompts, and PBX disaster recovery backups.

### 7.1 Local Storage Roots & Disks

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `FILESYSTEM_DISK` | `string` | `local` | `filesystems.default` | Default application storage disk (`local`, `public`, `s3`). |
| `TALLPBX_MEDIA_ROOT` | `path` | `/var/lib/tallpbx/media` | `media-storage.root` | Authoritative root filesystem path for all PBX media (call recordings, voicemail audio, greeting prompts). Standard permissions: `2775 www-data:www-data`. |
| `TALLPBX_BACKUP_ROOT` | `path` | `/var/lib/tallpbx/backups`| `backup-storage.root` | Authoritative root filesystem path for disaster recovery archive archives. Standard permissions: `2770 root:www-data`. |

---

### 7.2 Amazon S3 & Object Storage

Configures cloud storage for archiving call recordings or storing offsite database backups.

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `AWS_ACCESS_KEY_ID` | `string` | `null` | `filesystems.disks.s3.key` | IAM Access Key ID for AWS or S3-compatible cloud object storage (Cloudflare R2, MinIO, Wasabi). |
| `AWS_SECRET_ACCESS_KEY` | `string` | `null` | `filesystems.disks.s3.secret` | **CRITICAL SECRET**: IAM Secret Access Key for cloud storage. |
| `AWS_DEFAULT_REGION` | `string` | `us-east-1` | `filesystems.disks.s3.region` | S3 bucket region (e.g. `us-east-1`, `eu-west-1`). |
| `AWS_BUCKET` | `string` | `null` | `filesystems.disks.s3.bucket` | Target bucket name. |
| `AWS_URL` | `string` | `null` | `filesystems.disks.s3.url` | Public CDN URL prefix for stored assets if public URLs are enabled. |
| `AWS_ENDPOINT` | `string` | `null` | `filesystems.disks.s3.endpoint` | Custom S3 endpoint URL for MinIO, Cloudflare R2, or Ceph storage. |
| `AWS_USE_PATH_STYLE_ENDPOINT`| `boolean`| `false` | `filesystems.disks.s3.use_path_style_endpoint` | Enables path-style S3 URLs (`endpoint/bucket`), required for MinIO and legacy S3 backends. |

---

### 7.3 Livewire Temporary Uploads

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` | `string` | `null` | `livewire.temporary_file_upload.disk` | Specific disk utilized for staging temporary file uploads before processing. |

---

## 8. Queue Workers & Asynchronous Job Processing

Offloads time-consuming tasks (call recording transcodes, voicemail delivery, email notifications, threat feed downloads) to background workers.

### 8.1 Queue Connection & Failure Handling

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `QUEUE_CONNECTION` | `string` | `database` | `queue.default` | Active queue connection driver (`database`, `redis`, `beanstalkd`, `sqs`, `sync`). |
| `QUEUE_FAILED_DRIVER` | `string` | `database-uuids` | `queue.failed.driver` | Storage driver used for tracking failed job payloads. |

---

### 8.2 Database & Redis Queue Drivers

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `DB_QUEUE_CONNECTION` | `string` | `null` | `queue.connections.database.connection` | SQL database connection used for job queue. |
| `DB_QUEUE_TABLE` | `string` | `jobs` | `queue.connections.database.table` | Database table holding queued jobs. |
| `DB_QUEUE` | `string` | `default` | `queue.connections.database.queue` | Default queue channel name on database driver. |
| `DB_QUEUE_RETRY_AFTER` | `integer` | `90` | `queue.connections.database.retry_after` | Timeout in seconds before an unacknowledged job is released back to workers. |
| `REDIS_QUEUE_CONNECTION` | `string` | `default` | `queue.connections.redis.connection` | Named Redis connection mapped to queue processing. |
| `REDIS_QUEUE` | `string` | `default` | `queue.connections.redis.queue` | Default queue channel name on Redis driver. |
| `REDIS_QUEUE_RETRY_AFTER`| `integer` | `90` | `queue.connections.redis.retry_after` | Timeout in seconds before an in-flight Redis job is retried. |

---

### 8.3 Amazon SQS & Beanstalkd Drivers

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `SQS_PREFIX` | `string` | *(aws url)* | `queue.connections.sqs.prefix` | Base URL prefix for AWS SQS queues. |
| `SQS_QUEUE` | `string` | `default` | `queue.connections.sqs.queue` | Primary queue name on AWS SQS. |
| `SQS_SUFFIX` | `string` | `null` | `queue.connections.sqs.suffix` | Optional suffix string appended to SQS queue names. |
| `BEANSTALKD_QUEUE_HOST` | `string` | `localhost` | `queue.connections.beanstalkd.host` | Hostname for Beanstalkd work queue daemon. |
| `BEANSTALKD_QUEUE` | `string` | `default` | `queue.connections.beanstalkd.queue` | Tube name in Beanstalkd. |
| `BEANSTALKD_QUEUE_RETRY_AFTER` | `integer` | `90` | `queue.connections.beanstalkd.retry_after` | Retry delay in seconds for Beanstalkd jobs. |

---

## 9. Session Management & Web Security

Governs administrator and tenant user sessions, authentication persistence, and browser cookie boundaries.

### 9.1 Session Storage & Lifetime

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `SESSION_DRIVER` | `string` | `redis` | `session.driver` | Session storage driver (`redis` recommended, `database`, `file`, `cookie`). |
| `SESSION_LIFETIME` | `integer` | `120` | `session.lifetime` | Session idle expiration time in minutes (default 2 hours). |
| `SESSION_EXPIRE_ON_CLOSE` | `boolean` | `false` | `session.expire_on_close` | Forces browser sessions to invalidate immediately when the user closes their browser window. |
| `SESSION_ENCRYPT` | `boolean` | `true` | `session.encrypt` | Encrypts all session data at rest using `APP_KEY`. Default `true` protects session payloads from exposure. |
| `SESSION_CONNECTION` | `string` | `cache` | `session.connection` | Dedicated Redis connection utilized for storing session keys. |
| `SESSION_STORE` | `string` | `null` | `session.store` | Specific cache store mapped when using cache session driver. |
| `SESSION_TABLE` | `string` | `sessions` | `session.table` | Database table used when storing sessions in SQL database. |

---

### 9.2 Cookie Security & Transport Isolation

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `SESSION_COOKIE` | `string` | *(slug)* | `session.cookie` | Name of the authentication cookie sent to user browsers. |
| `SESSION_SECURE_COOKIE` | `boolean` | `false` | `session.secure` | **CRITICAL SECURITY ENFORCEMENT**: Restricts session cookie transmission exclusively over HTTPS connections. Set to `true` automatically by installer once TLS certificates are active. Keep `false` only during plain-HTTP development. |
| `SESSION_HTTP_ONLY` | `boolean` | `true` | `session.http_only` | Prevents JavaScript access to the session cookie (`HttpOnly` flag), blocking Cross-Site Scripting (XSS) session hijacking. |
| `SESSION_SAME_SITE` | `string` | `lax` | `session.same_site` | SameSite cookie policy (`lax`, `strict`, `none`) mitigating Cross-Site Request Forgery (CSRF). |
| `SESSION_PARTITIONED_COOKIE`| `boolean` | `false` | `session.partitioned` | Sets the CHIPS `Partitioned` attribute on cookies for partitioned storage in third-party frames. |
| `SESSION_DOMAIN` | `string` | `null` | `session.domain` | Cookie domain boundary. Setting to `.example.com` shares sessions across subdomains; leaving `null` binds strictly to the host. |
| `SESSION_PATH` | `string` | `/` | `session.path` | URL path scope for session cookies. |

---

## 10. Logging, Telemetry & Observability

Configures application error reporting, log rotation, security telemetry, and remote centralized monitoring.

### 10.1 Logging Stack & Retention

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `LOG_CHANNEL` | `string` | `stack` | `logging.default` | Active logging channel (`stack`, `single`, `daily`, `syslog`, `stderr`). |
| `LOG_STACK` | `string` | `single` | `logging.channels.stack.channels` | Comma-delimited list of logging channels aggregated into the stack logger. |
| `LOG_LEVEL` | `string` | `debug` | `logging.channels.single.level` | Minimum error severity recorded to disk (`debug`, `info`, `notice`, `warning`, `error`, `critical`, `alert`, `emergency`). In high-traffic production systems, set to `info` or `warning` to reduce disk I/O. |
| `LOG_DAILY_DAYS` | `integer` | `14` | `logging.channels.daily.days` | Number of daily rotated log files retained before automatic deletion. |
| `LOG_STDERR_FORMATTER` | `string` | `null` | `logging.channels.stderr.formatter` | Custom log formatter class applied to stderr stream outputs. |
| `LOG_SYSLOG_FACILITY` | `integer` | `LOG_USER` | `logging.channels.syslog.facility` | POSIX syslog facility level used when dispatching events to host `rsyslog` or `systemd-journald`. |
| `LOG_DEPRECATIONS_CHANNEL` | `string` | `null` | `logging.deprecations.channel` | Dedicated log channel for PHP or framework deprecation warnings. |
| `LOG_DEPRECATIONS_TRACE` | `boolean` | `false` | `logging.deprecations.trace` | Appends full stack traces to deprecation entries. |

---

### 10.2 Remote Centralized Logging & Slack Alerts

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `LOG_SLACK_WEBHOOK_URL` | `string` | `null` | `logging.channels.slack.url` | Slack Incoming Webhook URL for posting high-severity alerts. |
| `LOG_SLACK_USERNAME` | `string` | *(app name)* | `logging.channels.slack.username` | Sender username displayed on Slack alert messages. |
| `LOG_SLACK_EMOJI` | `string` | `:boom:` | `logging.channels.slack.emoji` | Display icon on Slack alert messages. |
| `LOG_PAPERTRAIL_HANDLER` | `string` | `SyslogUdpHandler` | `logging.channels.papertrail.handler` | Network handler class for Papertrail log ingestion. |
| `PAPERTRAIL_URL` | `string` | `null` | `logging.channels.papertrail.handler_with.host` | Papertrail syslog collector hostname. |
| `PAPERTRAIL_PORT` | `integer` | `null` | `logging.channels.papertrail.handler_with.port` | Papertrail syslog collector port. |

---

## 11. Mail & Notification Transports

Manages transactional emails (password resets, voicemail notifications with audio attachments, fax delivery, system alert notices).

### 11.1 SMTP & Standard Mail Transports

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `MAIL_MAILER` | `string` | `log` | `mail.default` | Active email transport (`smtp`, `sendmail`, `mailgun`, `postmark`, `resend`, `log`, `array`). In development, `log` writes emails to `storage/logs/laravel.log`. |
| `MAIL_HOST` | `string` | `127.0.0.1` | `mail.mailers.smtp.host` | Outbound SMTP server hostname. |
| `MAIL_PORT` | `integer` | `2525` | `mail.mailers.smtp.port` | Outbound SMTP server port (standard ports: `587` for STARTTLS, `465` for TLS). |
| `MAIL_USERNAME` | `string` | `null` | `mail.mailers.smtp.username` | Outbound SMTP authentication username. |
| `MAIL_PASSWORD` | `string` | `null` | `mail.mailers.smtp.password` | **CRITICAL SECRET**: Outbound SMTP authentication password. |
| `MAIL_SCHEME` | `string` | `null` | `mail.mailers.smtp.scheme` | Transport encryption scheme (`tls`, `ssl`). |
| `MAIL_FROM_ADDRESS` | `string` | `hello@example.com` | `mail.from.address` | Sender email address appearing on all outgoing PBX notification emails. |
| `MAIL_FROM_NAME` | `string` | *(app name)* | `mail.from.name` | Display name accompanying the sender email address. |
| `MAIL_EHLO_DOMAIN` | `string` | *(host)* | `mail.mailers.smtp.local_domain` | Fully qualified domain name used in SMTP `EHLO`/`HELO` greetings. |
| `MAIL_URL` | `string` | `null` | `mail.mailers.smtp.url` | Consolidated SMTP connection URL string. |
| `MAIL_SENDMAIL_PATH` | `string` | `/usr/sbin/sendmail -bs -i` | `mail.mailers.sendmail.path` | Binary execution path when using local sendmail/postfix delivery. |
| `MAIL_LOG_CHANNEL` | `string` | `null` | `mail.mailers.log.channel` | Dedicated log channel mapped when `MAIL_MAILER=log`. |

---

### 11.2 Cloud Mail & Webhook Notifications

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `POSTMARK_API_KEY` | `string` | `null` | `services.postmark.token` | Server API token for Postmark transactional delivery. |
| `POSTMARK_MESSAGE_STREAM_ID` | `string` | `null` | `mail.mailers.postmark.message_stream_id` | Postmark message stream identifier. |
| `RESEND_API_KEY` | `string` | `null` | `services.resend.key` | API access key for Resend transactional email transport. |
| `SLACK_BOT_USER_OAUTH_TOKEN`| `string` | `null` | `services.slack.notifications.bot_user_oauth_token` | Slack Bot OAuth token for posting alerts to administrative channels. |
| `SLACK_BOT_USER_DEFAULT_CHANNEL` | `string` | `null` | `services.slack.notifications.channel` | Default Slack channel ID (e.g. `#pbx-alerts`) receiving notifications. |

---

## 12. SIP Device Auto-Provisioning

Governs zero-touch hardware provisioning for IP phones (Yealink, Polycom, Cisco, Grandstream) requesting XML configurations over HTTP/HTTPS.

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `PROVISIONING_ENABLED` | `boolean` | `false` | `provisioning.enabled` | Master gate for phone auto-provisioning. When `false`, all provisioning endpoints reject incoming requests. |
| `PROVISIONING_CIDR` | `string` | `null` | `provisioning.cidr` | **NETWORK PERIMETER GUARD**: Comma-separated list of allowed IP subnets (CIDRs, e.g. `192.168.1.0/24,10.10.0.0/16`) permitted to request phone configurations. Requests outside these subnets are immediately dropped. |
| `PROVISIONING_HTTP_AUTH_USERNAME`| `string`| `null` | `provisioning.http_auth.username`| HTTP Basic or Digest authentication username enforced on provisioning requests. |
| `PROVISIONING_HTTP_AUTH_PASSWORD`| `string`| `null` | `provisioning.http_auth.password`| **CRITICAL SECRET**: HTTP authentication password enforced on provisioning requests. |

---

## 13. System Updater Subsystem

Manages git-based self-updating, preflight checks, database migrations, asset rebuilding, and automated rollbacks.

| Variable | Type | Default | Config Key | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `GIT_UPDATE_STEP_TIMEOUT_SECONDS` | `integer` | `300` | `git-update.step_timeout_seconds` | Execution timeout in seconds (default 5 minutes) allocated to individual updater pipeline operations (`git pull`, `composer install`, `php artisan migrate`). |

---

## 14. Installer CLI, Build Pipeline & Testing

Variables utilized by installation scripts, frontend build tooling, and test runners.

### 14.1 Installer Credentials & Development Modes

| Variable | Type | Default | Consumed By | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `FSPBX_ADMIN_USERNAME` | `string` | `admin` | `ConfigureInitialAdminCommand` | Initial super-administrator username seeded during headless installer execution. |
| `FSPBX_ADMIN_PASSWORD` | `string` | `null` | `ConfigureInitialAdminCommand` | **CRITICAL SECRET**: Initial super-administrator password seeded during headless installer execution. |
| `FSPBX_DEVELOPMENT_MODE` | `boolean` | `false` | Installer scripts (`install.sh`) | When `true`, configures the server for local development with verbose debugging, developer tools, and hot reloading. |
| `PHP_CLI_SERVER_WORKERS` | `integer` | `4` | `php artisan serve` | Worker process concurrency count when running PHP's built-in development CLI server. |

---

### 14.2 Vite Frontend Asset Pipeline

Vite environment variables prefixed with `VITE_` are compiled directly into client-side JavaScript bundles during `npm run build`.

| Variable | Type | Default | Consumed By | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `VITE_APP_NAME` | `string` | `"${APP_NAME}"` | `resources/js/app.js` | Application name referenced in browser title and UI elements. |
| `VITE_REVERB_APP_KEY` | `string` | `"${REVERB_APP_KEY}"` | `resources/js/echo.js` | Public Reverb key passed to client-side Laravel Echo instance. |
| `VITE_REVERB_HOST` | `string` | `""` | `resources/js/echo.js` | Hostname where client browsers establish WebSocket connections to Reverb. In production, this matches the public PBX domain or reverse proxy. |
| `VITE_REVERB_PORT` | `integer` | `""` | `resources/js/echo.js` | Public WebSocket port (e.g. `8080`, or `443` when proxied via Nginx over HTTPS/WSS). |
| `VITE_REVERB_SCHEME` | `string` | `"http"` | `resources/js/echo.js` | Protocol scheme used by client browsers (`http` or `https` / `ws` or `wss`). |

---

### 14.3 Documentation Testing Harness

| Variable | Type | Default | Consumed By | Description & Security Implications |
| :--- | :--- | :--- | :--- | :--- |
| `TALLPBX_CAPTURE_DOCS` | `boolean` | `false` | Pest Browser Tests | When set to `1` or `true`, instructs Pest Playwright browser tests to automatically capture high-resolution Light and Dark mode UI screenshots for documentation (`docs/ui-tour.md`). |

---

## 15. Production Sizing & Recommended Settings Reference

### Minimal / Single-Server PBX (< 50 Extensions)
```ini
APP_ENV=production
APP_DEBUG=false
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=database
BROADCAST_CONNECTION=reverb

# FreeSWITCH Engine
FS_LOG_LEVEL=warning
FS_SESSIONS_PER_SECOND=30
FS_XML_HANDLER_AUTH=true
FS_XML_HANDLER_CACHE_TTL=5
FS_ESL_PASSWORD=<generate-random-secret>
FS_XML_HANDLER_TOKEN=<generate-random-secret>
```

### High-Throughput PBX (100–1,000+ Extensions, Call Centers)
```ini
APP_ENV=production
APP_DEBUG=false
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_SECURE_COOKIE=true
QUEUE_CONNECTION=redis
BROADCAST_CONNECTION=reverb

# Redis Connection Persistence
REDIS_CLIENT=phpredis
REDIS_PERSISTENT=true
REDIS_CACHE_DB=1

# FreeSWITCH Engine Tuning
FS_LOG_LEVEL=warning
FS_SESSIONS_PER_SECOND=120
FS_SOFIA_INBOUND_REG_THREAD=true
FS_SOFIA_MAX_REG_THREADS=16
FS_ESL_BUFFER_SIZE=5000

# XML Handler Caching
FS_XML_HANDLER_AUTH=true
FS_XML_HANDLER_CACHE_TTL=10
FS_XML_HANDLER_CACHE_STORE=redis
FS_XML_HANDLER_ROUTING_VERSION_STORE=redis
FS_XML_HANDLER_THROTTLE_LIMIT=2000
```
