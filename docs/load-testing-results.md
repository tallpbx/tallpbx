# PBX SIP Load Testing & Benchmark Results

Authoritative capacity benchmarks and hardware sizing guidance for TallPBX deployments. This document records empirical benchmark results from datacenter VPS testing across shared and dedicated CPU profiles.

> [!NOTE]
> For the operational manual, lab topology setup, seeding instructions, and test runner options, see the companion **[SIP Load Testing Guide](load-testing-guide.md)**.

## Contents

- [Current Baseline: Measured Capacity & PHP-FPM Defaults](#current-baseline-measured-capacity--php-fpm-defaults)
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
- [CDR Fast Entry Series (1 vCPU, 2026-11-08)](#cdr-fast-entry-series-1-vcpu-2026-11-08)
- [Live Call Capacity & Telephony Feature Validation (SIP Signaling)](#live-call-capacity--telephony-feature-validation-sip-signaling)
  - [End-to-End Functional Feature Validation (All Tiers)](#end-to-end-functional-feature-validation-all-tiers)
  - [1 vCPU / 1 GB RAM Live Call Capacity](#1-vcpu--1-gb-ram-live-call-capacity)
  - [1 vCPU / 2 GB RAM Live Call Capacity](#1-vcpu--2-gb-ram-live-call-capacity)
  - [2 vCPU / 2 GB RAM Live Call Capacity](#2-vcpu--2-gb-ram-live-call-capacity)
  - [4 vCPU / 16 GB RAM Live Call Capacity](#4-vcpu--16-gb-ram-live-call-capacity)
  - [Cross-Architecture Call Capacity (Octane vs Lua vs Fast Controller)](#cross-architecture-call-capacity-octane-vs-lua-vs-fast-controller)
  - [PHP-FPM Static Worker Sweep on Minimal Hardware (1 vCPU / 1 GB, 2026-11-08)](#php-fpm-static-worker-sweep-on-minimal-hardware-1-vcpu--1-gb-2026-11-08)
  - [Pre-2vCPU Verification Block and Default Selection (1 vCPU / 1 GB, 2026-11-09)](#pre-2vcpu-verification-block-and-default-selection-1-vcpu--1-gb-2026-11-09)
  - [2 vCPU / 2 GB: Capacity Ladder and Worker Sweep (2026-11-09)](#2-vcpu--2-gb-capacity-ladder-and-worker-sweep-2026-11-09)

---

## Current Baseline: Measured Capacity & PHP-FPM Defaults

This section is the authoritative summary. Everything below it is the dated evidence that produced these numbers; where older tables disagree, this section wins.

### What the installer ships

| Setting | Value | What it does |
|:--|:--|:--|
| `pm` | `dynamic` | Workers start and retire with load |
| `pm.start_servers` | `1` | Workers present at startup |
| `pm.min_spare_servers` | `1` | Fewest idle kept ready; forks when the last idle worker takes a request |
| `pm.max_spare_servers` | `2` | Most idle tolerated; retires above this |
| `pm.max_children` | `3` below 1,800 MB · `6` below 3,500 MB · `12` above | Hard ceiling on total workers, chosen from detected RAM |
| `pm.max_requests` | `10000` | Requests per worker before it recycles, bounding memory creep |

### Measured call capacity, two hardware tiers

Both rows were measured on the current build with the fast XML controller, the fast CDR entry, a warm 60-second lookup cache, FreeSWITCH logging at `notice`, and the full default `nftables` firewall. Each cell offered calls for 60 seconds.

| Host | Supported rate (100 % completion) | First failing rate | Completion at the failing rate | Setup p50 / p95 at that rate |
|:--|:--|:--|:--|:--|
| **1 vCPU / 1 GB** | **5 calls/sec** (300/300) | 6 calls/sec | 70 % (252/360) | 4 580 / 7 652 ms |
| **2 vCPU / 2 GB** | **10 calls/sec** (600/600) | 12 calls/sec | 41.5 % (299/720) | 3 844 / 6 860 ms |

Capacity scales with cores, and the ceiling is **CPU**, not the pool and not CDR: at 12 calls/sec on two cores PHP-FPM alone demanded 2.25 cores while CDR integrity held at every rate tested, including a cell that lost 58 % of its calls.

### The persistent worker removes the boundary at both tiers

Same rate, same warm cache, same firewall — only the XML serving path changes from PHP-FPM to the optional RoadRunner worker on port 8090:

| Host | Rate | Shipped FPM pool | **Octane worker** | FPM setup p50 | Octane setup p50 |
|:--|:--|:--|:--|:--|:--|
| 1 vCPU / 1 GB | 6 calls/sec | 70 % (252/360) | **100 % (360/360)** | 4 580 ms | 280 ms |
| 2 vCPU / 2 GB | 12 calls/sec | 41.5 % (299/720) | **100 % (720/720)** | 3 844 ms | **284 ms** |

Both Octane cells passed the CDR gate (721 and 1 441 rows) with zero failed calls, so the call-rate boundary documented above is a property of the **PHP-FPM XML path**, not of the hardware. Median setup latency falls 13× at the same rate.

> [!NOTE]
> Per-process CPU in the Octane cells is not trustworthy: the sampler reports the RoadRunner child processes under the `php-fpm` class, so the split between `php-fpm` and `octane-worker` is distorted even though the totals are correct. Completion, CDR reconciliation and latency are unaffected. A p95 of 3 948 ms at 12 calls/sec also shows a tail worth investigating if Octane is ever promoted from option to default.

### How the pool shape matters — the same experiment at each tier's own boundary

Each column is that tier's first failing rate, because a rate below capacity cannot discriminate pool shapes.

| Pool shape | 1 vCPU @ 6 calls/sec | 2 vCPU @ 12 calls/sec |
|:--|:--|:--|
| **dynamic, RAM ceiling** (shipped) | **70 %** (252/360) | **41.5 %** (299/720) |
| static 1 | 84–97 % (two runs) | 44.0 % (317/720) |
| static 2 | 62–71 % (two runs) | 35.8 % (258/720) |
| static 3 | 64 % (230/360) | — |
| static 4 | 51 % (184/360) | 43.2 % (311/720) |
| static 5 | 38–50 % (two runs) | 34.4 % (248/720) |
| Debian stock dynamic (ceiling 5, higher idle floor) | 52–56 % | — |

**On one core the pool shape is first-order:** completion spans **59 points** and orders strictly — fewer workers always lose fewer calls, because extra workers only deepen a queue that one core has to drain. **On two cores it is second-order:** the span is **10 points**, the ordering is non-monotonic (1 > 4 > dynamic > 2 > 5), and a single run per shape cannot separate those differences from noise. Latency still degrades with more workers on both tiers (median 4 624 → 5 436 ms as workers go 1 → 5), so the queue-depth mechanism survives even where it no longer decides the outcome.

**Consequence for the default:** keep the dynamic pool on every tier. On one core its tight ceiling matches the best measured behaviour; on two it delivers the best median setup latency of the five shapes. Only the ceiling varies, and only with RAM.

Detailed evidence, per-service CPU and per-call latency for each of these cells: *PHP-FPM Static Worker Sweep on Minimal Hardware*, *Pre-2vCPU Verification Block and Default Selection*, and *2 vCPU / 2 GB: Capacity Ladder and Worker Sweep* below.

---

## Executive Summary & Hardware Sizing Matrix

### Datacenter Telephony Capacity Comparison

Empirical call capacity, setup latencies, and saturation boundaries measured across four datacenter VPS tiers under the current **production cache policy** (`FS_XML_HANDLER_CACHE_TTL=60`, Redis 7.0, and OPcache enabled):

| Hardware Configuration | CPU Allocation | PHP-FPM Profile (`www.conf`) | Cache Policy (`.env`) | Sustained Call Setup Rate | Call Setup Latency (`p50` / `p95`) | Peak Call Capacity / Concurrency | Recommended Production Role |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| **1 vCPU, 1 GB RAM** | Shared vCPU | `pm = dynamic` (max 3 workers) | Production default (`TTL: 60s`) | 5 calls/sec | 244 ms / 328 ms | ~20–35 concurrent (5 calls/sec saturation boundary) | Micro / Edge (1–10 extensions) |
| **1 vCPU, 2 GB RAM** | Shared vCPU | `pm = dynamic` (max 6 workers) | Production default (`TTL: 60s`) | 5 calls/sec | 276 ms / ~2.2s | 0 MB swap; single-core compute bound | Small Branch (1–15 extensions) |
| **2 vCPU, 2 GB RAM** | Shared vCPU | `pm = dynamic` (max 6 workers) | Production default (`TTL: 60s`) | 10 calls/sec<br>*(25+ CPS with Octane)* | ~1.1s (at 2 calls/sec)<br>3.8s / 6.9s (at 12 calls/sec)* | 10 calls/sec sustained ceiling (100% completion; 25+ CPS with Octane) | Standard SMB (10–75 extensions) |
| **2 vCPU, 8 GB RAM** | **Dedicated CPU** | **`pm = dynamic` (max 12 workers)** | **Production default (`TTL: 60s`)** | **10–15 calls/sec** | **160 ms / 180 ms** | **100% 14-scenario pass; sub-second XML peak latency (923 ms)** | **Mid-Market Dedicated (50–150 extensions)** |
| **4 vCPU, 16 GB RAM** | **Dedicated CPU** | **`pm = dynamic` (max 12 workers)** | **Baseline reference (`TTL: 60s`)** | **15–20 calls/sec** | **148–180 ms / 180–472 ms** | **30 calls/sec burst ceiling (100% completion across 2,110 calls, 0 drops)** | **High-Density Enterprise / Call Center (150–400+ extensions)** |

*\*At baseline arrival rates (2–3 calls/sec), setup latency on the 2 vCPU instance is sub-second to ~1.1s. The 3.8s p50 and 6.9s p95 figures reflect queueing delay under heavy 12 calls/sec load where calls compete for dynamic PHP-FPM workers.*

> [!NOTE]
> **Benchmark methodology and caching profile.** The 1 vCPU / 1 GB RAM tier reflects empirical testing under the current production default (`FS_XML_HANDLER_CACHE_TTL=60`, PHP-FPM `pm = dynamic`, and default `nftables` firewall active). Higher hardware tiers reflect reference benchmarks captured under the pre-v4.2.0 baseline and will be updated as the test host is upsized. Since v4.2.0, panel writes invalidate cached routing XML immediately through versioned cache keys (`RoutingCacheVersion`), so the 60-second default preserves full routing freshness while shielding MariaDB from high-concurrency spikes. All measurements ran with the **full default `nftables` firewall enabled** (geo-blocking, pre-filters, and default-drop active), matching a production-hardened deployment.

---

### Dialplan XML Routing Performance Comparison

XML throughput and latency across moderate bursts (`100 x 5`: 100 requests at 5 concurrency), high concurrency (`500 x 25`), sustained burst ceilings (`1,000 x 25`), and caching extremes (uncached database execution vs. memory hits). Because each call triggers 2–3 dynamic XML queries (auth, dialplan context, destination routing), XML throughput dictates call-setup performance:

| Hardware Configuration | CPU Allocation | PHP-FPM Profile | Cache Policy | Moderate Burst (`100 x 5`) | High Burst (`500 x 25`) | Sustained Ceiling (`1,000 x 25`) | Peak Tail Latency (`Max`) | Uncached MariaDB (`100 x 5`) | Pure Memory Ceiling (`100 x 5`) |
| :--- | :--- | :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: |
| **1 vCPU, 1 GB RAM** | Shared vCPU | `pm = dynamic` (max 3) | Production (`TTL: 60s`) | 26.4 req/sec (181 ms) | 24.8 req/sec (596 ms) | 26.0 req/sec (566 ms) | 1,632 ms | 15.7 req/sec (309 ms) | 18.9 req/sec (255 ms) |
| **1 vCPU, 2 GB RAM** | Shared vCPU | `pm = dynamic` (max 6) | Production (`TTL: 60s`) | 14.2 req/sec (340 ms) | 13.2 req/sec (1,147 ms) | 13.5 req/sec (1,129 ms) | 2,369 ms | 12.5 req/sec (313 ms) | 12.0 req/sec (327 ms) |
| **2 vCPU, 2 GB RAM** | Shared vCPU | `pm = dynamic` (max 6) | Production (`TTL: 60s`) | 24.7 req/sec (183 ms) | 23.9 req/sec (626 ms) | 24.2 req/sec (618 ms) | 1,209 ms | 17.9 req/sec (181 ms) | 18.1 req/sec (181 ms) |
| **2 vCPU, 8 GB RAM** | **Dedicated CPU** | **`pm = dynamic` (max 12)** | **Production (`TTL: 60s`)** | **30.8 req/sec (144 ms)** | **31.3 req/sec (569 ms)** | **30.7 req/sec (576 ms)** | **984 ms** | **26.3 req/sec (137 ms)** | **27.8 req/sec (133 ms)** |
| **4 vCPU, 16 GB RAM** | **Dedicated CPU** | **`pm = dynamic` (max 12)** | **Baseline (`TTL: 60s`)** | **57.3 req/sec (77 ms)** | **65.0 req/sec (333 ms)** | **65.3 req/sec (327 ms)** | **448 ms** | **41.1 req/sec (108 ms)** | **72.5 req/sec (61 ms)** |

**Key Architectural Takeaways:**
1. **The Shared-Core Baseline (~24–26 req/sec with Dynamic Pool)**: On a 1-core shared-CPU instance with dynamic PHP-FPM auto-tuning (`pm = dynamic`, max 3 workers), XML throughput reaches 26.4 req/sec under burst concurrency, and sustains 26.0 req/sec across 1,000 queries with zero errors.
2. **Dedicated CPU Scaling (>4x Multiplier)**: Moving to 4 dedicated vCPUs with dynamic workers eliminates worker starvation. Sustained throughput reaches **65.3 req/sec**, while peak tail latency drops by >80% (capped under 450 ms across 1,000 sustained queries).
3. **Database vs. Cache Scaling**: Redis contributor and dialplan caching shield MariaDB during high-concurrency bursts, keeping average response times under 240 ms on entry hardware and under 70 ms on dedicated instances.

---

### Production Sizing & Configuration Matrix

Use this authoritative reference when selecting server specifications, tuning PHP-FPM pools, and setting Redis cache TTLs:

| Profile / Tier | Recommended Hardware | PHP-FPM Profile (`www.conf`) | Cache TTL Window | Dialplan XML Throughput | Sustained Call Capacity | Active Call Ceiling | Primary Target Deployment |
| :--- | :--- | :--- | :--- | :--- | :--- | ---: | --- |
| **Micro / Edge** | 1 vCPU, 1–2 GB RAM | `pm = dynamic`<br>`pm.max_children = 3` | 60 seconds (default) | 19–26 req/sec | 5 calls/sec | 20–35 concurrent | Home office, small branch (1–10 phones) |
| **Standard SMB** | 2–4 vCPU, 2–4 GB RAM | `pm = dynamic`<br>`pm.max_children = 6` | 60 seconds (default) | 19–28 req/sec | 10 calls/sec (25+ with Octane) | 50–100 concurrent | Small-to-medium business (10–75 phones) |
| **Mid-Market** | 4–8 vCPU, 8–16 GB RAM | `pm = dynamic`<br>`pm.max_children = 12` | 60 seconds (default) | 65–72 req/sec | 15–20 calls/sec | 200–400 concurrent | Multi-department office (75–250 phones) |
| **Call Center** | 8+ vCPU, 16 GB RAM | `pm = dynamic`<br>`pm.max_children = 12` | 60 seconds (default) | 60–90+ req/sec | 25–40 calls/sec | 400–800 concurrent | Queue-heavy inbound contact center |
| **Enterprise / Multi-Tenant** | 16+ vCPU, 32 GB RAM | `pm = dynamic`<br>`pm.max_children = 12` | 60 seconds (default) | 100–150+ req/sec | 45–60+ calls/sec | 1,000+ concurrent | Multi-tenant cloud hosted PBX |

> [!NOTE]
> Since v4.2.0 the tier-differentiated TTL windows above are obsolete: panel edits invalidate cached routing XML immediately, so every tier runs the same 60-second default. Longer windows only trade Redis keyspace memory for rebuild avoidance — extend per deployment only if memory headroom allows.

> [!TIP]
> **PHP-FPM Worker Sizing Formula**:  
> The installer (`scripts/resources/php.sh`) configures dynamic worker pools based on physical RAM: `pm.max_children = 3` on < 1,800 MB, `6` on ≥ 1,800 MB, and `12` on ≥ 3,500 MB (with `start_servers = 1`, `min_spare_servers = 1`, `max_spare_servers = 2`, and `pm.max_requests = 10000`). This bounds memory creep while preventing worker starvation and queue-depth penalties.

---

## Production Recommendations: XML Caching & PHP-FPM Worker Tuning

### 1. Telephony XML Cache Policy (`.env`)

TallPBX caches compiled dialplan XML responses, individual contributor fragments (extensions, IVRs, ring groups), and directory lookup data in Redis:

| Deployment Role | Dialplan Route Cache TTL | Contributor Snippet Cache TTL | Directory User Cache TTL | ACL IP List Cache TTL | Observed Hit Rate | Average Latency | Operational Rationale |
| :--- | :---: | :---: | :---: | :---: | ---: | ---: | :--- |
| **Standard Office (Recommended Baseline)** | `60` | `60` | `60` | `60` | ~39.0% | 240 ms | **Write-driven freshness.** Panel changes invalidate cached XML immediately via version bumping (`RoutingCacheVersion`), so the 60-second default costs no routing freshness — it only shields MariaDB longer during call bursts. |
| **Dialplan Cache Off (Dynamic Routes)** | `0` | `60` | `60` | `60` | ~36.8% | 253 ms | **Instant Route Assembly.** Reuses cached modular snippets while compiling fresh route containers on demand for each call. |
| **Cold Baseline (Direct Database Routing)** | `0` | `0` | `60` | `60` | ~96.6%* | 309 ms | **Direct DB routing.** Bypasses dialplan and snippet cache layers; directory and ACL entries remain cached in Redis. (*Hit rate reflects directory and ACL lookups). |

- Benchmark all 5 cache tiers on your host: `bash load-testing/scripts/run-cache-sweep.sh`
- Check active Redis cache efficiency: `redis-cli info stats | grep -E 'keyspace_hits|keyspace_misses'`

### 2. PHP-FPM Worker Pool Tuning (`/etc/php/8.5/fpm/pool.d/www.conf`)

Unlike a standard website where PHP only serves human page clicks in a browser, a PBX uses PHP-FPM to serve real-time XML call routing to FreeSWITCH. This communication line is the system's **hotpath** — the critical highway of code that must run for every phone registration, extension lookup, and inbound/outbound call.

- **Dynamic Mode (`pm = dynamic`) Shipped Across All Tiers**: The 2026-11-08/09 worker sweeps demonstrated that static pools idle-consume RAM on small hosts and lose more calls at the 1 vCPU failure boundary (where fewer workers queue fewer calls behind the single core). On 2+ vCPUs, worker count is second-order behind Octane (`tallpbx-xml-worker`). TallPBX therefore ships `pm = dynamic` with one resident idle worker (`pm.start_servers = 1`, `pm.min_spare_servers = 1`, `pm.max_spare_servers = 2`, `pm.max_requests = 10000`).
- **Installer Auto-Tuning**: Fresh installations (`scripts/resources/php.sh`) detect system RAM via `free -m` and configure optimal worker pool ceilings:
  - **≥ 3,500 MB RAM (4GB+ standard)**: `pm = dynamic`, `pm.max_children = 12`.
  - **≥ 1,800 MB RAM (2GB small)**: `pm = dynamic`, `pm.max_children = 6`.
  - **< 1,800 MB RAM (1GB minimal)**: `pm = dynamic`, `pm.max_children = 3` (measured tight ceiling to minimize queue backlog at the failure boundary).
- **Check for Pool Saturation**:
  ```bash
  tail -n 50 /var/log/php8.5-fpm.log | grep "server reached pm.max_children"
  ```
  If this warning appears during peak calling periods, verify whether CPU is saturated before raising `pm.max_children`, or consider enabling the persistent RoadRunner/Octane worker (`tallpbx-xml-worker`) on port 8090.

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

> **Measured 2026-11-01, before the persistent worker and fast front controller were standardized.** The fast front controller (`xml-handler-fast.php`) is now the shipped default, the RoadRunner/Octane worker (`tallpbx-xml-worker`) is opt-in on `:8090`, and the Lua proxy was retired after this 4-way comparison; this section is the historical reference data for those paths and for the request-cost baseline they were built to evaluate.

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

- **Fast controller shipped as default (`xml-handler-fast.php`).** It is free, requires no FreeSWITCH change, and helps every section.
- **Lua proxy evaluated and retired in 5.0.0.** The RoadRunner/Octane persistent worker (`tallpbx-xml-worker`) on `:8090` provides superior throughput (66+ req/s) and sub-second setup latencies without the cache-mirroring write tax or maintenance overhead of the Lua proxy.

**Caveats.**

- The host is a **shared** 1 vCPU instance. Absolute numbers move by a factor of two between quiet and busy periods; only the within-round comparisons are trustworthy, which is why every figure above is a median across interleaved rounds.
- FreeSWITCH-side figures subtract a ping floor measured in the same window. That subtraction is crude when a stall lands between the two measurements.
- The 500×25 tier has two rounds per cell, not three.
- Calls were placed with `originate loopback` and fail at the bridge because the test extension is not registered. That failure is after the dialplan lookup, so it does not affect the dialplan measurement.
- No SIPp end-to-end call test was run for these configurations; that measures registration and media, not XML lookup cost.

---

## CDR Fast Entry Series (1 vCPU, 2026-11-08)

**Question.** What does serving CDR ingestion through `public/cdr-fast.php` — a five-provider minimal bootstrap instead of the full application — buy on the entry tier, and what does the switch sustain while it runs?

**Environment.** PBX `x.x.x.138`, 1 vCPU, 967 MB, 2 GB swap; generator `x.x.x.55`, 1 vCPU, no firewall. Build `5.x` @ `759ed6a`. XML served by the fast controller (`v-fast`), CDR by the fast entry, FreeSWITCH on its three-binding ladder with the Octane binding disabled, and the full default nftables ruleset active for the whole series.

### Ingestion latency (single POST, empty body → 422)

| Path | p50 | p90 | Probes |
|:--|--:|--:|--:|
| Stock `index.php` | 205 ms | — | 30 |
| Fast entry | **78 ms** | 100 ms | 30 |
| Fast entry, concurrency 5 | 82 ms | 155 ms | 50 |

A real attributed payload posted end-to-end returned 200, drained the stream within 2 s, and wrote one correct row with the payload byte length preserved.

### Call cells (fast entry, post-fix harness)

| Offered | Calls ok | Failed | CDR rows | Gate |
|--:|--:|--:|--:|:--|
| 5 CPS (anchor, earlier cell) | **300** | 0 | **601** | pass |
| 8 CPS | 102 | 378 | 231 | pass |
| 10 CPS | 62 | 538 | 153 | pass |
| 15 CPS | 53 | 847 | 148 | pass |

The 5 CPS anchor is the highest rate this tier serves fully; it compares with 276/300 calls and 46/552 rows recorded before the fast entry. Above 5 CPS the host is worker-bound and completion falls roughly linearly with the offered rate.

### CPU by process during the cells (peak % of one core)

| Class | 8 CPS | 10 CPS | 15 CPS |
|:--|--:|--:|--:|
| php-fpm | 116 | **127** | 117 |
| freeswitch | 52 | 52 | 61 |
| mariadb | 15 | 18 | 18 |
| queue-worker | 13 | 15 | 16 |
| esl-listener | 7 | 10 | 12 |
| cdr-worker | 6 | 7 | 6 |
| redis | 6 | 6 | 7 |
| octane-worker (idle) | 6 | 3 | 3 |

php-fpm is the ceiling: one pool of five static workers saturates while FreeSWITCH still has headroom, and the CDR worker stays under 7 %, so the write side of the pipeline is not the limit. Attributed memory: php-fpm 68–71 MB, FreeSWITCH 160–166 MB, cdr-worker 44 MB.

### Stock vs fast CDR ingestion at 5 CPS (interleaved A/B)

Four cells, alternating **fast → stock → fast → stock**, 300 offered calls each at 5 CPS. Both arms serve XML through the fast controller, so only the CDR entry differs, and each arm is proved live before its cell by the CORS header the full application adds and the fast entry does not. Alternating the order puts host drift on both arms instead of on whichever ran second.

| Slot | Arm | Calls ok | CDR rows |
|:--|:--|--:|--:|
| 1 | fast | 249 | 513 |
| 2 | stock | 88 | 194 |
| 3 | fast | 179 | 377 |
| 4 | stock | 104 | 227 |
| — | **fast total** | **428 / 600** | **890** |
| — | **stock total** | **192 / 600** | **421** |

Fast delivered **2.2× the completed calls and 2.1× the CDR rows**. Within-arm spread was 249→179 (fast) and 88→104 (stock), so the gap is much larger than the drift the interleaving was meant to cancel.

Per-process load, measured in the second pair (179 vs 104 completed calls, same window):

| Class | fast peak CPU | stock peak CPU | fast RAM | stock RAM |
|:--|--:|--:|--:|--:|
| php-fpm | 113 % | 119 % | 76 MB | **99 MB** |
| freeswitch | 45 % | 31 % | 132 MB | 123 MB |
| mariadb | 16 % | 17 % | 84 MB | 72 MB |
| cdr-worker | 7 % | 4 % | 45 MB | 45 MB |
| redis | 6 % | 4 % | 4 MB | 4 MB |

Both arms pin PHP-FPM at roughly one core, so the peak is not the difference — the work done at that peak is. Normalized per completed call:

| Arm | php-fpm CPU-seconds in the call window | Per completed call |
|:--|--:|--:|
| fast | 27.8 s | **155 ms** |
| stock | 27.1 s | **261 ms** |

Fast converts the same ~27 CPU-seconds into 69 % more completed calls and holds **23 MB less** resident in PHP-FPM (23 % lower). FreeSWITCH's lower CPU on the stock arm is consistent with fewer registrations reaching a call attempt, not with stock being cheaper.

**Caveats.** One interleaved pair; each series keeps a single CPU CSV, so the per-process table describes the second pair only while the call counts cover all four cells. The host is shared, and the earlier 2 CPS stock sample predates the sampler fix and remains unusable.

### What the retry-and-spool ladder absorbed

nginx recorded **1,596 failed posts** to `/api/v1/cdr` between 2026-11-04 and 2026-11-08 11:11 — the overloaded cells and the crash era. None were lost: FreeSWITCH retried, spooled what it could not deliver, and the drain reconciled it; the spool is empty.

### Stability

Six core dumps between 04:22 and 08:52 all faulted inside `mod_xml_curl`'s fetch with a zeroed binding. Root cause: the load-test harness unloaded that module between cells. Once binding changes were applied by restarting the switch — and repeated applies became no-ops — eight consecutive cells at 5–15 CPS completed with **zero** restarts.

### Measurement limits (do not use these)

- Per-process samples taken before ~10:50 came from a sampler that held its SSH channel and covered the pre-call window; those files are idle readings, not call load.
- The stock-CDR arm was only sampled at 2 CPS, pre-fix, at idle; that sample is unusable. The valid comparison is the interleaved 5 CPS A/B above, and it covers one pair of cells.
- The sampler files for 8/10/15 CPS on the workstation are stale; the valid copies remain on the PBX under `storage/app/load-tests/cdr-fpm-fast-20261108/`.
- Raw artifacts are not committed; per-cell data stays in `load-testing/results/` on the host that produced it.

---

## Live Call Capacity & Telephony Feature Validation (SIP Signaling)

Live SIP benchmarks measure complete telephony call setup, bidirectional audio flows, and feature validation across two hosts: the PBX target (`...141`) and a dedicated load generator (`...116`). Setup latency is measured from the initial `INVITE` through digest authentication to the destination `200 OK`.

### End-to-End Functional Feature Validation (All Tiers)

Before capacity stress testing, the full 14-scenario telephony feature validation suite was executed via `load-testing/scripts/pbx-sipp-validate.sh` (`MEDIA_FLOW=1`, `EXTENDED=1`) between the PBX target (`...141`) and the dedicated load generator (`...116`) over public IP, evaluating directly against the PBX's active default `nftables` firewall rules. **All 14 scenarios passed with 100% completion:**

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
- **Production Rule**: On 1 vCPU / 1 GB nodes, plan production telephony capacity at **5 calls/sec** (100% completion; ~20–35 concurrent active calls). Deployments requiring 10+ calls/sec should upgrade to 2+ vCPUs with the shipped dynamic pool (`pm.max_children = 6`) or enable the persistent Octane worker (`tallpbx-xml-worker`) to reach 25+ calls/sec.

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

*Superseded on 2026-11-09:* the 2 vCPU projection above was made before the current default was measured. With the shipped dynamic pool, a 2 vCPU / 2 GB host completes **10 calls/sec (600/600)** and loses 58 % at 12 calls/sec — see *2 vCPU / 2 GB: Capacity Ladder and Worker Sweep* below.

For hardware sizing, compute isolation architecture, and ESL event pruning instructions, see [telephony-capacity-scaling-guide.md](telephony-capacity-scaling-guide.md).

*Artifacts for the XML-side four-way comparison: `storage/app/load-tests/` on the test host (`task1-*.json`, `cache-sweep-*`); the SIP-capacity artifacts are listed with each live-call tier above.*

---

### PHP-FPM Static Worker Sweep on Minimal Hardware (1 vCPU / 1 GB, 2026-11-08)

**Question.** How many PHP-FPM workers does minimum hardware actually need, and at what point does adding workers stop helping because the bottleneck is elsewhere?

**Configuration.** The shipped default throughout: the trimmed fast XML controller (`xml-handler-fast.php`) and fast CDR entry (`cdr-fast.php`) sharing one PHP-FPM pool, no panel traffic, FreeSWITCH log level `notice`, XML/CDR access logs disabled, **with the full default nftables firewall enabled**. Build `5.x` @ `dd14e577`; 1 vCPU / 967 MB PBX (x.x.x.138); separate SIPp generator (x.x.x.55).

**Method.** One cell per worker count: the per-variant smoke (20 registrations plus register, extension-call and outbound checks) as the warm-up, then a 60 second offered-load window at a fixed call rate, a CDR gate verdict, and per-service CPU sampled every 2 seconds (php-fpm, nginx, freeswitch, esl-listener, reverb, cdr-worker, queue-worker, scheduler, mariadb, redis). One run per cell.

**Phase 1 — 5 calls/sec (300 offered).** Every pool size is identical: 300/300 calls completed, 0 failed, 601 CDR rows at **5, 4, 3, 2 and 1 worker alike**. With the 60-second lookup cache warm, a lookup is a Redis hit, so 5 calls/sec fits in a single worker. The rate sits below the constraint and cannot discriminate worker counts:

| Workers | Completed | CDR rows | Call setup p50 / p95 | php-fpm CPU (busy window mean / peak) | freeswitch CPU (mean) | php-fpm RAM (attributed) |
|:--|:--|:--|:--|:--|:--|--:|
| 5 | 300/300 | 601 | 464 / 2 972 ms | 75.8 % / 93 % | 31.7 % | 62 MB |
| 4 | 300/300 | 601 | 2 048 / 3 816 ms | 81.4 % / 99 % | 33.5 % | 85 MB |
| 3 | 300/300 | 601 | 824 / 1 480 ms | 79.1 % / 92 % | 32.5 % | 68 MB |
| 2 | 300/300 | 601 | 436 / 1 640 ms | 73.6 % / 92 % | 31.6 % | 64 MB |
| 1 | 300/300 | 601 | 372 / 920 ms | 74.3 % / 90 % | 32.5 % | 61 MB |

The setup and CPU spread across worker sizes is single-run noise on a shared core, not a worker signal: every metric that decides anything is flat.

**Phase 2 — 6 calls/sec (360 offered): the failure boundary.** The same cells at 6 calls/sec fail everywhere, but completion rises monotonically as workers are *removed*:

| | 5 CPS, N=5 | 6 CPS, N=5 | 6 CPS, N=4 | 6 CPS, N=3 | 6 CPS, N=2 | **6 CPS, N=1** |
|:--|:--|:--|:--|:--|:--|:--|
| Completed / offered | **300/300 (100 %)** | 138/360 (38 %) | 184/360 (51 %) | 230/360 (64 %) | 256/360 (71 %) | **302/360 (84 %)** |
| Failed | 0 | 222 | 176 | 130 | 104 | **58** |
| CDR rows / gate | 601 / ok | 319 / ok | 388 / ok | 478 / ok | 531 / ok | 624 / ok |
| Call setup p50 / p95 | 464 / 2 972 ms | 4 580 / 7 652 ms | 3 404 / 6 120 ms | 2 168 / 5 896 ms | 1 804 / 5 472 ms | **1 444 / 5 416 ms** |
| **php-fpm** CPU (busy-window mean / peak) | 75.8 % / 93 % | 83.1 % / 100 % | 90.1 % / 120 % | 88.2 % / 110 % | 89.4 % / 117 % | 88.9 % / 113 % |
| php-fpm share of the core (corrected) | ~55 % | ~64 % | ~61 % | ~61 % | ~60 % | **~61 %** |
| freeswitch CPU (mean / peak) | 31.7 % / 37 % | 16.4 % / 43 % | 24.1 % / 44 % | 24.5 % / 41 % | 26.9 % / 43 % | 26.5 % / 41 % |
| cdr-worker CPU | 2.5 % | 4.4 % | 4.1 % | 3.9 % | 3.7 % | 3.6 % |
| mariadb CPU | 9.8 % | 9.6 % | 10.7 % | 9.9 % | 10.0 % | 9.2 % |
| redis CPU | 3.2 % | 4.1 % | 4.0 % | 3.9 % | 3.9 % | 3.9 % |
| **nginx** CPU | 1.0 % | 0.8 % | 1.0 % | 1.0 % | 1.1 % | 1.2 % |

Every cell passed the CDR gate (rows at least equal to completed calls), ran under zero FreeSWITCH restarts, and restored its baseline afterwards.

**Complete per-service data, for future re-derivation.** CPU is the busy-window mean per class (absolute values read ~25–30 % high per the sampler caveat below; the row sums are the uncorrected totals). RAM is the attributed mean in the same window — held figures run roughly twice as large for the PHP processes because shared code is counted per process.

| Service class | 6 CPS, N=5 | N=4 | N=3 | N=2 | N=1 |
|:--|--:|--:|--:|--:|--:|
| php-fpm | 83.1 % | 90.1 % | 88.2 % | 89.4 % | 88.9 % |
| freeswitch | 16.4 % | 24.1 % | 24.5 % | 26.9 % | 26.5 % |
| mariadb | 9.6 % | 10.7 % | 9.9 % | 10.0 % | 9.2 % |
| queue-worker | 5.4 % | 6.3 % | 5.9 % | 5.9 % | 5.5 % |
| esl-listener | 3.1 % | 4.2 % | 4.2 % | 4.7 % | 4.5 % |
| cdr-worker | 4.4 % | 4.1 % | 3.9 % | 3.7 % | 3.6 % |
| redis | 4.1 % | 4.0 % | 3.9 % | 3.9 % | 3.9 % |
| octane-worker (idle) | 1.6 % | 1.6 % | 1.6 % | 1.7 % | 1.7 % |
| nginx | 0.8 % | 1.0 % | 1.0 % | 1.1 % | 1.2 % |
| reverb | 0.8 % | 0.8 % | 0.8 % | 0.8 % | 0.7 % |
| scheduler | 0.3 % | 0.1 % | 0.2 % | 0.2 % | 0.1 % |
| **Sum (uncorrected)** | 129.8 % | 147.1 % | 143.9 % | 148.3 % | 145.7 % |

| RAM attributed | 6 CPS, N=5 | N=4 | N=3 | N=2 | N=1 |
|:--|--:|--:|--:|--:|--:|
| php-fpm | 58 MB | 71 MB | 68 MB | 64 MB | 61 MB |
| freeswitch | 91 MB | 132 MB | 134 MB | 133 MB | 121 MB |
| mariadb | 44 MB | 64 MB | 67 MB | 84 MB | 89 MB |
| queue-worker | 39 MB | 39 MB | 39 MB | 39 MB | 39 MB |
| cdr-worker | 44 MB | 45 MB | 45 MB | 45 MB | 45 MB |
| esl-listener | 11 MB | 12 MB | 12 MB | 12 MB | 12 MB |
| octane-worker (idle) | 29 MB | 29 MB | 29 MB | 29 MB | 29 MB |
| reverb | 28 MB | 29 MB | 29 MB | 29 MB | 29 MB |
| scheduler | 26 MB | 26 MB | 26 MB | 26 MB | 26 MB |
| nginx | 4 MB | 4 MB | 4 MB | 4 MB | 4 MB |
| redis | 3 MB | 4 MB | 4 MB | 3 MB | 4 MB |
| **Total** | 376 MB | 455 MB | 457 MB | 469 MB | 458 MB |

The RAM totals move with MariaDB buffer churn and with how early each cell's calls failed (the N=5 cell lost most of its calls early and did less database work), not with the PHP-FPM worker count: php-fpm's own share is 58–71 MB throughout.

**Findings.**

1. **Fewer workers lose less at the boundary.** Completion rises 38 % → 84 % and setup p50 falls 4.6 s → 1.4 s as the pool shrinks from 5 to 1 worker. The pool's total CPU share is flat (~60–64 % of one core) across every worker count: the same total work, only a different queue shape. On a saturated single core extra workers deepen the request backlog, so more lookups miss FreeSWITCH's 5 second `mod_xml_curl` deadline. A single-server FIFO queue meets that deadline more often than N parallel workers of equal aggregate capacity.
2. **CDR never suffered.** The Redis buffer plus the `cdr:work` drain absorbed every cell; record integrity held even when 62 % of calls failed. nginx stayed under 1.2 % of the core throughout.
3. **The old "5 static workers" default spent memory on backlog.** One or two workers serve the supported rate identically and hold the boundary far better; 3 is the last ceiling before the curve collapses (4 workers: 51 %, 5 workers: 38 %).

**Installer change.** The installer now configures `pm = dynamic` on every tier with one idle worker (`start_servers = 1`, `min_spare_servers = 1`, `max_spare_servers = 2`), a per-memory ceiling of 3 on minimal hardware (6 on 2 GB, 12 on 4 GB+), and `pm.max_requests = 10000` so long-lived workers recycle. Minimal hardware therefore idles at ~24 MB of PHP-FPM instead of ~83 MB.

**Caveats.** One run per cell on a shared-CPU host (~10 % repeat noise; the monotonic five-point trend is the signal, individual gaps under ~10 points are not findings). The lookup cache was warm in every cell (60 second TTL); cold-cache expiry bursts at the boundary are untested and are the one scenario where more workers may legitimately help. cpuwatch percentages read roughly 25–30 % high in absolute terms (its sample window counts its own scanning time), so the corrected share column is the figure to compare between processes. No panel traffic ran during the cells.

*Artifacts: the series' useful data is consolidated into three versioned files under `load-testing/results/consolidated/` — `worker-sweep-20261108-cells.csv` (one row per cell: completion, CDR rows, setup percentiles, headline CPU/RAM), `worker-sweep-20261108-services.csv` (per-service CPU and RAM per cell) and `worker-sweep-20261108-setup-latency.csv` (every completed call's setup time) — carrying this series and the earlier `cdr-fpm-fast-20261108` comparison cells together, so scenarios stay comparable. The raw per-cell fragments remain git-ignored working data on the host, with a full-fidelity local archive beside them (`worker-sweep-20261108.tar.gz`, outside version control).*

---

### Pre-2vCPU Verification Block and Default Selection (1 vCPU / 1 GB, 2026-11-09)

**Question.** Before resizing the reference host to 2 vCPU, finish the minimal-hardware record: validate the new shipped default, bound run-to-run noise, cover the worker-recycling and cold-cache edge cases, and measure Debian's stock pool shape as a default candidate.

**Configurations compared, every setting spelled out:**

- **Shipped default** — `pm = dynamic`, `pm.start_servers = 1`, `pm.min_spare_servers = 1`, `pm.max_spare_servers = 2`, `pm.max_children = 3`, `pm.max_requests = 10000`. In plain terms: one worker at boot, growing to at most three under load and shrinking back to at most two when quiet.
- **Debian stock** — `pm = dynamic`, `pm.start_servers = 2`, `pm.min_spare_servers = 1`, `pm.max_spare_servers = 3`, `pm.max_children = 5`.
- **Static pools** (the sweep's repeats) — `pm = static` with `pm.max_children` fixed at 5, 2 or 1.

**Comparison.** 60-second cells, per-variant smoke as warm-up, CDR gate on every cell; ranges are two-run bands:

| Configuration | 5 calls/sec (supported rate) | 6 calls/sec (failure boundary) |
|:--|:--|:--|
| static 5 | 300/300 (100 %) | 38–50 % |
| static 4 | 300/300 | 51 % |
| static 3 | 300/300 | 64 % |
| static 2 | 300/300 | 62–71 % |
| static 1 | 300/300 | 84–97 % |
| **Shipped default** | **300/300 (100 %)** | **70 %** |
| **Debian stock** | **235/300 (78 %)** | 52–56 % |

**Findings.**

1. **The shipped default is validated at both rates.** It passes the supported rate completely and sits in the best band at the boundary — the tuned settings buy ~16 points of completion at the boundary over Debian stock.
2. **Debian stock is the only configuration that ever lost calls at the supported rate** (65 of 300 failed; setup p50 1.5 s, p95 5.9 s). Its wider worker range (up to 3 idle, ceiling 5) forks and retires workers at burst edges, and each new worker pays its start cost when the queue is deepest.
3. **Cold cache costs points but does not collapse small pools:** with a zero-TTL dialplan/contributor cache at 6 calls/sec, 2 static workers completed 57 % versus 62–71 % warm.
4. **Worker recycling is safe.** With `pm.max_requests` lowered to 500/400 for the measurement window, the pool recycled repeatedly (16 distinct worker PIDs) with 7 of 7 lookup runs at 100 % success, and a 6 calls/sec cell with recycling mid-call completed 248/360 (69 %) — inside the normal band for that pool. At the shipped 10000 this mechanism is exercised roughly daily on a busy host.
5. **The panel stays reachable under call flood.** A login-page probe inside the PBX during a 6 calls/sec cell answered all 200 samples (HTTP 302), mean 0.33 s, worst 18.65 s — slow, never dark.
6. **The Octane worker removes the boundary entirely.** With the persistent XML worker answering on 8090, the same 6 calls/sec cell completed 360/360 (100 %) with 721 CDR rows — the failure boundary is a property of the PHP-FPM XML path, not of the host. This is the 1 vCPU anchor for the 2 vCPU phase.

This section supersedes the earlier production rule in the 1 vCPU section above (the 3 calls/sec planning figure and its static-worker recommendation): the supported rate is 5 calls/sec with the shipped default, and static pools are retired for these tiers.

*Artifacts: every cell is consolidated in `load-testing/results/consolidated/` (rows tagged `pre-2vcpu-20261108`, `pre-2vcpu-debian-20261108`, `pre-2vcpu-recycle-20261108`, `pre-2vcpu-octane-20261109`), including the 200 panel-probe samples in `worker-sweep-20261108-panel-probe.csv`. Raw fragments stay git-ignored on the host.*

---

### 2 vCPU / 2 GB: Capacity Ladder and Worker Sweep (2026-11-09)

**Question.** After resizing the reference host from 1 vCPU / 1 GB to 2 vCPU / 2 GB, where does the call ceiling sit, and does the pool shape that won on one core still win on two?

**Configuration.** The shipped installer default throughout: `pm = dynamic` with `pm.start_servers = 1`, `pm.min_spare_servers = 1`, `pm.max_spare_servers = 2`, `pm.max_children = 6` (selected automatically because detected RAM is 1,973 MB) and `pm.max_requests = 10000`; fast XML controller, Octane disabled, warm 60-second lookup cache, FreeSWITCH logging at `notice`, full default nftables firewall. Host: 2 vCPU, 1,973 MB RAM, 2 GB swap; build `1333389f`.

**Method.** One 60-second cell per rate, 20 registered endpoints, per-series smoke (registration, extension call, outbound call, CDR gate) before the measured window, CDR gate judging every cell, baseline restored after each. The first failing rate defines the boundary; the worker sweep then runs at that rate only, because a rate below capacity cannot discriminate pool shapes.

**Phase 1 — capacity ladder (shipped dynamic pool, ceiling 6).**

| Offered rate | Completed | CDR rows | Call setup p50 / p95 / max | php-fpm CPU (mean / peak) | freeswitch CPU | Sum of services |
|:--|:--|:--|:--|:--|:--|:--|
| 6 calls/sec | 360/360 (100 %) | 721 | 120 / 232 / 544 ms | 62.2 % / 127 % | 52.6 % | 73 % of both cores |
| 10 calls/sec | 600/600 (100 %) | 1 201 | 1 664 / 3 860 / 4 388 ms | 176.1 % / 239 % | 94.4 % | 168 % of both cores |
| 12 calls/sec | 299/720 (41.5 %) | 635 | 3 844 / 6 860 / 7 636 ms | 225.0 % / 308 % | 63.5 % | 178 % of both cores |

**Phase 2 — static worker sweep at 12 calls/sec.** Same rate, same variant, same warm cache; only the pool shape changes.

| Pool | Completed | Call setup p50 / p95 / max |
|:--|:--|:--|
| static 1 | 317/720 (44.0 %) | 4 624 / 6 688 / 8 204 ms |
| static 2 | 258/720 (35.8 %) | 4 892 / 8 020 / 8 544 ms |
| static 4 | 311/720 (43.2 %) | 4 924 / 7 592 / 8 608 ms |
| static 5 | 248/720 (34.4 %) | 5 436 / 7 504 / 7 944 ms |
| dynamic, ceiling 6 (baseline) | 299/720 (41.5 %) | 3 844 / 6 860 / 7 636 ms |

**Findings.**

1. **Capacity roughly doubles with the second core: about 10 calls/sec clean on 2 vCPU versus 5 on 1 vCPU.** 10 calls/sec completes every call; 12 calls/sec loses 58 % of them. The boundary is between the two.
2. **The ceiling is CPU, not the pool and not CDR.** At 12 calls/sec PHP-FPM alone demands 2.25 cores on a 2-core host, so the machine is oversubscribed by 1.78× across all services. CDR integrity held at every rate and under 58 % call loss, so ingestion is not the failure mode.
3. **Worker count is second-order on two cores.** Completion across five pool shapes spans 34–44 % — 10 points — and the ordering is non-monotonic (1 > 4 > dynamic > 2 > 5). One run each, so the differences are not separable from run-to-run noise. On 1 vCPU the same comparison spanned 59 points and was strictly ordered.
4. **Latency still degrades monotonically with more workers.** Median setup rises 4 624 → 4 892 → 4 924 → 5 436 ms as workers go 1 → 2 → 4 → 5, so the queue-depth mechanism survives even though it no longer decides the outcome.
5. **The shipped dynamic pool is the right default on this tier.** Mid-pack completion with the best median setup time of the five shapes (3 844 ms), and it needs no tuning per host.
6. **The Octane worker clears the boundary entirely.** The same 12 calls/sec cell that defeated every PHP-FPM shape completed **720/720 (100 %)** through the persistent worker on port 8090, with 1 441 CDR rows and a median setup of **284 ms** versus 3 844 ms — a 13× improvement that matches the 1 vCPU result (360/360 at 6 calls/sec where FPM scored 70 %). The call-rate ceiling is therefore a property of the PHP-FPM XML path, not of the host, at both tiers. One caveat: the sampler books RoadRunner child processes under `php-fpm`, so the per-service CPU split for this cell is unreliable.

**Data limitation.** The four sweep cells shared one artifact directory (`…/2vcpu-20261109-sweep/h2-2c2g/v-fast/sip-cps12`), so each run overwrote the previous `cpu.csv`. Completion, CDR reconciliation and per-call setup latency survived for all four (latency files were attributed by row count, which matched each cell's completed-call count exactly), but per-service CPU and RAM survive only for the three ladder cells. The consolidated rows for the sweep therefore carry blank CPU columns rather than reconstructed values. The harness should include the pool shape in the artifact path before any further sweep.

*Artifacts: consolidated rows tagged `2vcpu-20261109` (ladder), `2vcpu-20261109-sweep` (worker sweep) and `2vcpu-20261109-octane` (persistent worker) in `load-testing/results/consolidated/`. Raw fragments stay git-ignored on the host.*
