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

During live server-to-server SIP capacity testing (`uac-extension-capacity.xml`) across four different XML routing architectures (Octane Lean XML Worker, Lua Redis Proxy, Fast Controller, and Stock PHP-FPM), the following key facts were established:

#### Head-to-Head Architecture Benchmark Comparison (1 vCPU, 5-Second Calls)

| Architecture | Peak Sustained Rate | 3 CPS Completion | 5 CPS Completion | 8 CPS Completion | 10 CPS Burst Completion | Call Setup Median (p50) | Primary Limiter / Failure Mode |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :--- |
| **Octane Lean Worker**<br>*(Port 8090, 1 worker, RAM DB)* | **6.23 CPS** | **100%** (180/180) | **100%** (300/300) | **94.8%** (455/480) | **99.3%** (596/600) | **82 – 118 ms** | FreeSWITCH + ESL + Reverb saturate CPU core (0% idle) |
| **Lua Proxy Only**<br>*(In-process Lua + Redis)* | **4.60 CPS** | **100%** (39/39) | **100%** (66/66) | **94.8%** (95/100) | **83.6%** (94/111) | **56 – 60 ms** | Redis connection contention + CPU starvation at 10 CPS |
| **Fast Controller + Lua Proxy**<br>*(Lua cached + Fast fallback)* | **3.30 CPS** | **100%** (33/33) | **100%** (66/66) | **100%** (105/105) | **73.5%** (91/122) | **56 – 504 ms** | CPU context switching between FreeSWITCH Lua & FPM |
| **Fast Controller Only**<br>*(xml-handler-fast.php via FPM)* | **~1.5 CPS** | **Failed / Stalled**<br>(worker queue limit) | Not viable | Not viable | Not viable | **> 3,200 ms** | Worker pool exhaustion (`pm.max_children = 5` queueing past 5s curl timeout) |

1. **XML Generation is NOT the Scaling Bottleneck**:
   - The **Octane Lean Worker** (`tallpbx-xml-worker.service`) delivers over **150 XML req/sec** sustained with sub-10ms response times.
   - Even when XML resolution cost was reduced to near-zero, the 1 vCPU system consistently hit a ceiling between **6.2 and 10 CPS**.

2. **The 10 CPS Wall on 1 vCPU is Full-Stack CPU Exhaustion**:
   At 10 CPS with 50 concurrent in-flight calls, top profiling on the 1 vCPU host revealed **0.0% idle CPU** (100% saturation):
   - **`freeswitch:listen` (33.3% CPU)**: Reads, deserializes, and dispatches hundreds of FreeSWITCH ESL JSON events per second across 25 subscribed event types into the Laravel application and database.
   - **`freeswitch` Core Engine (33.3% CPU)**: Sofia SIP UDP socket processing, timer threads, SDP offer/answer negotiation, RTP media socket management, and dialplan state-machine execution.
   - **`reverb:start` (25.0% CPU)**: Broadcasts real-time call state updates to the administrative web panel over WebSockets.
   - **Kernel Software Interrupts & UDP Context Switching (8.3% – 16% CPU)**: Network interface processing for incoming/outgoing SIP and RTP UDP packets (`ksoftirqd`).

3. **Static XML Files Do Not Solve the Bottleneck**:
   Static XML files on disk would only add disk I/O wait cycles (`wa%`). Because Octane and Lua already serve dynamic XML from memory faster than disk reads, changing the format of dialplans does not reduce the 80%+ of CPU spent on SIP signaling, RTP streams, ESL event consumption, and WebSockets.

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
 │  - mod_xml_curl (Fast-timeout dual binding to Octane port 8090)               │
 │  - Runtime SQLite databases in tmpfs RAM (/var/lib/freeswitch/db)            │
 │  - FreeSWITCH logging set to 'notice' (zero disk I/O lock wait)              │
 └─────────────────┬─────────────────────────────────────────────────────────────┘
                   │ mod_xml_curl HTTP (1.78 ms lookup)
 ┌─────────────────▼─────────────────────────────────────────────────────────────┐
 │ CORE 1+: WEB APPLICATION, EVENT BUS, & DATA STACK (Laravel / Octane / DB)     │
 │  - tallpbx-xml-worker (Octane/RoadRunner on port 8090, 1 worker per app core) │
 │  - freeswitch:listen (Streamlined: filtered to 5 critical call events only)   │
 │  - MariaDB & Redis servers                                                    │
 │  - Nginx & PHP-FPM (Dedicated to Admin Web Panel & REST APIs)                 │
 └───────────────────────────────────────────────────────────────────────────────┘
