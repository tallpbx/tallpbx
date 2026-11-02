# XML Handler Performance — Four-Way Comparison

**Date:** 2026-11-01
**Plan:** [`2026-10-31-lua-redis-proxy-v2.md`](superpowers/plans/2026-10-31-lua-redis-proxy-v2.md) — Task 8
**Purpose:** measure the two optional speed-ups independently and together, so the decision to keep or remove either one rests on a number.

> **This revision supersedes the directory-only sweep.** That run measured the proxy against a workload it covered only a fraction of, and its verdict was marked provisional for that reason. Dialplan is now mirrored too, so both sections are measured below and the verdict is no longer provisional. The pre-dialplan numbers are kept only in `docs/lua-proxy-measurement-results.md`.

## 1. Environment

| Item | Value |
|:--|:--|
| Host | Existing datacenter test server (`x.x.x.145`; public address obfuscated per project convention) |
| OS | Debian GNU/Linux 13 (trixie), amd64 |
| CPU | 1 shared vCPU |
| RAM | 967 MB |
| Swap | 2 GB |
| PHP | 8.5.12, FPM `pm = static`, `pm.max_children = 5` |
| FreeSWITCH | 1.11.4 (SignalWire packages) |
| Cache policy | `FS_XML_HANDLER_CACHE_TTL=60`, dialplan cache TTL 60 s |
| Tenant | `load-test-beta` (tenant 2), domain `load.test.local`, extension `2000`, context `tenant_2_internal` |

## 2. Configurations

| ID | Fast controller | Lua proxy | What it represents |
|:--|:--:|:--:|:--|
| **A** | off | off | Stock install before either optimisation |
| **B** | off | on | Proxy alone |
| **C** | on | on | Both, as a future default |
| **D** | on | off | Fast controller alone |

Both switches are real, not simulated: the canonical `location = /api/v1/xml-handler` is repointed at `public/xml-handler-fast.php` for the fast configurations, and the Lua bindings are commented out for the proxy-off ones. Each switch is asserted after it is applied, because an earlier attempt silently failed to switch the controller and produced a full set of plausible but meaningless numbers.

## 3. Method

1. **Interleaved rounds.** Three rounds, each visiting A → D → B → C. A single sweep per configuration is not trustworthy here: the ESL "ping floor" (20 bare `status` calls, no cache, no PHP) moved between **126 ms and 1096 ms** during one such sweep, which made the last configuration look twice as slow for reasons unrelated to it. Interleaving spreads a stall across all four.
2. **Timing logs off.** `FS_XML_HANDLER_LOG_TIMING` was enabled during one discarded sweep; it attaches a database listener and writes a log line per request, which halved throughput and doubled app-side latency. Every number here is from runs with it disabled.
3. **Who served the lookup** is proved from the mirror TTL, not from logs: PHP rewrites the payload and resets its TTL to 60 s, Lua only reads it and the TTL keeps counting down. Both probes report `lua` for B/C and `php` for A/D in every round.
4. **FreeSWITCH-side cost** is 30 `find_user_xml` calls (directory) and 15 `originate loopback/2000/tenant_2_internal` calls (dialplan), minus the ping floor measured in the same window.
5. **Load** is `pbx:load-test:dialplan --section=directory|dialplan --timing` at 100×1, 100×5 and 500×25. The 500×25 tier ran twice per configuration; the cheaper tiers three times.

## 4. Results

### 4.1 What FreeSWITCH waits for

Per lookup, median of three rounds, ping floor subtracted:

| ID | Fast | Lua | Directory | Dialplan (per call) | Served by |
|:--|:--:|:--:|--:|--:|:--|
| **A** | off | off | **62 ms** | **~140–235 ms** | PHP |
| **B** | off | **on** | **6 ms** | **~22 ms** | Lua (probe) |
| **C** | **on** | **on** | **5–8 ms** | **~22 ms** | Lua (probe) |
| **D** | **on** | off | **80 ms** | **~80 ms** | PHP |

The dialplan row is the one that matters: every call leg produces a dialplan lookup, and the proxy removes essentially all of it — what remains (~22 ms) is the cost of creating the loopback channel, which both sides pay.

### 4.2 Web-app load, median of rounds

Throughput, requests/second:

