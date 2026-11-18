# Telephony Scaling & Capacity Architecture: Scaling Beyond 10 CPS

## Overview

This document provides the authoritative implementation and engineering guide for scaling TallPBX telephony call setup capacity beyond **10 Calls per Second (CPS)** (~35 to 60 concurrent active calls). 

Based on empirical benchmarks conducted on a 1 vCPU / 1 GB RAM cloud host with a dedicated SIPp load generator, this document explains:
1. Why single-core PBX deployments encounter a physical CPU saturation boundary at ~10 CPS.
2. The specific subsystems responsible for the compute bottleneck.
3. The architectural implementation plan to unlock 20, 30, and 60+ CPS.

---

## 1. Executive Summary & Empirical Findings

### What We Learned from Empirical Load Testing

Empirical server-to-server SIP capacity testing established three core facts (for the complete tier tables, per-service CPU numbers, and historical static-pool runs, see [`load-testing-results.md`](load-testing-results.md)):

- **Shipped default capacity scales linearly with CPU cores:** With the shipped dynamic PHP-FPM pool (`pm.max_children` = `3` on 1 GB, `6` on 2 GB, `12` on 4 GB+) and fast XML/CDR entry points, TallPBX completes **5 calls/sec** (300/300) on 1 vCPU / 1 GB and **10 calls/sec** (600/600) on 2 vCPU / 2 GB.
- **XML generation is NOT the bottleneck:** The optional **Octane Lean Worker** (`tallpbx-xml-worker.service` on port 8090) serves 150+ XML req/sec and clears the PHP-FPM call-rate boundary on both 1 vCPU (6 calls/sec, 360/360) and 2 vCPU (12 calls/sec, 720/720). Even with near-zero XML latency, 1 vCPU still hits a hard core ceiling between ~6 and 10 CPS.
- **The 10 CPS wall on 1 vCPU is full-stack CPU exhaustion:**
   At 10 CPS with 50 concurrent in-flight calls, profiling on a 1 vCPU host revealed **0.0% idle CPU** (100% saturation):
   - **`freeswitch:listen` (33.3% CPU)**: Reads, deserializes, and dispatches hundreds of FreeSWITCH ESL JSON events per second across 25 subscribed event types into the Laravel application and database.
   - **`freeswitch` Core Engine (33.3% CPU)**: Sofia SIP UDP socket processing, timer threads, SDP offer/answer negotiation, RTP media socket management, and dialplan state-machine execution.
   - **`reverb:start` (25.0% CPU)**: Broadcasts real-time call state updates to the administrative web panel over WebSockets.
   - **Kernel Software Interrupts & UDP Context Switching (8.3% – 16% CPU)**: Network interface processing for incoming/outgoing SIP and RTP UDP packets (`ksoftirqd`).
- **Static XML files do not solve the bottleneck:**
   Static XML files on disk only add disk I/O wait cycles (`wa%`). Because Octane and memory caches already serve dynamic XML from memory faster than disk reads, changing the format of dialplans does not reduce the 80%+ of CPU spent on SIP signaling, RTP streams, ESL event consumption, and WebSockets.

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
 │  - tallpbx-xml-worker (Octane/RoadRunner on port 8090, 1 worker per app core) │
 │  - freeswitch:listen (Streamlined: filtered to 10 essential call events only) │
 │  - MariaDB & Redis servers                                                    │
 │  - Nginx & PHP-FPM (Dedicated to Admin Web Panel & REST APIs)                 │
 └───────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Implementation Steps

### Phase 1: Hardware & Compute Sizing (Immediate 2× to 4× Capacity Leap)

The single most impactful step for scaling past 10 CPS is adding a second compute core.