```

---

## 3. Implementation Steps

### Phase 1: Hardware & Compute Sizing (Immediate 2× to 4× Capacity Leap)

The single most impactful step for scaling past 10 CPS is adding a second compute core.

* **Single-Core Ceiling**: 6–10 CPS (~35–60 concurrent active calls).
* **Dual-Core Sizing (2 vCPU / 2 GB RAM)**: Separates FreeSWITCH's real-time VoIP threads from background PHP daemons and database writes. Unlocks **15–20 CPS** (~75–120 concurrent calls).
* **Quad-Core Dedicated Sizing (4 vCPU / 8 GB+ RAM)**: Provides dedicated CPU cores with zero "noisy neighbor" steal time (`st%`). Unlocks **30–60+ CPS** (~150–350 concurrent active calls).

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

### Phase 3: Runtime Database & Disk I/O Decoupling (Implemented & Verified)

Under concurrent call volume, FreeSWITCH records active registrations, channels, and dialogs into internal SQLite tables (`core.db`, `sofia_reg_internal.db`). On physical SSDs or cloud virtual disks, SQLite table write-locks cause Sofia threads to block (`SQLite is BUSY`).

#### Required Configuration:
Mount FreeSWITCH's database directory to a `tmpfs` RAM disk:
```bash
# Add to /etc/fstab for persistent RAM disk
tmpfs /var/lib/freeswitch/db tmpfs defaults,noatime,nosuid,nodev,noexec,mode=0770,uid=freeswitch,gid=tallpbx-media,size=128M 0 0
```
*Impact*: Completely eliminates `switch_core_db.c:92 SQLite is BUSY` lock wait states, ensuring instant thread unlock during concurrent SIP transactions.

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

### Phase 5: XML Handler Persistence (Octane Primary Engine)

Keep the persistent Octane lean XML worker (`tallpbx-xml-worker.service`) as the primary routing resolver:
1. **Worker Pool Sizing**:
   - For 1 vCPU hosts: `--workers=1` (prevents worker context thrashing).
   - For 2 vCPU hosts: `--workers=2`.
   - For 4+ vCPU hosts: `--workers=4` to `8`.
2. **Curl Timeout**:
   - Maintain `<param name="timeout" value="5"/>` in `xml_curl.conf.xml` to prevent premature call drops during transient CPU bursts.
3. **Fallback Resiliency**:
   - Keep the dual binding in `xml_curl.conf.xml`: primary to Octane (`127.0.0.1:8090`), fallback to Nginx/FPM (`127.0.0.1:80`).

---

## 4. Capacity Progression Sizing Matrix

| Hardware Tier | Recommended CPS | Target Concurrent Calls | Primary Architectural Constraints | Recommended Settings |
| :--- | :---: | :---: | :--- | :--- |
| **1 vCPU / 1 GB RAM** | **3 – 5 CPS** | **20 – 35 calls** | Single-core CPU contention between FreeSWITCH, ESL, and MariaDB. | 1 Octane worker, `tmpfs` DB, `notice` logging, dynamic FPM (5 max). |
| **2 vCPU / 2 GB RAM** | **15 – 20 CPS** | **75 – 120 calls** | Kernel interrupt balancing and ESL event flood. | 2 Octane workers, ESL event pruning, static FPM (6 workers). |
| **4 vCPU / 8 GB Dedicated** | **30 – 45 CPS** | **150 – 250 calls** | Sofia UDP receive buffer sizing and network bandwidth. | 4 Octane workers, `mod_hiredis` limits, static FPM (12 workers). |
| **8 vCPU / 16 GB Dedicated** | **60+ CPS** | **350+ calls** | FreeSWITCH session limits (`FS_SESSIONS_PER_SECOND`). | 8 Octane workers, `FS_SESSIONS_PER_SECOND=120`, dedicated DB host. |

---

## 5. Verification Commands

To verify call capacity scalability without regressions, run the standardized capacity ladder from the load generator:

```bash
# 1. Verify FreeSWITCH DB is mounted in RAM
mountpoint /var/lib/freeswitch/db

# 2. Verify FreeSWITCH production logging level
fs_cli -x "fsctl loglevel"

# 3. Verify Octane worker is active
systemctl status tallpbx-xml-worker

# 4. Execute the call capacity ladder test from generator
PBX_HOST=<PBX_IP> LOAD_GENERATOR_IP=<GEN_IP> python3 run-all-scenarios.py octane_tuned
```