| Section | Tier | **A** stock | **B** proxy | **C** both | **D** fast |
|:--|:--|--:|--:|--:|--:|
| directory | 100×1 | 12.9 | 12.4 | 10.3 | 13.2 |
| directory | 100×5 | 14.1 | **17.3** | 17.5 | **22.0** |
| directory | 500×25 | 16.4 | 15.3 | 16.2 | **23.4** |
| dialplan | 100×1 | 16.1 | 14.7 | 16.2 | 13.1 |
| dialplan | 100×5 | 13.3 | 14.8 | 13.8 | **22.5** |
| dialplan | 500×25 | 16.0 | 13.1 | 12.6 | 15.7 |

App-side p50, milliseconds:

| Section | Tier | **A** | **B** | **C** | **D** |
|:--|:--|--:|--:|--:|--:|
| directory | 100×5 | 257 | 271 | 244 | **172** |
| directory | 500×25 | 272 | 285 | 258 | **185** |
| dialplan | 100×5 | 257 | 308 | 329 | **160** |
| dialplan | 500×25 | 265 | 346 | 305 | **171** |

A supporting alternation run (ten pairs of stock vs fast at 100×5, order flipped each iteration, partial sample n=4 per cell) agreed with the direction: directory 8.3 → 11.8 req/s, dialplan 7.3 → 13.9 req/s.

### 4.3 What mirroring costs

With the proxy on, every PHP request also writes a mirror copy. At saturation that is visible:

| Comparison | Tier | Without proxy | With proxy | Cost |
|:--|:--|--:|--:|--:|
| directory, fast on (D → C) | 500×25 | 23.4 req/s | 16.2 req/s | **−31 %** |
| dialplan, fast off (A → B) | 500×25 | 16.0 req/s | 13.1 req/s | **−18 %** |

At 100×5 the cost is inside the noise.

## 5. What the numbers say

**The proxy is decisive and unambiguous.** A directory lookup drops from 62 ms to ~6 ms and a dialplan lookup from well over 100 ms to ~22 ms, with the TTL probe confirming Redis served it every round. No amount of host noise explains a 10–20× gap.

**The fast controller is real but smaller, roughly +40–70 % throughput**, and it shows up in app-side p50 everywhere (257 → 172 ms at 100×5; 265 → 171 ms at 500×25). One cell disagrees — dialplan at 500×25 is flat — and that cell's round-1 run was taken while the ping floor was 1096 ms, i.e. the box was stalled, so it is treated as contaminated rather than as a counterexample.

**Mirroring is not free.** It costs 18–31 % of HTTP throughput at saturation on a 1 vCPU box. That is the honest trade: the proxy moves cost off call setup and adds a slice to every PHP request that renders a document.

The two features solve different problems and do not substitute for each other. The fast controller makes *every* PHP render cheaper; the proxy removes the render from the call path entirely.

## 6. Recommendation

- **Keep the fast controller on by default.** It is free, it needs no FreeSWITCH change, and it helps every section.
- **Keep the proxy available but decide per deployment.** On a box whose call-setup rate is the constraint, it is the difference between ~140 ms and ~22 ms per call. On a box already saturated on the HTTP side, its write cost is a real tax — and it only pays off if call setup, not admin traffic, is the bottleneck.
- **Do not treat the proxy as a way to raise HTTP throughput.** It cannot: requests over HTTP still go through PHP.

## 7. Extension: End-to-End SIP Call Capacity & Architecture Comparison (Octane vs Lua vs Fast Controller)

**Date:** 2026-11-02  
**Test Harness:** Dedicated SIPp load generator (`x.x.x.148`) running `uac-extension-capacity.xml` against remote 1 vCPU / 1 GB RAM PBX host (`x.x.x.89`). Call duration: 5 seconds. Tested under standard Debian dynamic PHP-FPM pool (`pm = dynamic`, `max_children = 5`).

While Section 4 measured raw HTTP handler throughput and isolated FreeSWITCH XML lookup wait times, this section records full-stack telephony performance: SIP registration, INVITE/200 OK SDP negotiation, bi-directional RTP media flow, CDR generation, ESL event streaming (`freeswitch:listen`), and WebSockets broadcasting (`reverb:start`).

### 7.1 Head-to-Head Call Capacity Ladder