* **Single-Core Ceiling**: **5 calls/sec supported**, failing at 6 calls/sec (~30 concurrent active calls). Independent of pool shape: every worker count from one to five passes 5 and loses at least a third of the calls at 6.
* **Dual-Core Sizing (2 vCPU / 2 GB RAM)**: Separates FreeSWITCH's real-time VoIP threads from background PHP daemons and database writes. **Doubles the supported rate to 10 calls/sec** (measured, 600/600), failing at 12 calls/sec — not the 15–20 calls/sec previously projected. Worker count is second-order here: five pool shapes measured within 34–44 % at the boundary, while PHP-FPM alone demanded 2.25 cores.
* **Quad-Core Sizing (4 vCPU / 8 GB RAM, Cloud VPS / Appliance)**: Sized for **15–25 calls/sec sustained** under PHP-FPM baseline (`pm = dynamic`, dynamic ceiling 12 workers). The `30–60+ CPS` (~150–350 concurrent active calls) figure with the persistent Laravel Octane worker on port 8090 is a **projection, not a measurement** — no published run covers that rate.

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
1. **Environment Profile Selection**:
   - `FS_ESL_EVENT_PROFILE=essential` (default): Subscribes to the 10 essential call lifecycle, keepalive, and registration events.
   - `FS_ESL_EVENT_PROFILE=full`: Re-enables all 25 event types (alias: `all`).
2. **Custom Event Lists**:
   - `FS_ESL_SUBSCRIBE=CHANNEL_CREATE,CHANNEL_ANSWER,CHANNEL_HANGUP_COMPLETE`: Explicit comma-separated override.
3. **CLI Options**:
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

**Shipped Default — Disk-Backed SQLite:** TallPBX leaves FreeSWITCH's `/var/lib/freeswitch/db` directory on local disk with `FS_DB_DRIVER=sqlite` (see [../config/freeswitch.php](../config/freeswitch.php) and the [operations guide](operations.md#freeswitch-runtime-database-sqlite-default-vs-mariadb)). Current shipped benchmarks achieve **5 CPS** (1 vCPU) and **10 CPS** (2 vCPU) on disk-backed SQLite, bounded by full-stack CPU saturation rather than disk I/O. A historical disk-versus-RAM experiment did not raise the capacity ceiling.

**Why RAM-backed SQLite is not a deployment option:**
1. **Reboot data loss:** `/var/lib/freeswitch/db` holds persistent and operational databases alongside `core.db` — including `voicemail_default.db`, `sofia_reg_internal.db`, `sofia_reg_external.db`, `callcenter.db`, `fifo.db`, and `call_limit.db`. Mounting the directory on a bare `tmpfs` wipes voicemail index data and active state across every reboot.
2. **Memory headroom:** Small appliances (`1 vCPU / 1 GB RAM`) run with roughly 340 MB of available RAM after resident services start; dedicating RAM to `tmpfs` shrinks headroom and can trigger swap pressure.

#### Optional Alternative: Using MariaDB Instead of SQLite

