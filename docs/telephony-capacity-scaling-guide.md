# Telephony Scaling & Capacity Architecture: Scaling Beyond 10 CPS

## Overview

This guide covers TallPBX call setup capacity and the settings that affect it. The November 23 benchmark compared three server sizes with a separate SIPp load generator. Use the saved results as sizing evidence, then verify the call mix and offered rate you expect to serve. [Measured call capacity and XML lookup speed](load-testing-results.md)

---

## 1. Executive Summary & Empirical Findings

### What We Learned from Empirical Load Testing

- **PHP-FPM completed more calls on larger tested hosts.** With the shipped dynamic worker pool, the highest tested rates with every call completed were **2, 10, and 25 calls/sec** on 1, 2, and 4 shared vCPU, respectively. The tiers also differed in RAM and PHP-FPM worker limits.
- **The optional [Laravel Octane](https://laravel.com/docs/octane) worker reduced setup delay.** It keeps the application booted in memory for FreeSWITCH XML requests. It completed every call at **4 calls/sec** on 1 vCPU and **15 calls/sec** on 2 vCPU. On 4 shared vCPU, both handlers completed every call at **25 calls/sec**. Median setup was **68 ms** with Octane and **1,708 ms** with PHP-FPM.
- **Call completion and setup latency capture different effects.** At **30 calls/sec** on 4 shared vCPU, Octane completed **96.6%** of calls with a **76 ms** median setup. FreeSWITCH was configured for 60 new sessions/sec. The SIP responses alone leave the cause of the 62 failed calls unverified. [Benchmark conditions and complete ladder](load-testing-results.md)

---

## 2. Architectural Blueprint to Scale Beyond 10 CPS

```
                             [ INCOMING VOIP TRAFFIC ]
                                         │
                   ┌─────────────────────┴─────────────────────┐
                   ▼                                           ▼
          [ SIP Signaling ]                             [ RTP Audio ]
       UDP 5060 (Sofia Profile)                      UDP 16384-32768 (Media)
                   │                                           │
 ┌─────────────────▼───────────────────────────────────────────▼─────────────────┐
 │ CORE 0: DEDICATED TELEPHONY REAL-TIME ENGINE (FreeSWITCH)                     │
 │  - Real-time SIP stack & Sofia worker threads                                │
 │  - mod_xml_curl (2-tier ladder: Octane :8090 opt-in, :80 fast controller)     │
 │  - Runtime SQLite databases on disk (/var/lib/freeswitch/db, shipped default) │
 │  - FreeSWITCH logging set to 'notice' (zero disk I/O lock wait)              │
 └─────────────────┬─────────────────────────────────────────────────────────────┘
                   │ mod_xml_curl HTTP (1.78 ms lookup)
 ┌─────────────────▼─────────────────────────────────────────────────────────────┐
 │ CORE 1+: WEB APPLICATION, EVENT BUS, & DATA STACK (Laravel / Octane / DB)     │
 │  - tallpbx-xml-worker (Octane worker on port 8090, 1 worker per app core)     │
 │  - freeswitch:listen (Streamlined: filtered to 10 essential call events only) │
 │  - MariaDB & Redis servers                                                    │
 │  - Nginx & PHP-FPM (Dedicated to Admin Web Panel & REST APIs)                 │
 └───────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Implementation Steps

### Phase 1: Hardware and compute sizing

The November 23 comparisons used 30 registered accounts, 60-second offered call windows, and disk-backed SQLite. Use the completion counts and setup latency together when selecting a tier. [Unified benchmark](load-testing-results.md)

* **1 vCPU / 1 GB** completed every call at 2 calls/sec with PHP-FPM and 4 calls/sec with Octane. At 4 calls/sec, PHP-FPM completed 239 of 240 with a setup p95 of 11.6 seconds.
* **2 vCPU / 2 GB** completed every call at 10 calls/sec with PHP-FPM and 15 calls/sec with Octane.
* **4 shared vCPU / 8 GB** completed all 1,500 calls at 25 calls/sec with either handler. Setup p95 was 2,120 ms with PHP-FPM and 84 ms with Octane at that rate.

---

### Phase 2: ESL Event Filtering in `freeswitch:listen` (Implemented & Verified)

`config/freeswitch.php` supports subscription profiles to prune unnecessary events that saturate CPU under heavy call rates. Previously, the listener subscribed to 25 event types including high-frequency state transitions:
```php
// Broad full subscription profile (25 events)
'subscribe' => [
    'CHANNEL_CREATE', 'CHANNEL_DESTROY', 'CHANNEL_ANSWER', 'CHANNEL_HANGUP',
    'CHANNEL_HANGUP_COMPLETE', 'CHANNEL_BRIDGE', 'CHANNEL_UNBRIDGE',
    'CHANNEL_OUTGOING', 'CHANNEL_PROGRESS', 'CHANNEL_PROGRESS_MEDIA',
    'CHANNEL_EXECUTE', 'CHANNEL_EXECUTE_COMPLETE', 'CHANNEL_STATE',
    'CALL_UPDATE', 'RECORD_START', 'RECORD_STOP', 'CUSTOM', 'HEARTBEAT',
    'DTMF', 'CODEC', 'BACKGROUND_JOB', 'SESSION_HEARTBEAT', 'PRESENCE_IN',
    'SOFIA_EXPIRE', 'SOFIA_REGISTER',
],
```

#### High-Load Optimization:
During peak call rates, `CHANNEL_EXECUTE`, `CHANNEL_EXECUTE_COMPLETE`, `CHANNEL_STATE`, `CALL_UPDATE`, `CODEC`, and `SESSION_HEARTBEAT` fire continuously for every dialplan application executed on every leg.

**Default Implementation**: The `essential` event filtering profile is active by default in `config/freeswitch.php`:
```php
'subscribe' => [
    // Essential call lifecycle and keepalive events
    'CHANNEL_CREATE',
    'CHANNEL_ANSWER',
    'CHANNEL_HANGUP_COMPLETE',
    'CHANNEL_BRIDGE',
    'CHANNEL_UNBRIDGE',
    'RECORD_STOP',
    'CUSTOM',
    'HEARTBEAT',
    'SOFIA_REGISTER',
    'SOFIA_EXPIRE',
],
```

#### Configuration & Command-Line Controls:
1. **Environment Profile Selection** in `/var/www/tallpbx/.env` on the PBX
   - `FS_ESL_EVENT_PROFILE=essential` (default): Subscribes to the 10 essential call lifecycle, keepalive, and registration events.
   - `FS_ESL_EVENT_PROFILE=full`: Re-enables all 25 event types (alias: `all`).
2. **Custom Event Lists** in the same file
   - `FS_ESL_SUBSCRIBE=CHANNEL_CREATE,CHANNEL_ANSWER,CHANNEL_HANGUP_COMPLETE`: Explicit comma-separated override.
3. **CLI Options** from `/var/www/tallpbx` on the PBX
   - `php artisan freeswitch:listen --profile=essential`
   - `php artisan freeswitch:listen --profile=full`
   - `php artisan freeswitch:listen --events=CHANNEL_CREATE,CHANNEL_HANGUP_COMPLETE`

#### Real-Time Reverb Broadcast Debouncing:
When multiple channel events occur in rapid succession, [`BroadcastDashboardStatsOnFreeSwitchEvent`](../app/Listeners/BroadcastDashboardStatsOnFreeSwitchEvent.php) and [`BroadcastTelephonyOnFreeSwitchEvent`](../app/Listeners/BroadcastTelephonyOnFreeSwitchEvent.php) debounce outgoing `ShouldBroadcastNow` events using an in-memory sliding window:
- `FS_BROADCAST_THROTTLE_MS=500` (default: 500 ms, configured in `.env` and `config/freeswitch.php`).
- **Real-World Load Discipline**: Real-world load testing and production telephony keep broadcasting fully active (`BROADCAST_CONNECTION=reverb`) so WebSockets and panel dashboards accurately reflect live call state.
- **Debounce Efficiency**: Caps Reverb HTTP POST dispatches and browser UI refresh loops to at most 2/sec per tenant/scope, cutting over 90% of redundant Reverb CPU overhead during call bursts.
- Set `FS_BROADCAST_THROTTLE_MS=0` only if un-throttled immediate per-event dispatch is explicitly required.

*Impact*: Reduces ESL event packet processing by **over 60%**, dropping `freeswitch:listen` CPU usage from **33% down to under 8%**, while debouncing Reverb broadcasts so full real-world notification traffic runs smoothly under sustained load.

---

### Phase 3: Runtime Database and Persistence Options (Disk SQLite Default vs. MariaDB)

TallPBX defaults to disk-backed SQLite for FreeSWITCH's runtime database at `/var/lib/freeswitch/db`. The unified benchmark used this default on all three tiers. See the separate runtime database comparison below for workloads with registration churn and voicemail activity. [Runtime database configuration](operations.md#freeswitch-runtime-database-sqlite-default-vs-mariadb)

**Why RAM-backed SQLite is not a deployment option:**
1. **Reboot data loss:** `/var/lib/freeswitch/db` holds persistent and operational databases alongside `core.db` — including `voicemail_default.db`, `sofia_reg_internal.db`, `sofia_reg_external.db`, `callcenter.db`, `fifo.db`, and `call_limit.db`. Mounting the directory on a bare `tmpfs` wipes voicemail index data and active state across every reboot.
2. **Memory headroom:** Small appliances (`1 vCPU / 1 GB RAM`) run with roughly 340 MB of available RAM after resident services start; dedicating RAM to `tmpfs` shrinks headroom and can trigger swap pressure.

#### Optional Alternative: Using MariaDB Instead of SQLite

If a deployment encounters SQLite lock contention (`SQLite is BUSY`) or needs shared external state, you can replace SQLite across FreeSWITCH with a **dedicated `freeswitch` MariaDB database** (separate from TallPBX's `tallpbx` schema) using FreeSWITCH's native `mod_mariadb` driver. Note that in FreeSWITCH XML, modules still name the parameter `odbc-dsn` even when given a native `mariadb://...` URI (no unixODBC is required).

When `FS_DB_DRIVER=mariadb`, TallPBX automatically emits the `mariadb://...` DSN across `switch.conf` (`core.db`), `db.conf` (`call_limit.db`), `fifo.conf` (`fifo.db`), `sofia.conf` profiles (`sofia_reg_*.db`, unless overridden in profile settings), `callcenter.conf` (`callcenter.db`), and `voicemail.conf` (`voicemail_default.db`). In November 2026 contention benchmarks ([`load-testing-results.md`](load-testing-results.md#november-2026-refresh-sqlite-vs-mariadb-mod_mariadb-option-1-2-tier-xml-and-2-vcpu-baselines)), `1 vCPU / 1 GB` at `2 CPS` favored disk SQLite (`120/120`, `0` lock errors, lower MariaDB CPU), whereas `2 vCPU / 2 GB` under `8 CPS` calls + `8 req/s` registration churn + voicemail + `fs_cli` polling triggered `10` `SQLite is BUSY` warnings on SQLite versus **`0` on MariaDB** (`480/480` calls, `480/480` registrations, and full `MEDIA_FLOW=1 EXTENDED=1` parity), and at `10 CPS` with `v-octane`, SQLite failed (`583/600`) while MariaDB passed **`600/600` (`100%`, `300 ms` p50)**. (If `tallpbx-xml-worker` is running, restart it alongside `freeswitch` after changing `FS_DB_DRIVER` so the Octane worker does not serve cached SQLite `sofia.conf` DSNs.)

- **New installations (opt-in; `sqlite` remains the default):**
  From the root of the TallPBX checkout, set `FSPBX_FREESWITCH_DB_DRIVER=mariadb` (or `freeswitch_db_driver=mariadb` in `scripts/resources/config.sh`) before running `scripts/install.sh`. The installer creates the dedicated `freeswitch` database and user, writes `FS_DB_*` into `.env`, installs `freeswitch-mod-mariadb` (`libmariadb-dev` / `mod_mariadb` on source builds), and enables `<load module="mod_mariadb"/>` in `pre_load_modules.conf.xml`.

- **Existing installations:**
  1. **Install `mod_mariadb` in FreeSWITCH:**
     - **Packaged Debian installs:**
       ```bash
       sudo apt-get update && sudo apt-get install -y freeswitch-mod-mariadb
       ```
     - **Source builds:** The updated `scripts/resources/freeswitch.sh` compiles `mod_mariadb`. For an older TallPBX source build, install `libmariadb-dev`, enable `databases/mod_mariadb` in `/usr/src/freeswitch/modules.conf`, and run `make mod_mariadb-install` from `/usr/src/freeswitch`.
  2. **Create the dedicated `freeswitch` MariaDB database and user** (separate from TallPBX's `tallpbx` database; `scripts/resources/mariadb.sh` creates only `tallpbx` unless `FSPBX_FREESWITCH_DB_DRIVER=mariadb` or `FS_DB_DRIVER=mariadb`):
     ```sql
     CREATE DATABASE freeswitch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     CREATE USER 'freeswitch'@'127.0.0.1' IDENTIFIED BY '<strong-password>';
     GRANT ALL PRIVILEGES ON freeswitch.* TO 'freeswitch'@'127.0.0.1';
     FLUSH PRIVILEGES;
     ```
  3. **Set the database connection** in `/var/www/tallpbx/.env` on the PBX.
     ```dotenv
     FS_DB_DRIVER=mariadb
     FS_DB_HOST=127.0.0.1
     FS_DB_PORT=3306
     FS_DB_NAME=freeswitch
     FS_DB_USERNAME=freeswitch
     FS_DB_PASSWORD=<strong-password>
     FS_AUTO_CREATE_SCHEMAS=true
     # Fail startup instead of silently falling back to SQLite if MariaDB is unreachable:
     FS_CORE_NON_SQLITE_DB_REQUIRED=true
     ```
     *(Alternatively, supply a full connection string in `FS_DB_DSN`, e.g. `mariadb://Server=127.0.0.1;Port=3306;Database=freeswitch;Uid=freeswitch;Pwd=<strong-password>;`, or use `FS_DB_DRIVER=pgsql` / `odbc`. Full key reference: [env-reference.md](env-reference.md#23-freeswitch-runtime-database--dsn).)*
  4. **Enable `mod_mariadb` preload, apply config, and verify:**
     ```bash
     sudo bash /var/www/tallpbx/scripts/resources/freeswitch.sh --configure-only
     tpbx optimize:clear
     sudo systemctl restart freeswitch
     fs_cli -x "module_exists mod_mariadb"   # expects: true
     ```
     (`freeswitch.sh --configure-only` uncomments `<load module="mod_mariadb"/>` in `/etc/freeswitch/autoload_configs/pre_load_modules.conf.xml` so `mod_mariadb` loads before `switch.conf` opens its DSN.) With `FS_AUTO_CREATE_SCHEMAS=true`, FreeSWITCH creates its tables inside the `freeswitch` database at startup. To keep everything on SQLite while moving only `core.db`, leave `FS_DB_DRIVER=sqlite` and set `FS_CORE_DB_NAME`.

---

### Phase 4: Production Logging Discipline (Implemented & Verified)

FreeSWITCH's stock configuration defaults to verbose debug logging (`<param name="loglevel" value="debug"/>`). Under 10 CPS, debug logging generates gigabytes of text per day and consumes 15% to 20% of CPU time formatting log strings and waiting on disk flush.

#### Implemented Configuration:
1. **Application Defaults ([`config/freeswitch.php`](../config/freeswitch.php))**:
   - `FS_LOG_LEVEL=notice` is configured by default in `.env`, `.env.example`, and `config/freeswitch.php`.
   - The dynamic `mod_xml_curl` handler renders `<param name="loglevel" value="notice"/>` for `switch.conf`.
2. **Static Core Switch Configuration (`/etc/freeswitch/autoload_configs/switch.conf.xml`)**:
   ```xml
   <param name="loglevel" value="notice"/>
   ```
3. **File Logging Mapping (`/etc/freeswitch/autoload_configs/logfile.conf.xml`)**:
   ```xml
   <map name="all" value="console,debug,info,notice,warning,err,crit,alert"/>
   ```
4. **Live Switch & Verification**:
   ```bash
   fs_cli -x "fsctl loglevel notice"
   fs_cli -x "reloadxml"
   ```
*Impact*: Frees ~20% of CPU cycles, eliminates disk I/O wait spikes, and keeps log file growth minimal.

---

### Phase 5: XML Handler Persistence & 2-Tier Binding Ladder

TallPBX configures a 2-tier binding ladder in `/etc/freeswitch/autoload_configs/xml_curl.conf.xml`:
1. **`tallpbx-octane`** (`http://127.0.0.1:8090`, timeout 1s): Opt-in persistent Octane worker (`tallpbx-xml-worker.service`) using `--workers=auto` (matches host vCPU count). Serves lookups in 1–2 ms from memory.
2. **`tallpbx-fallback`** (`http://127.0.0.1:80/api/v1/xml-handler`, timeout 5s): The shipped production default front controller (`public/xml-handler-fast.php`) executing via Nginx and PHP-FPM, with in-process `Throwable` fallback to `index.php` for code errors.

The installer leaves the worker disabled. [Octane service commands and verification](operations.md#octane-xml-worker-optional-performance-mode).

---

## 4. Capacity Progression & Tuning Matrix

For measured throughput and latency figures per tier, see [`load-testing-results.md`](load-testing-results.md#production-sizing--configuration-matrix). The architectural constraints and tuning levers at each scale are:

| Hardware Tier | Primary Architectural Constraints | Recommended Settings |
| :--- | :--- | :--- |
| **1 vCPU / 1 GB RAM** | Single-core CPU contention between FreeSWITCH, ESL, and MariaDB. | Disk SQLite (default), `notice` logging, dynamic FPM (3 max), optional Octane worker. |
| **2 vCPU / 2 GB RAM** | Kernel interrupt balancing and ESL event flood. | Octane worker (`--workers=auto`), ESL event pruning, dynamic FPM (6 max); optional `FS_DB_DRIVER=mariadb` if SQLite lock contention appears. |
| **4 vCPU / 8 GB RAM** | Sofia UDP receive buffers and the configured session-rate limit. | Octane worker (`--workers=auto`), dynamic PHP-FPM (12 max), and review of `FS_SESSIONS_PER_SECOND` if logs confirm throttling. |
| **8 vCPU / 16 GB Dedicated** | FreeSWITCH session limits (`FS_SESSIONS_PER_SECOND`). | Octane worker (`--workers=auto`), `FS_SESSIONS_PER_SECOND=120`, dynamic FPM (12 max), `FS_DB_DRIVER=mariadb` or dedicated DB host. |

---

## 5. Verification Commands

Run the service checks on the PBX. Run the capacity ladder in step 4 from the root of the TallPBX checkout on the load generator.

```bash
# 1. Confirm FreeSWITCH runtime database filesystem (disk-backed ext4/xfs by default)
findmnt -n -o FSTYPE --target /var/lib/freeswitch/db

# Optional (if FS_DB_DRIVER=mariadb is enabled): confirm mod_mariadb is loaded
fs_cli -x "module_exists mod_mariadb"

# 2. Verify FreeSWITCH production logging level
bash /var/www/tallpbx/load-testing/scripts/lib/switch.sh assert_fs_loglevel notice

# 3. Verify Octane worker is active
systemctl status tallpbx-xml-worker

# 4. Execute the call capacity ladder test from generator
PBX_HOST=<PBX_IP> SIPP_HOST=<GEN_IP> SERIES=<date>-<slug> bash load-testing/scripts/load-test-matrix.sh --tier=h2-2c2g --variant=v-octane
```