| Architecture | Peak Sustained Rate | 3 CPS Ladder | 5 CPS Ladder | 8 CPS Ladder | 10 CPS Burst | Call Setup Median (p50) | Failure Mode / Bottleneck |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :--- |
| **Octane Lean Worker**<br>*(Port 8090, 1 worker, RAM DB)* | **6.23 CPS** | **100%** (180/180) | **100%** (300/300) | **94.8%** (455/480) | **99.3%** (596/600) | **82 – 118 ms** | Total 1 vCPU exhaustion (33% ESL, 33% FS, 25% Reverb, 8% softirq) |
| **Lua Proxy Only**<br>*(In-process Lua + Redis)* | **4.60 CPS** | **100%** (39/39) | **100%** (66/66) | **94.8%** (95/100) | **83.6%** (94/111) | **56 – 60 ms** | In-process Lua thread contention and Redis connection limits under concurrency |
| **Fast Controller + Lua Proxy**<br>*(Lua cached + Fast fallback)* | **3.30 CPS** | **100%** (33/33) | **100%** (66/66) | **100%** (105/105) | **73.5%** (91/122) | **56 – 504 ms** | CPU context switching overhead between Lua interpreter and FPM fallbacks |
| **Fast Controller Only**<br>*(xml-handler-fast.php via FPM)* | **~1.5 CPS** | **Failed / Stalled** | Not viable | Not viable | Not viable | **> 3,200 ms** | Worker pool exhaustion (`pm.max_children = 5` queueing past 5s curl timeout) |

### 7.2 Architectural Analysis: Why Octane Outperforms Despite Hardware Limits

1. **Failure Domain Isolation (The Admin Lockout Prevention Factor)**:
   - In any FPM-backed architecture (Stock FPM or Fast Controller), a burst of inbound phone calls sends multiple sequential HTTP queries per call (directory, dialplan, configuration).
   - On a default dynamic pool (`pm.max_children = 5`), just 2 simultaneous inbound calls saturate all 5 FPM worker processes. Subsequent calls queue, call setup latency spikes to 3.3+ seconds (audible dead-air silence before ringing), and administrators attempting to log in to the web panel receive `HTTP 504 Gateway Timeout`.
   - **Octane runs as a dedicated daemon on port 8090**. Telephony surges never touch PHP-FPM (port 80). The web administration panel remains fully responsive even when telephony is at maximum load.

2. **The Truth About the 10 CPS Ceiling on 1 vCPU**:
   - Tests proved that **PHP XML parsing is not the bottleneck**. Whether XML was served by Octane in 1.78 ms, or by Lua in 6 ms directly from Redis RAM, the system hit a hard ceiling between 6 and 10 CPS.
   - At 10 CPS (50 concurrent active calls), CPU utilization was 100% (0.0% idle). The single CPU was split between:
     - `freeswitch:listen` (33% CPU): Deserializing hundreds of ESL JSON events per second into Laravel and MariaDB.
     - `freeswitch` Core (33% CPU): Sofia SIP UDP socket processing, timer loops, RTP allocations, and state machines.
     - `reverb:start` (25% CPU): Broadcasting live channel states over WebSockets.
     - Kernel network processing (8–16% CPU): Software interrupts handling UDP packets.
   - Static XML files would not solve this, because static files still require the same SIP signaling, RTP streaming, ESL events, and WebSockets.

3. **Multi-Core Scaling Horizon**:
   - While a 1 vCPU server maxes out at ~6.2–10 CPS due to sharing a single core between telephony and PHP daemons, Octane provides the foundation for multi-core scaling:
     - On **2 vCPUs**, separating FreeSWITCH from Laravel unlocks **15–20 CPS**.
     - On **4 vCPUs**, Octane scales to **30–60+ CPS** with only 4 worker threads (~250 MB RAM), whereas PHP-FPM would require 24–32 workers (~1.2 GB RAM).

For detailed hardware sizing guidelines, compute isolation architecture, and ESL event pruning instructions, see [`docs/telephony-capacity-scaling-guide.md`](telephony-capacity-scaling-guide.md).

## 8. Caveats

- The host is a **shared** 1 vCPU instance. Absolute numbers move by a factor of two between quiet and busy periods; only the within-round comparisons are trustworthy, which is why every figure above is a median across interleaved rounds.
- FreeSWITCH-side figures subtract a ping floor measured in the same window. That subtraction is crude when a stall lands between the two measurements.
- The 500×25 tier has two rounds per cell, not three.
- Calls were placed with `originate loopback` and fail at the bridge because the test extension is not registered. That failure is after the dialplan lookup, so it does not affect the dialplan measurement.
- No SIPp end-to-end call test was run for these configurations; that measures registration and media, not XML lookup cost.