If a deployment encounters SQLite lock contention (`SQLite is BUSY`) or needs shared external state, you can replace SQLite across FreeSWITCH with a **dedicated `freeswitch` MariaDB database** (separate from TallPBX's `tallpbx` schema) using FreeSWITCH's native `mod_mariadb` driver. Note that in FreeSWITCH XML, modules still name the parameter `odbc-dsn` even when given a native `mariadb://...` URI (no unixODBC is required).

When `FS_DB_DRIVER=mariadb`, TallPBX automatically emits the `mariadb://...` DSN across `switch.conf` (`core.db`), `db.conf` (`call_limit.db`), `fifo.conf` (`fifo.db`), `sofia.conf` profiles (`sofia_reg_*.db`, unless overridden in profile settings), `callcenter.conf` (`callcenter.db`), and `voicemail.conf` (`voicemail_default.db`). Load testing comparing `FS_DB_DRIVER=mariadb` against the disk-backed SQLite default has not yet been run and is scheduled for future benchmarking.

- **New installations (opt-in; `sqlite` remains the default):**
  Set `FSPBX_FREESWITCH_DB_DRIVER=mariadb` (or `freeswitch_db_driver=mariadb` in `scripts/resources/config.sh`) before running `scripts/install.sh`. The installer creates the dedicated `freeswitch` database and user, writes `FS_DB_*` into `.env`, installs `freeswitch-mod-mariadb` (`libmariadb-dev` / `mod_mariadb` on source builds), and enables `<load module="mod_mariadb"/>` in `pre_load_modules.conf.xml`.

- **Existing installations:**
  1. **Install `mod_mariadb` in FreeSWITCH:**
     - **Packaged Debian installs:**
       ```bash
       sudo apt-get update && sudo apt-get install -y freeswitch-mod-mariadb
       ```
     - **Source builds:** Already compiled if installed with updated `scripts/resources/freeswitch.sh` (`libmariadb-dev` + `databases/mod_mariadb`); otherwise install `libmariadb-dev`, enable `databases/mod_mariadb` in `modules.conf`, and run `make mod_mariadb-install`.
  2. **Create the dedicated `freeswitch` MariaDB database and user** (separate from TallPBX's `tallpbx` database; `scripts/resources/mariadb.sh` creates only `tallpbx` unless `FSPBX_FREESWITCH_DB_DRIVER=mariadb` or `FS_DB_DRIVER=mariadb`):
     ```sql
     CREATE DATABASE freeswitch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
     CREATE USER 'freeswitch'@'127.0.0.1' IDENTIFIED BY '<strong-password>';
     GRANT ALL PRIVILEGES ON freeswitch.* TO 'freeswitch'@'127.0.0.1';
     FLUSH PRIVILEGES;
     ```
  3. **Configure TallPBX `.env`:**
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

TallPBX configures a 2-tier binding ladder in `xml_curl.conf.xml`:
1. **`tallpbx-octane`** (`http://127.0.0.1:8090`, timeout 1s): Opt-in persistent RoadRunner/Octane lean XML worker (`tallpbx-xml-worker.service`) using `--workers=auto` (matches host vCPU count). Serves lookups in 1–2 ms from memory.
2. **`tallpbx-fallback`** (`http://127.0.0.1:80/api/v1/xml-handler`, timeout 5s): The shipped production default front controller (`public/xml-handler-fast.php`) executing via Nginx and PHP-FPM, with in-process `Throwable` fallback to `index.php` for code errors.

---

## 4. Capacity Progression & Tuning Matrix

For measured throughput and latency figures per tier, see [`load-testing-results.md`](load-testing-results.md#production-sizing--configuration-matrix). The architectural constraints and tuning levers at each scale are:

| Hardware Tier | Primary Architectural Constraints | Recommended Settings |
| :--- | :--- | :--- |
| **1 vCPU / 1 GB RAM** | Single-core CPU contention between FreeSWITCH, ESL, and MariaDB. | Disk SQLite (default), `notice` logging, dynamic FPM (3 max), optional Octane worker. |
| **2 vCPU / 2 GB RAM** | Kernel interrupt balancing and ESL event flood. | Octane worker (`--workers=auto`), ESL event pruning, dynamic FPM (6 max); optional `FS_DB_DRIVER=mariadb` if SQLite lock contention appears. |
| **4 vCPU / 8 GB RAM** | Sofia UDP receive buffer sizing and network bandwidth. | Octane worker (`--workers=auto`), `mod_hiredis` limits, dynamic FPM (12 max); optional `FS_DB_DRIVER=mariadb`. |
| **8 vCPU / 16 GB Dedicated** | FreeSWITCH session limits (`FS_SESSIONS_PER_SECOND`). | Octane worker (`--workers=auto`), `FS_SESSIONS_PER_SECOND=120`, dynamic FPM (12 max), `FS_DB_DRIVER=mariadb` or dedicated DB host. |

---

## 5. Verification Commands

To verify call capacity scalability without regressions, run the standardized capacity ladder from the load generator:

```bash
# 1. Confirm FreeSWITCH runtime database filesystem (disk-backed ext4/xfs by default)
findmnt -n -o FSTYPE --target /var/lib/freeswitch/db

# Optional (if FS_DB_DRIVER=mariadb is enabled): confirm mod_mariadb is loaded
fs_cli -x "module_exists mod_mariadb"

# 2. Verify FreeSWITCH production logging level
fs_cli -x "fsctl loglevel"

# 3. Verify Octane worker is active
systemctl status tallpbx-xml-worker

# 4. Execute the call capacity ladder test from generator
PBX_HOST=<PBX_IP> SIPP_HOST=<GEN_IP> SERIES=<date>-<slug> bash load-testing/scripts/load-test-matrix.sh --tier=h2-2c2g --variant=v-octane
```

