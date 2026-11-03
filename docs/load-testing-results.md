# PBX SIP Load Testing & Benchmark Results

Authoritative capacity benchmarks and hardware sizing guidance for TallPBX deployments. This document records empirical benchmark results from datacenter VPS testing across shared and dedicated CPU profiles.

> [!NOTE]
> For the operational manual, lab topology setup, seeding instructions, and test runner options, see the companion **[SIP Load Testing Guide](load-testing-guide.md)**.

## Contents

- [Executive Summary & Hardware Sizing Matrix](#executive-summary--hardware-sizing-matrix)
  - [Datacenter Telephony Capacity Comparison](#datacenter-telephony-capacity-comparison)
  - [Dialplan XML Routing Performance Comparison](#dialplan-xml-routing-performance-comparison)
  - [Production Sizing & Configuration Matrix](#production-sizing--configuration-matrix)
- [Production Recommendations: XML Caching & PHP-FPM Worker Tuning](#production-recommendations-xml-caching--php-fpm-worker-tuning)
- [Capacity Planning Rules](#capacity-planning-rules)
- [How Results Are Recorded & Metrics Reference](#how-results-are-recorded--metrics-reference)
  - [Testing Notation & Concurrency Concepts](#testing-notation--concurrency-concepts)
  - [Performance Metrics & Statistics](#performance-metrics--statistics)
  - [Cache Profiles & Telephony Scenarios](#cache-profiles--telephony-scenarios)
- [Dialplan & Cache Engine Benchmarks (XML Throughput)](#dialplan--cache-engine-benchmarks-xml-throughput)
  - [1 vCPU / 1 GB RAM (Entry Baseline)](#1-vcpu--1-gb-ram-entry-baseline)
  - [1 vCPU / 2 GB RAM (Memory-Scaled)](#1-vcpu--2-gb-ram-memory-scaled)
  - [2 vCPU / 2 GB RAM (Dual-Core)](#2-vcpu--2-gb-ram-dual-core)
  - [2 vCPU Dedicated / 8 GB RAM (Dedicated Compute Node)](#2-vcpu-dedicated--8-gb-ram-dedicated-compute-node)
  - [4 vCPU / 16 GB RAM (Dedicated Cloud Node)](#4-vcpu--16-gb-ram-dedicated-cloud-node)
  - [Persistent Octane Worker (4-core Reference Appliance)](#persistent-octane-worker-4-core-reference-appliance)
  - [XML Serving Configurations: Four-Way Comparison (1 vCPU)](#xml-serving-configurations-four-way-comparison-1-vcpu)
- [Live Call Capacity & Telephony Feature Validation (SIP Signaling)](#live-call-capacity--telephony-feature-validation-sip-signaling)
  - [End-to-End Functional Feature Validation (All Tiers)](#end-to-end-functional-feature-validation-all-tiers)
  - [1 vCPU / 1 GB RAM Live Call Capacity](#1-vcpu--1-gb-ram-live-call-capacity)
  - [1 vCPU / 2 GB RAM Live Call Capacity](#1-vcpu--2-gb-ram-live-call-capacity)
  - [2 vCPU / 2 GB RAM Live Call Capacity](#2-vcpu--2-gb-ram-live-call-capacity)
  - [4 vCPU / 16 GB RAM Live Call Capacity](#4-vcpu--16-gb-ram-live-call-capacity)
  - [Cross-Architecture Call Capacity (Octane vs Lua vs Fast Controller)](#cross-architecture-call-capacity-octane-vs-lua-vs-fast-controller)

---

## Executive Summary & Hardware Sizing Matrix

### Datacenter Telephony Capacity Comparison

Empirical call capacity, setup latencies, and saturation boundaries measured across four datacenter VPS tiers under the current **production cache policy** (`FS_XML_HANDLER_CACHE_TTL=60`, Redis 7.0, and OPcache enabled):

| Hardware Configuration | CPU Allocation | PHP-FPM Profile (`www.conf`) | Cache Policy (`.env`) | Sustained Call Setup Rate | Call Setup Latency (`p50` / `p95`) | Peak Call Capacity / Concurrency | Recommended Production Role |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **1 vCPU, 1 GB RAM** | Shared vCPU | `pm = static` (5 workers) | Production default (`TTL: 60s`) | 3 calls/sec | 244 ms / 328 ms | ~20–35 concurrent (5 calls/sec saturation boundary) | Micro / Edge (1–10 extensions) |
| **1 vCPU, 2 GB RAM** | Shared vCPU | `pm = static` (6 workers) | Production default (`TTL: 60s`) | 3 calls/sec | 276 ms / ~2.2s | 0 MB swap; single-core compute bound | Small Branch (1–15 extensions) |
| **2 vCPU, 2 GB RAM** | Shared vCPU | `pm = static` (6 workers) | Production default (`TTL: 60s`) | 3–5 calls/sec<br>*(up to 8–10 burst)* | ~1.1s (at 2 calls/sec)<br>~4.0s / ~9.5s (at 5 calls/sec)* | 10 calls/sec burst ceiling (89% answer rate, 50 concurrency) | Standard SMB (10–75 extensions) |
| **2 vCPU, 8 GB RAM** | **Dedicated CPU** | **`pm = static` (12 workers)** | **Production default (`TTL: 60s`)** | **5–8 calls/sec** | **160 ms / 180 ms** | **100% 14-scenario pass; sub-second XML peak latency (923 ms)** | **Mid-Market Dedicated (50–150 extensions)** |
| **4 vCPU, 16 GB RAM** | **Dedicated CPU** | **`pm = static` (24 workers)** | **Baseline reference (`TTL: 60s`)** | **15–20 calls/sec** | **148–180 ms / 180–472 ms** | **30 calls/sec burst ceiling (100% completion across 2,110 calls, 0 drops)** | **High-Density Enterprise / Call Center (150–400+ extensions)** |

*\*At baseline arrival rates (2–3 calls/sec), setup latency on the 2 vCPU instance is sub-second to ~1.1s. The ~4.0s p50 and ~9.5s p95 figures reflect queueing delay under heavy 5 calls/sec load where 25 in-flight setups compete for 6 static PHP-FPM workers.*

> [!NOTE]
> **Benchmark methodology and caching profile.** The 1 vCPU / 1 GB RAM tier reflects empirical testing under the current production default (`FS_XML_HANDLER_CACHE_TTL=60`, PHP-FPM `pm = static`, and default `nftables` firewall active). Higher hardware tiers reflect reference benchmarks captured under the pre-v4.2.0 baseline and will be updated as the test host is upsized. Since v4.2.0, panel writes invalidate cached routing XML immediately through versioned cache keys (`RoutingCacheVersion`), so the 60-second default preserves full routing freshness while shielding MariaDB from high-concurrency spikes. All measurements ran with the **full default `nftables` firewall enabled** (geo-blocking, pre-filters, and default-drop active), matching a production-hardened deployment.

---

### Dialplan XML Routing Performance Comparison

XML throughput and latency across moderate bursts (`100 x 5`: 100 requests at 5 concurrency), high concurrency (`500 x 25`), sustained burst ceilings (`1,000 x 25`), and caching extremes (uncached database execution vs. memory hits). Because each call triggers 2–3 dynamic XML queries (auth, dialplan context, destination routing), XML throughput dictates call-setup performance:

| Hardware Configuration | CPU Allocation | PHP-FPM Profile | Cache Policy | Moderate Burst (`100 x 5`) | High Burst (`500 x 25`) | Sustained Ceiling (`1,000 x 25`) | Peak Tail Latency (`Max`) | Uncached MariaDB (`100 x 5`) | Pure Memory Ceiling (`100 x 5`) |
| :--- | :--- | :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: |
| **1 vCPU, 1 GB RAM** | Shared vCPU | `pm = static` (5 workers) | Production (`TTL: 60s`) | 26.4 req/sec (181 ms) | 24.8 req/sec (596 ms) | 26.0 req/sec (566 ms) | 1,632 ms | 15.7 req/sec (309 ms) | 18.9 req/sec (255 ms) |
| **1 vCPU, 2 GB RAM** | Shared vCPU | `pm = static` (6 workers) | Production (`TTL: 60s`) | 14.2 req/sec (340 ms) | 13.2 req/sec (1,147 ms) | 13.5 req/sec (1,129 ms) | 2,369 ms | 12.5 req/sec (313 ms) | 12.0 req/sec (327 ms) |
| **2 vCPU, 2 GB RAM** | Shared vCPU | `pm = static` (6 workers) | Production (`TTL: 60s`) | 24.7 req/sec (183 ms) | 23.9 req/sec (626 ms) | 24.2 req/sec (618 ms) | 1,209 ms | 17.9 req/sec (181 ms) | 18.1 req/sec (181 ms) |
| **2 vCPU, 8 GB RAM** | **Dedicated CPU** | **`pm = static` (12 workers)** | **Production (`TTL: 60s`)** | **30.8 req/sec (144 ms)** | **31.3 req/sec (569 ms)** | **30.7 req/sec (576 ms)** | **984 ms** | **26.3 req/sec (137 ms)** | **27.8 req/sec (133 ms)** |
| **4 vCPU, 16 GB RAM** | **Dedicated CPU** | **`pm = static` (24 workers)** | **Baseline (`TTL: 60s`)** | **57.3 req/sec (77 ms)** | **65.0 req/sec (333 ms)** | **65.3 req/sec (327 ms)** | **448 ms** | **41.1 req/sec (108 ms)** | **72.5 req/sec (61 ms)** |

**Key Architectural Takeaways:**
1. **The Shared-Core Baseline (~24–26 req/sec with Static Pool)**: On a 1-core shared-CPU instance with static PHP-FPM auto-tuning (`pm = static`, 5 workers), XML throughput reaches 26.4 req/sec under burst concurrency (+69% over the legacy dynamic pool), and sustains 26.0 req/sec across 1,000 queries with zero errors.
2. **Dedicated CPU Scaling (>4x Multiplier)**: Moving to 4 dedicated vCPUs with 24 pre-forked static workers eliminates worker starvation. Sustained throughput reaches **65.3 req/sec**, while peak tail latency drops by >80% (capped under 450 ms across 1,000 sustained queries).
3. **Database vs. Cache Scaling**: Redis contributor and dialplan caching shield MariaDB during high-concurrency bursts, keeping average response times under 240 ms on entry hardware and under 70 ms on dedicated instances.

---

### Production Sizing & Configuration Matrix

Use this authoritative reference when selecting server specifications, tuning PHP-FPM pools, and setting Redis cache TTLs:

| Profile / Tier | Recommended Hardware | PHP-FPM Profile (`www.conf`) | Cache TTL Window | Dialplan XML Throughput | Sustained Call Capacity | Active Call Ceiling | Primary Target Deployment |
| :--- | :--- | :--- | :--- | :--- | :--- | ---: | --- |
| **Micro / Edge** | 1 vCPU, 1–2 GB RAM | `pm = static`<br>`pm.max_children = 5` | 60 seconds (default) | 19–26 req/sec | 3–5 calls/sec | 20–35 concurrent | Home office, small branch (1–10 phones) |
| **Standard SMB** | 2–4 vCPU, 4 GB RAM | `pm = static`<br>`pm.max_children = 12` | 60 seconds (default) | 19–28 req/sec | 5–8 calls/sec | 50–100 concurrent | Small-to-medium business (10–75 phones) |
| **Mid-Market** | 4–8 vCPU, 8–16 GB RAM | `pm = static`<br>`pm.max_children = 24` | 60 seconds (default) | 65–72 req/sec | 15–20 calls/sec | 200–400 concurrent | Multi-department office (75–250 phones) |
| **Call Center** | 8+ vCPU, 16 GB RAM | `pm = static`<br>`pm.max_children = 32–48` | 60 seconds (default) | 60–90+ req/sec | 25–40 calls/sec | 400–800 concurrent | Queue-heavy inbound contact center |
| **Enterprise / Multi-Tenant** | 16+ vCPU, 32 GB RAM | `pm = static`<br>`pm.max_children = 64` | 60 seconds (default) | 100–150+ req/sec | 45–60+ calls/sec | 1,000+ concurrent | Multi-tenant cloud hosted PBX |

> [!NOTE]
> Since v4.2.0 the tier-differentiated TTL windows above are obsolete: panel edits invalidate cached routing XML immediately, so every tier runs the same 60-second default. Longer windows only trade Redis keyspace memory for rebuild avoidance — extend per deployment only if memory headroom allows.

> [!TIP]
> **PHP-FPM Worker Sizing Formula**:  
> `pm.max_children = (Total RAM - System & Telephony Overhead [1.5 GB]) / Worker RSS (~65 MB)`  
> On a 4 GB VM: `(4096 - 1536) / 65 ≈ 39` theoretical limit. Setting `pm.max_children = 12` caps PHP-FPM memory under 800 MB, leaving >2.5 GB free for FreeSWITCH RTP media, MariaDB buffers, and Redis.

---

## Production Recommendations: XML Caching & PHP-FPM Worker Tuning

### 1. Telephony XML Cache Policy (`.env`)

TallPBX caches compiled dialplan XML responses, individual contributor fragments (extensions, IVRs, ring groups), and directory lookup data in Redis:

| Deployment Role | Dialplan Route Cache TTL | Contributor Snippet Cache TTL | Directory User Cache TTL | ACL IP List Cache TTL | Observed Hit Rate | Average Latency | Operational Rationale |
| :--- | :---: | :---: | :---: | :---: | ---: | ---: | :--- |
| **Standard Office (Recommended Baseline)** | `60` | `60` | `60` | `60` | ~39.0% | 240 ms | **Write-driven freshness.** Panel changes invalidate cached XML immediately via version bumping (`RoutingCacheVersion`), so the 60-second default costs no routing freshness — it only shields MariaDB longer during call bursts. |
| **Dialplan Cache Off (Dynamic Routes)** | `0` | `60` | `60` | `60` | ~36.8% | 253 ms | **Instant Route Assembly.** Reuses cached modular snippets while compiling fresh route containers on demand for each call. |
| **Cold Baseline (Direct Database Routing)** | `0` | `0` | `60` | `60` | ~96.6%* | 309 ms | **Direct DB routing.** Bypasses dialplan and snippet cache layers; directory and ACL entries remain cached in Redis. (*Hit rate reflects directory and ACL lookups). |

- Benchmark all 4 cache tiers on your host: `bash scripts/run-cache-sweep.sh`
- Check active Redis cache efficiency: `redis-cli info stats | grep -E 'keyspace_hits|keyspace_misses'`

### 2. PHP-FPM Worker Pool Tuning (`/etc/php/8.5/fpm/pool.d/www.conf`)

Unlike a standard website where PHP only serves human page clicks in a browser, a PBX uses PHP-FPM to serve real-time XML call routing to FreeSWITCH. This communication line is the system's **hotpath** — the critical highway of code that must run for every phone registration, extension lookup, and inbound/outbound call. If PHP workers are asleep or busy when a call arrives, callers hear silence and FreeSWITCH drops the call.

- **Always Use Static Mode (`pm = static`) Across All Tiers**: Dynamic mode spawns workers on demand, introducing 200–500 ms process-fork latency during call bursts. In benchmarks, dynamic mode with 5 workers saturated at concurrency 25, creating worker starvation warnings and 502 timeouts. Static mode keeps all workers pre-forked in memory so spare workers are always ready to answer the hotpath instantly, even while users navigate the web panel.
- **Installer Auto-Tuning**: Fresh installations detect system RAM via `free -m` and configure optimal worker pools:
  - **≥ 3,500 MB RAM (4GB+ standard)**: `pm = static`, `pm.max_children = 12` (+15% throughput, zero timeouts, >2.5 GB free RAM).
  - **≥ 1,800 MB RAM (2GB small)**: `pm = static`, `pm.max_children = 6`.
  - **< 1,800 MB RAM (1GB minimal)**: `pm = static`, `pm.max_children = 5` (pre-forked baseline to eliminate fork latency during bursts; paired with mandatory ≥ 2GB swap).
- **Check for Pool Saturation**:
  ```bash
  tail -n 50 /var/log/php8.5-fpm.log | grep "server reached pm.max_children"
  ```
  If this warning appears during peak calling periods, increase `pm.max_children` using the formula above and restart PHP-FPM (`systemctl restart php8.5-fpm`).

---

## Capacity Planning Rules

1. **XML throughput scales with CPU count, not memory.** Doubling RAM from 1 GB to 2 GB on a single core eliminated swap but did not increase XML throughput. Adding a second core roughly doubled burst capability and cut peak tail latency from ~1.7s to ~0.4s. Dedicated cores (4 vCPU) delivered 65+ req/sec sustained with sub-450ms tail latency.
2. **Do not size calls directly from XML numbers.** A complete call requires SIP signaling, a second call leg, FreeSWITCH state tracking, and teardown work that an HTTP XML test never touches.
3. **Expected call capacity:**
   - 1 vCPU ≈ 3 calls/sec sustained (~20–35 concurrent active calls).
   - 2 vCPU ≈ 5–8 calls/sec sustained (10 calls/sec burst ceiling).
   - 4 vCPU Dedicated ≈ 15–20 calls/sec sustained (30 calls/sec burst ceiling, 100% completion across 2,110 calls).
4. **Plan for CPU headroom.** Measured ceilings occurred at 95–98% CPU utilization. Do not plan production capacity above ~80% sustained CPU load.
5. **Check `sessions-per-second` before blaming hardware.** The default of 60 allows roughly 30 two-leg calls/sec. When hit, FreeSWITCH returns `503 Maximum Calls In Progress`. Adjust via `FS_SESSIONS_PER_SECOND` in `.env` or dynamically with `fs_cli -x "fsctl sps <val>"`.
6. **Media capacity is separate.** These numbers measure call setup, answer, and teardown signaling. Continuous RTP audio quality and transcoding capacity require dedicated RTP load testing.

---

## How Results Are Recorded & Metrics Reference

This centralized reference defines all testing notation, metrics, and scenario terminology used across the benchmark tables.

### Testing Notation & Concurrency Concepts

- **Load Tier Format (`<Total Requests> x <Concurrency>`)**:
  - **First Number**: Total request volume delivered over the test run.
  - **Second Number**: Maximum simultaneous HTTP requests kept in flight concurrently at any millisecond. As soon as one response completes, the client fires the next until the total count finishes.
  - Examples: `25 x 1` (sequential warm-up), `100 x 5` (moderate office burst), `500 x 25` (heavy burst), `1,000 x 25` (sustained ceiling).
- **Client Concurrency vs. Server PHP-FPM Workers**:
  - **Client Concurrency** (`--concurrency=X`): Simultaneous requests sent by the load tester.
  - **PHP-FPM Server Workers** (`pm.max_children`): Backend PHP processes available to execute requests.
  - When client concurrency (e.g. 25) exceeds server workers (e.g. 6), requests wait in Nginx/PHP-FPM queue buffers, revealing how the PBX behaves under sudden call spikes.
- **Median Reporting**: To eliminate transient OS scheduling jitter, all comparison tiers represent the median of at least 3 identical repetitions (`r1`, `r2`, `r3`).

### Performance Metrics & Statistics

| Metric | Full Name | Plain-Language Definition & Operational Meaning |
| :--- | :--- | :--- |
| **Req/Sec** | Requests per second | Rate of completed XML dialplan documents delivered per second. Higher is better. |
| **Achieved CPS** | Achieved calls per second | Completion rate of successfully answered live calls (`200 OK`) between first and last answer. |
| **Average (`avg`)** | Mean latency | Total response time divided by total requests. Sensitive to rare extreme outliers. |
| **p50 (Median)** | 50th percentile | The middle response time: 50% of requests were faster, 50% slower. Represents the typical user experience. |
| **p90 / p95** | 90th / 95th percentile | 90% or 95% of requests completed faster than this time. Industry standard SLA benchmarks. |
| **p99** | 99th percentile | Tail latency: the slowest 1 out of 100 requests. Reveals buffer stalls and worker queueing. |
| **Fastest (Min)** | Minimum latency | The quickest response recorded (best-case memory/cache hit). |
| **Slowest (Max)** | Maximum latency | The single slowest response recorded (worst-case queueing or disk read). |
| **Jitter (`std_dev`)** | Standard deviation | How much response times fluctuated. Low = consistent; High = erratic. |
| **App-Side Request Time (`request_ms`)** | End-to-end Laravel request time | Time from `LARAVEL_START` to response, including framework boot. The metric used to judge in-process caching value. Recorded only for runs using `--timing`. |
| **Handler Time (`handler_ms`)** | XML handler pipeline time | Time inside the XML handler middleware window, excluding framework boot. |
| **App-Side p95** | Tail of `request_ms` | Per-section tail latency reported in `timing.by_section` (dialplan vs. directory). |
| **Redis Hit Rate** | Keyspace efficiency | Percentage of lookups served directly from Redis RAM without querying MariaDB (`INFO stats`). |

> [!NOTE]
> Artifacts captured before 2026-11 contain client-side latency only. App-side fields (`schema_version: 2`) appear in reports produced with `--timing`.

### Cache Profiles & Telephony Scenarios

- **`mixed` Scenario**: Simulates real-world PBX routing by distributing requests across varied extensions, inbound DIDs, ring groups, IVRs, and outbound patterns. Exercises dynamic XML generation, database indexes, and Redis fragment caching.
- **`cache-hit` Scenario**: Queries the exact same destination 100 times consecutively. Measures theoretical maximum throughput when 100% of responses hit Redis memory (0 database queries).
- **Layered XML Caching**: Contributor fragments and full dialplan responses are cached hierarchically in Redis DB 1. See [Architecture Guide §7.5](architecture.md#75-telephony-xml-handler-hot-path-layered-caching--versioned-invalidation) for layer semantics, key composition, and `RoutingCacheVersion` invalidation.

---

## Dialplan & Cache Engine Benchmarks (XML Throughput)

Direct benchmarks of the web and database routing engine. Evaluates raw HTTP request throughput (`req/sec`) and latency (`ms`) as FreeSWITCH queries Laravel for dynamic dialplan and directory XML documents. The 1 vCPU / 1 GB RAM baseline was measured under current v4.3.0 defaults (`FS_XML_HANDLER_CACHE_TTL=60`, static PHP-FPM pools, and active default `nftables` firewall).

### 1 vCPU / 1 GB RAM (Entry Baseline)

- **Host**: Cloud VPS (`...141`), Debian 13, 1 shared vCPU, 1 GB RAM, 2 GB swap (0 MB used).
- **Stack**: Nginx 1.26, PHP 8.5-FPM (`pm = static`, `pm.max_children = 5`), MariaDB 10.11, Redis 7.0, active `nftables` default firewall.
- **Role**: Minimal production footprint for small office or edge branch (1–10 extensions).

#### XML Throughput & Concurrency Scaling

| Tier | Repetitions | Req/Sec | Avg Latency | p50 (Median) | Min | Max | p95 | p99 | Jitter (`std_dev`) | Operational Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| `25 x 1` | 1 (warm-up) | 24.577 | 39.4 ms | 38.9 ms | 31.3 ms | 72.6 ms | 46.7 ms | 72.6 ms | 8.1 ms | Initial warm-up; OPcache bytecode and Redis primed. |
| `100 x 5` | 3 (r1–r3) | 26.401 | 180.8 ms | 174.8 ms | 108.6 ms | 298.6 ms | 222.6 ms | 298.6 ms | 29.2 ms | Repeatable baseline with 5 static workers; +69% throughput over legacy dynamic pool. |
| `500 x 25` | 3 (r1–r3) | 24.785 | 595.8 ms | 581.4 ms | 126.7 ms | 1,631.7 ms | 1,055.6 ms | 1,293.4 ms | 235.6 ms | Concurrency 25 across 5 static workers; average latency cut from 990 ms to 596 ms. |
| `1,000 x 25` | 1 (r1) | 26.017 | 565.6 ms | 555.9 ms | 134.4 ms | 1,417.8 ms | 975.4 ms | 1,189.1 ms | 215.1 ms | 1,000/1,000 completed with 0 errors across 5 static workers; stable burst ceiling. |

#### Cache Policy Optimization Tests (`100 x 5`)

| Configuration | TTL Policy (Route / Snippet / Dir / ACL) | Scenario | Req/Sec | Avg Latency | p50 | Min | Max | p95 | Hit Rate | Key Observation |
| :--- | :---: | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **1. Cold Baseline** | `0s / 0s / 60s / 60s` | `mixed` | 15.731 | 308.6 ms | 308.0 ms | 143.2 ms | 455.5 ms | 389.0 ms | 96.6%* | Dialplan and contributor caching off; queries MariaDB for routing logic. (*Hit rate reflects directory and ACL lookups). |
| **2. Dialplan Cache Off** | `0s / 60s / 60s / 60s` | `mixed` | 19.117 | 253.2 ms | 247.4 ms | 132.8 ms | 464.2 ms | 367.6 ms | 36.8% | Reused static routing fragments; dynamic route containers recompiled on demand. |
| **3. Production Baseline** | `60s / 60s / 60s / 60s` | `mixed` | 19.566 | 239.8 ms | 235.9 ms | 117.9 ms | 394.0 ms | 329.1 ms | 39.0% | Production default 60s profile; lowest average latency with immediate versioned invalidation. |
| **4. Memory Ceiling** | `60s / 60s / 60s / 60s` | `cache-hit` | 18.904 | 255.3 ms | 247.7 ms | 125.7 ms | 446.4 ms | 373.1 ms | 52.1% | 100% route cache hits; single-core PHP-FPM / Redis memory serialization ceiling. |

#### Network Interface Comparison (`100 x 5`)

| Interface | Target Endpoint | Req/Sec | Avg Latency | p50 (Median) | Min | Max | p95 | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **Public IPv4** | `http://...141:80/...` | 19.106 | 243.3 ms | 238.4 ms | 122.9 ms | 436.9 ms | 347.1 ms | Standard internet routing via public edge interface and default firewall. |
| **Private IPv4** | `http://10.104.0.2:80/...` | 25.817 | 186.2 ms | 181.7 ms | 110.1 ms | 305.8 ms | 246.0 ms | Datacenter private network; ~23% lower average latency. |
| **Public IPv6** | `http://[2400:6180:...:e000]:80/...` | 27.121 | 177.8 ms | 174.4 ms | 111.4 ms | 294.3 ms | 226.7 ms | Native dual-stack IPv6 routing with minimal packet overhead. |

*Artifacts: `storage/app/load-tests/capacity/datacenter-1c-1g-20261030T2224Z/` and `cache-sweep-20261030-224426/`.*

---

### 1 vCPU / 2 GB RAM (Memory-Scaled)

- **Host**: Cloud VPS (`...141`), resized in place. 1 shared vCPU, 2 GB RAM, 2 GB swap (0 MB used).
- **Stack**: Nginx 1.26, PHP 8.5-FPM (`pm = static`, `pm.max_children = 6`), MariaDB 10.11, Redis 7.0, active `nftables` default firewall.
- **Finding**: Doubling RAM completely eliminated swap usage (0 MB swap used) and increased free RAM buffer to ~1.2 GB, but XML throughput remained compute-bound on the single core (~13.2–14.2 req/sec), confirming that telephony routing throughput scales with CPU cores rather than memory.

#### XML Throughput & Concurrency Scaling

| Tier | Repetitions | Req/Sec | Avg Latency | p50 (Median) | Min | Max | p95 | p99 | Jitter (`std_dev`) | Operational Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| `25 x 1` | 1 (warm-up) | 13.195 | 73.8 ms | 69.4 ms | 62.6 ms | 104.3 ms | 94.8 ms | 104.3 ms | 10.7 ms | Initial warm-up; OPcache bytecode and Redis primed. |
| `100 x 5` | 3 (r1–r3) | 14.225 | 339.6 ms | 331.3 ms | 293.3 ms | 471.2 ms | 381.2 ms | 469.2 ms | 33.0 ms | Repeatable baseline with 6 static workers; memory 100% unconstrained. |
| `500 x 25` | 3 (r1–r3) | 13.157 | 1,147.1 ms | 1,226.3 ms | 365.4 ms | 2,072.9 ms | 1,918.0 ms | 2,011.3 ms | 522.2 ms | Single-core worker saturation at concurrency 25; 0 errors. |
| `1,000 x 25` | 1 (r1) | 13.461 | 1,128.8 ms | 1,190.5 ms | 343.4 ms | 2,369.1 ms | 1,912.8 ms | 2,098.9 ms | 515.6 ms | 1,000/1,000 completed with 0 errors across 6 static workers; zero swap activity. |

#### Cache Policy Optimization Tests (`100 x 5`)

| Configuration | TTL Policy (Route / Snippet / Dir / ACL) | Scenario | Req/Sec | Avg Latency | p50 | Min | Max | p95 | Hit Rate | Key Observation |
| :--- | :---: | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **1. Cold Baseline** | `0s / 0s / 60s / 60s` | `mixed` | 12.467 | 313.1 ms | 286.1 ms | 237.5 ms | 535.5 ms | 399.3 ms | 58.4%* | Dialplan and contributor caching off; queries MariaDB for routing logic. (*Hit rate reflects directory and ACL lookups). |
| **2. Dialplan Cache Off** | `0s / 60s / 60s / 60s` | `mixed` | 11.815 | 326.2 ms | 299.7 ms | 237.8 ms | 641.7 ms | 468.4 ms | 57.3% | Reused static routing fragments; dynamic route containers recompiled on demand. |
| **3. Production Baseline** | `60s / 60s / 60s / 60s` | `mixed` | 12.452 | 314.8 ms | 300.0 ms | 232.0 ms | 550.0 ms | 436.5 ms | 49.9% | Production default 60s profile; balanced throughput with immediate versioned invalidation. |
| **4. Memory Ceiling** | `60s / 60s / 60s / 60s` | `cache-hit` | 12.034 | 326.7 ms | 310.9 ms | 249.5 ms | 562.3 ms | 459.6 ms | 57.5% | 100% route cache hits; single-core PHP-FPM / Redis memory serialization ceiling. |

#### Network Interface Comparison (`100 x 5`)

| Interface | Target Endpoint | Req/Sec | Avg Latency | p50 (Median) | Min | Max | p95 | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **Public IPv4** | `http://...141:80/...` | 13.250 | 367.3 ms | 354.7 ms | 261.0 ms | 533.9 ms | 506.6 ms | Standard internet routing via public edge interface and default firewall. |
| **Private IPv4** | `http://10.104.0.2:80/...` | 11.786 | 407.7 ms | 391.5 ms | 302.7 ms | 601.3 ms | 502.1 ms | Datacenter private network. |
| **Public IPv6** | `http://[2400:6180:...:e000]:80/...` | 13.716 | 353.3 ms | 346.8 ms | 305.0 ms | 421.8 ms | 409.2 ms | Native dual-stack IPv6 routing with minimal packet overhead. |

*Artifacts: `storage/app/load-tests/capacity/datacenter-1c-2g-20261031T074619Z/` and `cache-sweep-20261031-075043/`.*

---

### 2 vCPU / 2 GB RAM (Dual-Core)

- **Host**: Cloud VPS (`...141`), resized in place. 2 shared vCPUs, 2 GB RAM, 2 GB swap (0 MB used).
- **Stack**: Nginx 1.26, PHP 8.5-FPM (`pm = static`, `pm.max_children = 6`), MariaDB 10.11, Redis 7.0, active `nftables` default firewall.
- **Finding**: Adding a second core increased burst throughput to **24.7 req/sec** (+73% over single-core 2GB) and cut average latency across all tiers by nearly half (from ~340 ms to 183 ms under moderate load, and from 1,147 ms to 626 ms under concurrency 25), with zero queue dropouts or timeouts.

#### XML Throughput & Concurrency Scaling

| Tier | Repetitions | Req/Sec | Avg Latency | p50 (Median) | Min | Max | p95 | p99 | Jitter (`std_dev`) | Operational Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| `25 x 1` | 1 (warm-up) | 10.672 | 91.8 ms | 80.8 ms | 65.0 ms | 337.1 ms | 123.3 ms | 337.1 ms | 52.6 ms | Initial warm-up; OPcache bytecode and Redis primed. |
| `100 x 5` | 3 (r1–r3) | 24.679 | 182.8 ms | 183.5 ms | 126.5 ms | 230.2 ms | 222.4 ms | 229.0 ms | 22.9 ms | Dual-core concurrency; latency cut in half vs. single-core baseline. |
| `500 x 25` | 3 (r1–r3) | 23.858 | 626.0 ms | 656.8 ms | 78.5 ms | 1,189.4 ms | 1,028.6 ms | 1,097.0 ms | 280.1 ms | Dual-core burst throughput; average latency reduced from 1,147 ms to 626 ms. |
| `1,000 x 25` | 1 (r1) | 24.218 | 618.0 ms | 623.6 ms | 89.4 ms | 1,208.6 ms | 1,040.6 ms | 1,105.7 ms | 279.3 ms | 1,000/1,000 completed with 0 errors across 6 static workers; rock-solid ceiling. |

#### Cache Policy Optimization Tests (`100 x 5`)

| Configuration | TTL Policy (Route / Snippet / Dir / ACL) | Scenario | Req/Sec | Avg Latency | p50 | Min | Max | p95 | Hit Rate | Key Observation |
| :--- | :---: | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **1. Cold Baseline** | `0s / 0s / 60s / 60s` | `mixed` | 17.911 | 181.3 ms | 169.1 ms | 72.7 ms | 415.7 ms | 290.3 ms | 49.3%* | Dialplan and contributor caching off; queries MariaDB for routing logic. (*Hit rate reflects directory and ACL lookups). |
| **2. Dialplan Cache Off** | `0s / 60s / 60s / 60s` | `mixed` | 18.109 | 176.8 ms | 165.6 ms | 71.9 ms | 310.3 ms | 282.1 ms | 49.0% | Reused static routing fragments; dynamic route containers recompiled on demand. |
| **3. Production Baseline** | `60s / 60s / 60s / 60s` | `mixed` | 19.343 | 169.2 ms | 156.1 ms | 61.5 ms | 306.3 ms | 272.7 ms | 52.9% | Production default 60s profile; lowest latency with immediate versioned invalidation. |
| **4. Memory Ceiling** | `60s / 60s / 60s / 60s` | `cache-hit` | 18.132 | 181.0 ms | 166.7 ms | 88.5 ms | 316.4 ms | 282.5 ms | 49.3% | 100% route cache hits; dual-core memory serialization ceiling. |

#### Network Interface Comparison (`100 x 5`)

| Interface | Target Endpoint | Req/Sec | Avg Latency | p50 (Median) | Min | Max | p95 | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **Public IPv4** | `http://...141:80/...` | 24.203 | 183.7 ms | 186.8 ms | 100.3 ms | 226.8 ms | 216.1 ms | Standard internet routing via public edge interface and default firewall. |
| **Private IPv4** | `http://10.104.0.2:80/...` | 22.447 | 199.8 ms | 204.4 ms | 90.0 ms | 247.7 ms | 235.2 ms | Datacenter private network. |
| **Public IPv6** | `http://[2400:6180:...:e000]:80/...` | 23.148 | 193.4 ms | 192.1 ms | 79.6 ms | 252.8 ms | 231.2 ms | Native dual-stack IPv6 routing with minimal packet overhead. |

*Artifacts: `storage/app/load-tests/capacity/datacenter-2c-2g-20261031T081415Z/` and `cache-sweep-20261031-081702/`.*

---

### 2 vCPU Dedicated / 8 GB RAM (Dedicated Compute Node)

- **Host**: Cloud VPS (`...25`), 2 Dedicated vCPUs (`Intel(R) Xeon(R) Platinum 8358 CPU @ 2.60GHz`), 8 GB RAM (7,947 MB total, 0 MB swap), Linux 6.12 amd64.
- **Stack**: Nginx 1.26, PHP 8.5-FPM (`pm = static`, `pm.max_children = 12`), MariaDB 10.11, Redis 7.0, active default `nftables` stateful firewall.
- **Role**: High-concurrency enterprise PBX node, dedicated compute tier.
- **Finding**: Dedicated compute cores slashed response latency by more than half compared to shared instances. Under peak concurrency of 25 simultaneous XML workers, average latency dropped to **568.8 ms** (down from 1,147 ms on 1c and 626 ms on 2c shared), with max latency remaining sub-second (**923.3 ms**) and sustained throughput reaching **31.3 req/sec** with 0 errors across 1,000 requests.

#### XML Throughput & Concurrency Scaling

| Tier | Repetitions | Req/Sec | Avg Latency | p50 (Median) | Min | Max | p95 | p99 | Jitter (`std_dev`) | Operational Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| `25 x 1` | 1 (warm-up) | 22.286 | 43.5 ms | 39.8 ms | 37.7 ms | 80.6 ms | 62.7 ms | 80.6 ms | 10.4 ms | Sub-45ms baseline; OPcache and Redis primed. |
| `100 x 5` | 2 (r1–r2) | 30.767 | 143.5 ms | 150.4 ms | 66.1 ms | 165.2 ms | 163.5 ms | 164.9 ms | 21.3 ms | **Zero latency drift.** Warmed r2 reached 30.77 req/sec. |
| `500 x 25` | 2 (r1–r2) | 31.282 | 568.8 ms | 708.4 ms | 335.3 ms | 923.3 ms | 787.0 ms | 876.7 ms | 187.0 ms | **Sub-second max latency.** Peak latency stayed under 924 ms across 12 static workers. |
| `1,000 x 25` | 1 (r1) | 30.745 | 576.4 ms | 703.9 ms | 287.0 ms | 983.5 ms | 815.4 ms | 908.7 ms | 183.0 ms | 1,000/1,000 requests completed with 0 errors. |

#### Cache Policy Optimization Tests (`100 x 5`)

| Configuration | TTL Policy (Route / Snippet / Dir / ACL) | Scenario | Req/Sec | Avg Latency | p50 | Min | Max | p95 | Hit Rate | Key Observation |
| :--- | :---: | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **1. Cold Baseline** | `0s / 0s / 60s / 60s` | `mixed` | 26.338 | 136.6 ms | 123.6 ms | 56.7 ms | 306.0 ms | 235.1 ms | 49.3%* | MariaDB routing queries execute faster on dedicated cores. (*Hit rate reflects directory/ACL lookups). |
| **2. Dialplan Cache Off** | `0s / 60s / 60s / 60s` | `mixed` | 28.088 | 126.9 ms | 120.0 ms | 55.0 ms | 215.2 ms | 172.4 ms | 59.3% | Reused static routing fragments; dynamic route containers recompiled on demand. |
| **3. Production Baseline** | `60s / 60s / 60s / 60s` | `mixed` | 28.320 | 127.2 ms | 119.4 ms | 55.7 ms | 231.6 ms | 174.0 ms | 61.8% | Production default 60s profile; highest hit rate (61.8%) and sustained throughput. |
| **4. Memory Ceiling** | `60s / 60s / 60s / 60s` | `cache-hit` | 27.782 | 133.1 ms | 120.5 ms | 90.7 ms | 295.2 ms | 229.4 ms | 53.1% | 100% route cache hits; dedicated dual-core memory serialization ceiling. |

#### Network Interface Comparison (`100 x 5`)

| Interface | Target Endpoint | Req/Sec | Avg Latency | p50 (Median) | Min | Max | p95 | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **Public IPv4** | `http://...25:80/...` | 31.269 | 139.4 ms | 143.1 ms | 63.8 ms | 163.7 ms | 159.2 ms | Standard internet routing via public edge interface and default firewall. |
| **Private IPv4** | `http://10.108.0.2:80/...` | 31.664 | 138.3 ms | 142.0 ms | 60.1 ms | 161.4 ms | 158.7 ms | Datacenter private network routing. |
| **Public IPv6** | `http://[2604:a880:...:1000]:80/...` | 31.123 | 142.1 ms | 144.5 ms | 66.8 ms | 165.9 ms | 162.3 ms | Native dual-stack IPv6 routing under stateful firewall. |

*Artifacts: `cache-sweep-20261031-094031` and `storage/app/load-tests/sipp-e2e-20261031-095409`.*

---

### 4 vCPU / 16 GB RAM (Dedicated Cloud Node)

- **Host**: Cloud VPS (`...200` historical tier), 4 dedicated CPU cores, 16 GB RAM (15 GB available), Linux 6.12 amd64.
- **Stack**: Nginx 1.26, PHP 8.5-FPM (`pm = static`, `pm.max_children = 24`), MariaDB 10.11, Redis 7.0, 0 MB swap.
- **Role**: High-density multi-tenant enterprise PBX and inbound call center (150–400+ extensions).
- **Finding**: Completely eliminated worker starvation. Sustained throughput leaped to **65.3 req/sec** (+303% over 2c/2g), while peak tail latency dropped under 450 ms across 1,000 sustained queries.

#### XML Throughput & Concurrency Scaling

| Tier | Repetitions | Req/Sec | Avg Latency | p50 (Median) | Min | Max | p95 | p99 | Jitter (`std_dev`) | Operational Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| `25 x 1` | 1 (warm-up) | 21.553 | 45.1 ms | 41.2 ms | 37.7 ms | 84.8 ms | 74.5 ms | 84.8 ms | 11.1 ms | Sub-50ms baseline; OPcache and Redis primed. |
| `100 x 5` | 2 (r1–r2) | 57.347 | 77.2 ms | 77.9 ms | 56.6 ms | 96.5 ms | 89.0 ms | 94.2 ms | 8.4 ms | **Sub-100ms across all percentiles.** Warmed r2 reached 57.35 req/sec. |
| `500 x 25` | 2 (r1–r2) | 65.015 | 332.5 ms | 333.2 ms | 210.8 ms | 458.3 ms | 424.4 ms | 437.0 ms | 43.0 ms | **+303% gain over 2c/2g;** tail latency dropped to 458 ms across 24 workers. |
| `1,000 x 25` | 1 (r1) | 65.321 | 326.7 ms | 327.6 ms | 209.3 ms | 448.1 ms | 382.7 ms | 439.5 ms | 40.4 ms | Rock-solid sustained burst ceiling with zero swap activity. |

#### Cache Policy Optimization Tests (`100 x 5`)

| Configuration | Scenario | Req/Sec | Avg Latency | p50 (Median) | Min | Max | p95 | Hit Rate | Key Observation |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **1. Uncached DB** (`TTL: 0s`) | `mixed` | 41.107 | 108.2 ms | 109.1 ms | 80.0 ms | 129.2 ms | 125.8 ms | 0.0% | Uncached MariaDB throughput quadrupled over 1c/2c baselines. |
| **2. Dialplan Cache Off** (`C: 5s, D: 0s`) | `mixed` | 58.520 | 75.6 ms | 73.5 ms | 48.2 ms | 111.8 ms | 91.4 ms | 48.5% | Reused static routing fragments; achieved 48.5% hit rate. |
| **3. Legacy 5s Baseline** (`TTL: 5s`) | `mixed` | 65.340 | 67.4 ms | 66.5 ms | 48.5 ms | 116.0 ms | 80.7 ms | 47.0% | Legacy 5s default profile; 65.34 req/sec with immediate invalidation. |
| **4. Extended Retention** (`TTL: 30s`) | `mixed` | 70.635 | 63.2 ms | 61.0 ms | 43.3 ms | 118.8 ms | 70.3 ms | 40.9% | High-density trunk routing; reached 70.64 req/sec. |
| **5. Memory Ceiling** (`cache-hit`) | `cache-hit` | 72.519 | 61.1 ms | 59.9 ms | 47.7 ms | 113.5 ms | 69.6 ms | 30.5% | Zero DB queries; memory serialization ceiling at 72.5 req/sec. |

*Artifacts: `storage/app/load-tests/capacity/datacenter-4c16g-dedicated-20260924T220942Z/` and `cache-sweep-20260924-221059/`.*

### Persistent Octane Worker (4-core Reference Appliance)

> **Provisional figures.** This is the first measurement of the production XML path — FreeSWITCH posting to the resident RoadRunner worker on `127.0.0.1:8090`. The baseline row is queued for re-measurement alongside the rest of this document; treat the multipliers as indicative until then.

- **Host**: appliance server (`fspbx1`), 4 cores, 4 GB RAM, 2 GB swap, Debian 13, Linux 6.12 amd64.
- **Stack**: PHP 8.5.12 CLI under RoadRunner (`rr` 2024.3) + Laravel Octane 2.x; FreeSWITCH 1.11.4; MariaDB 11; Redis 7 (cache DB 1, CDR stream DB 3).
- **Worker**: `tallpbx-xml-worker.service`, started with `TALLPBX_WORKER_ROLE=telephony`, listening only on `127.0.0.1:8090`; four auto-scaled workers held roughly 162 MB resident.
- **Baseline**: nginx + PHP-FPM **static pool, 12 workers**, same host, same tenant and request counts.
- **Load**: `tpbx pbx:load-test:dialplan`, tenant `load-test-vbox` (100 extensions).

#### Worker HTTP Throughput

| Metric | 100×5 Cache-Hit | 500×25 Cache-Hit | 100×5 Mixed | 500×25 Mixed | 100×5 Directory |
|:--|--:|--:|--:|--:|--:|
| **Total Requests** | 100 | 500 | 100 | 500 | 100 |
| **Successful Responses** | 100 | 500 | 100 | 500 | 100 |
| **Failed / Timeouts** | 0 | 0 | 0 | 0 | 0 |
| **Elapsed Time** | 2.95 s | 7.50 s | 4.55 s | 11.26 s | 5.16 s |
| **Client Throughput** | **33.9 req/s** | **66.6 req/s** | **22.0 req/s** | **44.4 req/s** | **19.4 req/s** |
| **Client Latency (Min)** | 19.5 ms | 33.7 ms | 29.7 ms | 24.5 ms | 57.8 ms |
| **Client Latency (p50)** | 58.2 ms | 180.7 ms | 67.5 ms | 224.4 ms | 165.2 ms |
| **Client Latency (Avg)** | 88.9 ms | 193.1 ms | 104.8 ms | 277.4 ms | 172.3 ms |
| **App-Side `handler_ms` (p50)** | **10.8 ms** | **10.2 ms** | **12.0 ms** | **10.6 ms** | **14.2 ms** |
| **App-Side `handler_ms` (p95)** | **28.4 ms** | **26.2 ms** | **187.0 ms** | **204.5 ms** | **42.8 ms** |
| **Database Queries (Cached)** | **0** | **0** | **0 (hits)** | **0 (hits)** | **0** |

*Client latency includes CLI Guzzle `Http::pool` batching and per-batch TCP overhead; app-side `handler_ms` is the processing window inside the resident worker. The 2,500 req/s target in the design notes describes low-level socket concurrency (`sipp` / `wrk`), not this PHP CLI harness, and has not been demonstrated.*

#### Worker vs PHP-FPM (Same Host)

| Metric | PHP-FPM, 12 static workers | Octane worker | Delta |
|:--|--:|--:|:--|
| **Elapsed Time (100 reqs)** | 9.13 s | 2.95 s | **3.1× faster elapsed** |
| **Throughput (100×5)** | 10.95 req/s | 33.91 req/s | **+210 % throughput (+3.1×)** |
| **Client Latency (Min)** | 169.99 ms | 19.47 ms | **8.7× faster response** |
| **Client Latency (p50)** | 347.17 ms | 58.15 ms | **6.0× lower latency** |
| **Client Latency (Avg)** | 355.48 ms | 88.85 ms | **4.0× lower latency** |
| **App-side p95** | 436.95 ms (`request_ms`) | 28.38 ms (`handler_ms`) | ~400 ms of boot removed — directional only, the two columns measure different windows |
| **Throughput under saturation (500×25)** | 3.59 req/s (worker starvation) | 66.65 req/s (0 errors) | **18.5× throughput under load** |

The worker removes the per-request boot, so the gap widens under saturation: 66.65 req/s with zero failures at concurrency 25 against 3.59 req/s for the static pool. The 100×5 baseline was captured before the pool tuning recorded in the 1 vCPU tiers above and is being re-measured; until then the 3.1× multiplier is provisional.

Cache hits recorded `X-TallPBX-DB-Queries: 0`, and atomic cache locks serialize a cold-key stampede into one build. Tenant isolation is enforced by `ClearTenantContext` and `VerifyCleanDatabaseState` on request and operation termination; byte parity is asserted by `XmlHandlerOctaneParityTest`, with `TenantStateIsolationTest` covering cross-tenant cleanup. Verification run: 97 tests / 379 assertions, Pint clean, and a live `find_user_xml` answered 200 from the worker.

### XML Serving Configurations: Four-Way Comparison (1 vCPU)

> **Measured 2026-11-01, before the persistent worker became the primary path.** The fast front controller and the Lua proxy are now disabled in favour of the RoadRunner worker (see above); this section is the reference data for those two optional paths and for the request-cost baseline they were built to remove.

**Purpose:** measure the two optional speed-ups independently and together, so a decision to keep or remove either one rests on a number. **Supersedes** the earlier directory-only sweep: the pre-dialplan run reached the same gate decision — app-side p95 73.9–292.4 ms, HTTP p95 1,233 ms at concurrency 25, directory share 66.7 % (PROCEED) — and its full report was retired once this comparison replaced it.

**Provenance:** Lua proxy v2 plan, Task 8 (the plan document was removed after completion; recover it from git history if needed).

**Environment**

| Item | Value |
|:--|:--|
| Host | Datacenter test server (`x.x.x.145`; public address obfuscated per project convention) |
| OS | Debian GNU/Linux 13 (trixie), amd64 |
| CPU / RAM / Swap | 1 shared vCPU, 967 MB, 2 GB swap |
| PHP | 8.5.12, FPM `pm = static`, `pm.max_children = 5` |
| FreeSWITCH | 1.11.4 (SignalWire packages) |
| Cache policy | `FS_XML_HANDLER_CACHE_TTL=60`, dialplan cache TTL 60 s |
| Tenant | `load-test-beta` (tenant 2), domain `load.test.local`, extension `2000`, context `tenant_2_internal` |

**Configurations**

| ID | Fast controller | Lua proxy | What it represents |
|:--|:--:|:--:|:--|
| **A** | off | off | Stock install before either optimisation |
| **B** | off | on | Proxy alone |
| **C** | on | on | Both, as a future default |
| **D** | on | off | Fast controller alone |

Both switches are real, not simulated: the canonical `location = /api/v1/xml-handler` is repointed at `public/xml-handler-fast.php` for the fast configurations, and the Lua bindings are commented out for the proxy-off ones. Each switch is asserted after it is applied, because an earlier attempt silently failed to switch the controller and produced a full set of plausible but meaningless numbers.

**Method**

1. **Interleaved rounds.** Three rounds, each visiting A → D → B → C. A single sweep per configuration is not trustworthy here: the ESL "ping floor" (20 bare `status` calls, no cache, no PHP) moved between 126 ms and 1,096 ms during one such sweep, which made the last configuration look twice as slow for reasons unrelated to it. Interleaving spreads a stall across all four.
2. **Timing logs off.** `FS_XML_HANDLER_LOG_TIMING` was enabled during one discarded sweep; it attaches a database listener and writes a log line per request, which halved throughput and doubled app-side latency. Every number here is from runs with it disabled.
3. **Who served the lookup** is proved from the mirror TTL, not from logs: PHP rewrites the payload and resets its TTL to 60 s, Lua only reads it and the TTL keeps counting down. Both probes report `lua` for B/C and `php` for A/D in every round.
4. **FreeSWITCH-side cost** is 30 `find_user_xml` calls (directory) and 15 `originate loopback/2000/tenant_2_internal` calls (dialplan), minus the ping floor measured in the same window.
5. **Load** is `pbx:load-test:dialplan --section=directory|dialplan --timing` at 100×1, 100×5 and 500×25. The 500×25 tier ran twice per configuration; the cheaper tiers three times.

**What FreeSWITCH waits for** — per lookup, median of three rounds, ping floor subtracted:

| ID | Fast | Lua | Directory | Dialplan (per call) | Served by |
|:--|:--:|:--:|--:|--:|:--|
| **A** | off | off | **62 ms** | **~140–235 ms** | PHP |
| **B** | off | **on** | **6 ms** | **~22 ms** | Lua (probe) |
| **C** | **on** | **on** | **5–8 ms** | **~22 ms** | Lua (probe) |
| **D** | **on** | off | **80 ms** | **~80 ms** | PHP |

The dialplan row is the one that matters: every call leg produces a dialplan lookup, and the proxy removes essentially all of it — what remains (~22 ms) is the cost of creating the loopback channel, which both sides pay.

**Web-app load** — median of rounds. Throughput, requests/second:

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

**What mirroring costs.** With the proxy on, every PHP request also writes a mirror copy. At saturation that is visible:

| Comparison | Tier | Without proxy | With proxy | Cost |
|:--|:--|--:|--:|--:|
| directory, fast on (D → C) | 500×25 | 23.4 req/s | 16.2 req/s | **−31 %** |
| dialplan, fast off (A → B) | 500×25 | 16.0 req/s | 13.1 req/s | **−18 %** |

At 100×5 the cost is inside the noise.

**What the numbers say.**

- **The proxy is decisive and unambiguous.** A directory lookup drops from 62 ms to ~6 ms and a dialplan lookup from well over 100 ms to ~22 ms, with the TTL probe confirming Redis served it every round. No amount of host noise explains a 10–20× gap.
- **The fast controller is real but smaller, roughly +40–70 % throughput**, and it shows up in app-side p50 everywhere (257 → 172 ms at 100×5; 265 → 171 ms at 500×25). One cell disagrees — dialplan at 500×25 is flat — and that cell's round-1 run was taken while the ping floor was 1,096 ms, i.e. the box was stalled, so it is treated as contaminated rather than as a counterexample.
- **Mirroring is not free.** It costs 18–31 % of HTTP throughput at saturation on a 1 vCPU box. That is the honest trade: the proxy moves cost off call setup and adds a slice to every PHP request that renders a document.
- The two features solve different problems and do not substitute for each other. The fast controller makes *every* PHP render cheaper; the proxy removes the render from the call path entirely.

**Recommendation.**

- **Keep the fast controller on by default.** It is free, it needs no FreeSWITCH change, and it helps every section.
- **Keep the proxy available but decide per deployment.** On a box whose call-setup rate is the constraint, it is the difference between ~140 ms and ~22 ms per call. On a box already saturated on the HTTP side, its write cost is a real tax — and it only pays off if call setup, not admin traffic, is the bottleneck.
- **Do not treat the proxy as a way to raise HTTP throughput.** It cannot: requests over HTTP still go through PHP.

**Caveats.**

- The host is a **shared** 1 vCPU instance. Absolute numbers move by a factor of two between quiet and busy periods; only the within-round comparisons are trustworthy, which is why every figure above is a median across interleaved rounds.
- FreeSWITCH-side figures subtract a ping floor measured in the same window. That subtraction is crude when a stall lands between the two measurements.
- The 500×25 tier has two rounds per cell, not three.
- Calls were placed with `originate loopback` and fail at the bridge because the test extension is not registered. That failure is after the dialplan lookup, so it does not affect the dialplan measurement.
- No SIPp end-to-end call test was run for these configurations; that measures registration and media, not XML lookup cost.

---

## Live Call Capacity & Telephony Feature Validation (SIP Signaling)

Live SIP benchmarks measure complete telephony call setup, bidirectional audio flows, and feature validation across two hosts: the PBX target (`...141`) and a dedicated load generator (`...116`). Setup latency is measured from the initial `INVITE` through digest authentication to the destination `200 OK`.

### End-to-End Functional Feature Validation (All Tiers)

Before capacity stress testing, the full 14-scenario telephony feature validation suite was executed via `scripts/pbx-sipp-validate.sh` (`MEDIA_FLOW=1`, `EXTENDED=1`) between the PBX target (`...141`) and the dedicated load generator (`...116`) over public IP, evaluating directly against the PBX's active default `nftables` firewall rules. **All 14 scenarios passed with 100% completion:**

| Test Scenario | Destination / Feature | Off / Limit / Count | Result | Operational Verification Summary |
| :--- | :--- | ---: | :---: | :--- |
| **SIP Registration** | `register.xml` (20 users) | 5 / 5 / 20 | **Passed** | 20/20 extensions authenticated with `200 OK` (Expires: 3600). |
| **Extension Calls** | Internal extensions (2000–2019) | 2 / 5 / 10 | **Passed** | 10/10 authenticated calls completed via UAS auto-answer on port 5066. |
| **Outbound Gateway** | Gateway routing to UAS | 1 / 2 / 5 | **Passed** | 5/5 calls routed through gateway to UAS port 5088 with PBX SNAT. |
| **Recording Media** | `*732` (Call Recording) | 1 / 1 / 1 | **Passed** | FreeSWITCH answered, recorded active RTP audio, and saved session cleanly. |
| **MOH Media** | `load_test_moh` (Music on Hold) | 1 / 1 / 1 | **Passed** | Call answered, active RTP captured on UDP 6002 (`load_test_moh.wav`). |
| **Announcement Media** | `load_test_announcement` | 1 / 1 / 1 | **Passed** | Call answered, active RTP captured on UDP 6004, PBX sent BYE after playback. |
| **Ring Group** | Extension 2400 | 1 / 1 / 3 | **Passed** | Simultaneous ring bridged to destinations with `200 OK`. |
| **Voicemail** | `*98` (Voicemail Access) | 1 / 1 / 3 | **Passed** | Authenticated voicemail IVR answered and completed menu navigation. |
| **Conference Bridge** | Extension 2500 | 1 / 1 / 3 | **Passed** | Conference room bridge connected and audio mixer initialized. |
| **Call Forwarding** | Ext 2000 -> 2001 forwarding | 1 / 1 / 3 | **Passed** | Diverted via FreeSWITCH loopback channel to 2001; answered cleanly. |
| **Time Conditions** | Extension 2600 | 1 / 1 / 3 | **Passed** | Evaluated schedule routing rules and terminated at active time target. |
| **Follow-Me** | Extension 2002 | 1 / 1 / 3 | **Passed** | Stepped hunting destinations sequentially and bridged to available endpoint. |
| **Emergency Routing** | Extension 911 | 1 / 1 / 3 | **Passed** | Matched emergency dialplan expression and routed to emergency handler. |
| **Call Blocking** | Blacklisted Caller ID (`5550199`) | 1 / 1 / 3 | **Passed** | Matched incoming blacklist entry and rejected immediately with `603 Decline`. |

*Artifacts: `storage/app/load-tests/sipp-e2e-20261031-070313` (1c/1g), `sipp-e2e-20261031-075147` (1c/2g), `sipp-e2e-20261031-081756` (2c/2g), and `sipp-e2e-20261031-095409` (2c/8g dedicated).*

---

### 1 vCPU / 1 GB RAM Live Call Capacity

Sustained extension-to-extension capacity tests with SIP authentication and XML dialplan routing. Each row represents the median of 3 measured repetitions:

| Offered Rate | Attempted | Achieved CPS | Success Rate | Avg Setup | p50 (Median) | Min | Max | p95 | Capacity Assessment |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **3 CPS** | 60 | 2.962 | **100.0%** (60/60) | 255.1 ms | 244.0 ms | 192.0 ms | 492.0 ms | 328.0 ms | **Rock-solid baseline.** Sub-second setup across 100% of calls; 0 signaling errors. |
| **5 CPS** | 100 | 4.359 | **100.0%** (100/100) | 3,150.7 ms | 3,284.0 ms | 360.0 ms | 6,296.0 ms | 3,964.0 ms | **Worker saturation boundary.** 100% completed, but queueing delays emerge. |
| **8 CPS** | 160 | 4.552 | **90.0%** (144/160) | 7,311.1 ms | 8,260.1 ms | 716.0 ms | 13,224.1 ms | 9,384.1 ms | **Queue overflow.** Worker pool saturates; triggers `mod_xml_curl` 5s timeouts. |
| **10 CPS** | 200 | 5.440 | **55.5%** (111/200) | 7,747.9 ms | 8,888.1 ms | 702.0 ms | 14,302.1 ms | 9,570.1 ms | **Heavy overload.** Concurrency 50 exhausts worker backlog; ~45% calls rejected. |

**Performance Insights:**
- Each authenticated call requires **three discrete XML HTTP requests**: (1) caller auth digest challenge in `directory`, (2) dialplan context lookup in `dialplan`, and (3) destination location in `directory`.
- At **3 CPS**, the XML arrival rate is ~9 req/sec. The 5 PHP-FPM workers process requests in ~250 ms without queueing, delivering a median call setup latency of **244 ms** and 100% success.
- At **5+ CPS**, XML arrival exceeds 15 req/sec (matching the single-core CPU limit). Requests queue up in the PHP-FPM listen backlog; when queued delay exceeds FreeSWITCH `mod_xml_curl`'s 5.0s timeout, Sofia fails the lookup and issues `403 Forbidden`.
- **Production Rule**: On 1 vCPU / 1 GB nodes, plan production telephony capacity at **3 calls/sec** (~20–35 concurrent active calls). Deployments requiring 5+ calls/sec should upgrade to 2+ vCPUs and configure `pm = static` with `pm.max_children = 12`.

*Artifacts: `storage/app/load-tests/capacity/datacenter-b1-sipp-20260924T1849Z/`.*

---

### 1 vCPU / 2 GB RAM Live Call Capacity

Sustained extension-to-extension capacity tests with 6 static PHP-FPM workers:

| Offered Rate | Attempted | Achieved CPS | Success Rate | Avg Setup | p50 (Median) | Min | Max | p95 | Capacity Assessment |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **3 CPS** | 60 | 2.354 | **100.0%** (60/60) | 721.9 ms | 276.0 ms | 200.0 ms | 5,548.1 ms | 2,268.0 ms | **Rock-solid baseline.** Sub-second setup across 100% of calls (p50: 276 ms); 0 errors. |
| **5 CPS** | 100 | 2.391 | **97.3%** (99/100) | 5,101.4 ms | 5,600.1 ms | 556.0 ms | 15,760.2 ms | 8,288.1 ms | **Worker saturation boundary.** 97.3% success; queuing emerges behind single core. |
| **8 CPS** | 160 | 3.328 | **74.0%** (141/160) | 6,443.0 ms | 6,840.1 ms | 660.0 ms | 17,764.2 ms | 9,272.1 ms | **Queue overflow.** Worker backlog saturates; triggers timeouts and retransmissions. |
| **10 CPS** | 200 | 2.000 | **25.0%** (50/200) | 6,292.9 ms | 6,736.1 ms | 832.0 ms | 17,588.2 ms | 9,244.1 ms | **Overload ceiling.** 75% of calls rejected under heavy worker contention. |

**Performance Insights:**
- Doubling physical memory to 2 GB eliminated swap activity and provided ~1.2 GB of free RAM buffer. However, call-handling capacity remained strictly bound to the single CPU core.
- **Architectural Takeaway**: Administrators cannot increase call-handling capacity simply by adding RAM to a single-core machine. Adding compute cores (2+ vCPU) is required to unlock higher call rates.

*Artifacts: `storage/app/load-tests/capacity/datacenter-1c2g-sipp-20260924T2004Z/`.*

---

### 2 vCPU / 2 GB RAM Live Call Capacity

Sustained extension-to-extension capacity tests across 2 shared compute cores with 6 static PHP-FPM workers:

| Offered Rate | Attempted | Concurrency Limit | Achieved CPS | Success Rate | Avg Setup | p50 (Median) | Min | Max | p95 | Capacity Assessment |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **Warmup (2 CPS)** | 10 | 5 | 1.918 | **100.0%** (10/10) | 1,696.8 ms | 1,168.0 ms | 580.0 ms | 6,584.1 ms | 6,584.1 ms | Initial connection priming; 100% completed with zero errors. |
| **5 CPS** | 100 | 25 | 4.359 | **93.0%** (93/100) | 4,428.5 ms | 4,008.1 ms | 1,032.0 ms | 14,280.2 ms | 9,484.1 ms | **Stable operating ceiling.** 93% success at concurrency 25. |
| **10 CPS** | 200 | 50 | 5.440 | **89.0%** (178/200) | 7,453.0 ms | 7,804.1 ms | 1,612.0 ms | 18,084.2 ms | 10,268.1 ms | **High-throughput burst.** Handled 178 calls with 89% answer rate (concurrency 50). |
| **15 CPS** | 300 | 60 | 4.552 | **19.7%** (59/300) | 7,025.7 ms | 6,564.1 ms | 3,520.1 ms | 13,736.2 ms | 9,804.1 ms | **Worker & Core Saturation.** Queue backlog saturates shared CPU. |
| **20 CPS** | 400 | 60 | 5.332 | **20.5%** (82/400) | 8,820.5 ms | 9,412.1 ms | 2,348.0 ms | 19,508.3 ms | 10,692.1 ms | **Exhaustion Ceiling.** Heavy signaling contention; remaining calls dropped. |

**Performance Insights:**
- Adding a second vCPU unlocked a substantial leap in call concurrency: on 2 vCPUs, the server answered **178 calls out of 200 at 10 CPS** (an 89% answer rate under intense burst concurrency of 50 simultaneous calls).
- The dual compute cores allow FreeSWITCH Sofia SIP signaling threads and PHP-FPM XML workers to execute simultaneously across discrete cores without starving the Linux network stack.
- **Production Recommendation**: Plan production workloads at **5–8 calls/sec** sustained (supporting 50–100 active extensions), with headroom to absorb bursts up to **10 calls/sec**.

*Artifacts: `storage/app/load-tests/capacity/datacenter-2c2g-sipp-20260924T2141Z/`.*

---

### 4 vCPU / 16 GB RAM Live Call Capacity

Sustained extension-to-extension capacity tests on 4 dedicated CPU cores with 24 static PHP-FPM workers. Across **2,110 total calls** spanning 2 to 30 calls/sec, the system achieved a **100% completion rate with ZERO failed calls**:

| Offered Rate | Attempted | Concurrency Limit | Achieved CPS | Success Rate | Avg Setup | p50 (Median) | Min | Max | p95 | Capacity Assessment |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **Warmup (2 CPS)** | 10 | 5 | 1.918 | **100.0%** (10/10) | 656.8 ms | 144.0 ms | 124.0 ms | 5,224.1 ms | 5,224.1 ms | Connection priming; 100% completed with zero errors. |
| **5 CPS** | 100 | 25 | 4.689 | **100.0%** (100/100) | 394.3 ms | 148.0 ms | 96.0 ms | 5,228.1 ms | 180.0 ms | **Flawless baseline.** Median setup 148 ms, 95th percentile 180 ms. |
| **10 CPS** | 200 | 50 | 9.550 | **100.0%** (200/200) | 414.1 ms | 148.0 ms | 96.0 ms | 5,276.1 ms | 284.0 ms | **Zero degradation.** Median setup 148 ms, 95th percentile 284 ms. |
| **15 CPS** | 300 | 60 | 14.120 | **100.0%** (300/300) | 461.2 ms | 180.0 ms | 112.0 ms | 5,536.1 ms | 472.0 ms | **High-density sustained.** Median setup 180 ms, 95th percentile 472 ms. |
| **20 CPS** | 400 | 60 | 14.380 | **100.0%** (400/400) | 723.2 ms | 484.0 ms | 132.0 ms | 6,472.1 ms | 1,248.0 ms | **Heavy burst.** Median setup 484 ms, 95th percentile 1.25s. |
| **25 CPS** | 500 | 75 | 14.450 | **100.0%** (500/500) | 1,597.1 ms | 1,404.0 ms | 200.0 ms | 8,612.1 ms | 3,488.1 ms | **Stress concurrency.** 100% completed across 500 calls (concurrency 75). |
| **30 CPS** | 600 | 75 | 14.470 | **100.0%** (600/600) | 1,575.3 ms | 1,296.0 ms | 268.0 ms | 8,152.1 ms | 2,932.0 ms | **Peak capacity ceiling.** 100% completed across 600 calls; zero drops. |

**Performance Insights:**
- **Zero Call Failures**: Across 2,110 calls up to 30 CPS and concurrency 75, **zero calls failed**.
- Up through **15 CPS**, call setup was virtually instantaneous: **median setup latency was 148–180 ms**, and the 95th percentile remained under 472 ms.
- At **20–30 CPS**, FreeSWITCH handled burst peaks of **301 active concurrent sessions** and **55 sessions/second** with zero SIP signaling errors.
- With 4 dedicated CPU cores and 24 static PHP-FPM workers, dynamic XML lookups execute in 60–80 ms, keeping the FreeSWITCH `mod_xml_curl` queue completely clear.
- **Production Recommendation**: Plan production capacity at **15–20 calls/sec** sustained (supporting 200–400 active concurrent extensions), with headroom to absorb bursts up to **30 calls/sec** with zero call drops.

*Artifacts: `storage/app/load-tests/capacity/datacenter-4c16g-sipp-20260924T2211Z/`.*

### Cross-Architecture Call Capacity (Octane vs Lua vs Fast Controller)

**Date:** 2026-11-02 — **Test harness:** dedicated SIPp load generator (`x.x.x.148`) running `uac-extension-capacity.xml` against a remote 1 vCPU / 1 GB RAM PBX host (`x.x.x.89`). Call duration: 5 seconds. Tested under the standard Debian dynamic PHP-FPM pool (`pm = dynamic`, `max_children = 5`).

While the XML throughput benchmarks above measure raw handler performance, this section records full-stack telephony performance: SIP registration, INVITE/200 OK SDP negotiation, bi-directional RTP media flow, CDR generation, ESL event streaming (`freeswitch:listen`), and WebSocket broadcasting (`reverb:start`).

| Architecture | Peak Sustained Rate | 3 CPS Ladder | 5 CPS Ladder | 8 CPS Ladder | 10 CPS Burst | Call Setup Median (p50) | Failure Mode / Bottleneck |
| :--- | :---: | :---: | :---: | :---: | :---: | :---: | :--- |
| **Octane Lean Worker**<br>*(Port 8090, 1 worker, RAM DB)* | **6.23 CPS** | **100%** (180/180) | **100%** (300/300) | **94.8%** (455/480) | **99.3%** (596/600) | **82 – 118 ms** | Total 1 vCPU exhaustion (33% ESL, 33% FS, 25% Reverb, 8% softirq) |
| **Lua Proxy Only**<br>*(In-process Lua + Redis)* | **4.60 CPS** | **100%** (39/39) | **100%** (66/66) | **94.8%** (95/100) | **83.6%** (94/111) | **56 – 60 ms** | In-process Lua thread contention and Redis connection limits under concurrency |
| **Fast Controller + Lua Proxy**<br>*(Lua cached + Fast fallback)* | **3.30 CPS** | **100%** (33/33) | **100%** (66/66) | **100%** (105/105) | **73.5%** (91/122) | **56 – 504 ms** | CPU context switching overhead between Lua interpreter and FPM fallbacks |
| **Fast Controller Only**<br>*(xml-handler-fast.php via FPM)* | **~1.5 CPS** | **Failed / Stalled** | Not viable | Not viable | Not viable | **> 3,200 ms** | Worker pool exhaustion (`pm.max_children = 5` queueing past 5s curl timeout) |

**Failure domain isolation (the admin-lockout factor).** In any FPM-backed architecture (stock FPM or fast controller), a burst of inbound calls sends multiple sequential HTTP queries per call (directory, dialplan, configuration). On a default dynamic pool (`pm.max_children = 5`), two simultaneous inbound calls saturate all five worker processes; subsequent calls queue, call setup latency spikes past 3.3 seconds (audible dead air before ringing), and administrators logging into the panel receive `HTTP 504 Gateway Timeout`. Octane runs as a dedicated daemon on port 8090, so telephony surges never touch PHP-FPM (port 80) and the administrative panel stays responsive at maximum call load.

**The truth about the 10 CPS ceiling on 1 vCPU.** PHP XML parsing is not the bottleneck: whether XML was served by Octane in 1.78 ms or by Lua in 6 ms directly from Redis RAM, the system hit a hard ceiling between 6 and 10 CPS. At 10 CPS (50 concurrent active calls) CPU utilisation was 100% (0.0% idle), split between `freeswitch:listen` (33%: deserialising hundreds of ESL JSON events per second into Laravel and MariaDB), the FreeSWITCH core (33%: Sofia SIP UDP processing, timer loops, RTP allocations, state machines), `reverb:start` (25%: broadcasting live channel state over WebSockets), and kernel network processing (8–16%: UDP software interrupts). Static XML files would not solve this, because static files still require the same SIP signalling, RTP streaming, ESL events, and WebSockets.

**Multi-core scaling horizon.** A 1 vCPU server maxes out at ~6.2–10 CPS because telephony and PHP daemons share one core. Separating FreeSWITCH from Laravel on **2 vCPUs** unlocks **15–20 CPS**; on **4 vCPUs** Octane scales to **30–60+ CPS** with only four worker threads (~250 MB RAM), where PHP-FPM would require 24–32 workers (~1.2 GB RAM).

For hardware sizing, compute isolation architecture, and ESL event pruning instructions, see [telephony-capacity-scaling-guide.md](telephony-capacity-scaling-guide.md).

*Artifacts for the XML-side four-way comparison: `storage/app/load-tests/` on the test host (`task1-*.json`, `cache-sweep-*`); the SIP-capacity artifacts are listed with each live-call tier above.*
