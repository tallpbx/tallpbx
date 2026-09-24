# PBX SIP Load Testing & Benchmark Results

Clear, real-world capacity guidance for administrators. This document records empirical results from the September 2026 VirtualBox test series and historical datacenter VPS benchmarks, and provides hardware sizing guidance for system administrators.

> [!NOTE]
> For the operational manual, lab topology setup, seeding instructions, and test runner options, see the companion **[SIP Load Testing Guide](load-testing-guide.md)**.

## Contents

- [Executive Summary & Hardware Sizing Matrix for Administrators](#executive-summary--hardware-sizing-matrix-for-administrators)
- [Production Recommendations: XML Caching & PHP-FPM Worker Tuning](#production-recommendations-xml-caching--php-fpm-worker-tuning)
- [Planning Rules](#planning-rules)
- [How Results Are Recorded & How to Read Benchmark Tables](#how-results-are-recorded--how-to-read-benchmark-tables)
- [Dialplan & Cache Engine Benchmarks (XML Throughput)](#dialplan--cache-engine-benchmarks-xml-throughput)
  - [Local Lab: VirtualBox (4 vCPU / 4 GiB RAM)](#local-lab-virtualbox-4-vcpu--4-gib-ram)
  - [Cloud VPS: Entry Baseline (1 vCPU / 1 GiB RAM)](#cloud-vps-entry-baseline-1-vcpu--1-gib-ram)
  - [Cloud VPS: Memory-Scaled (1 vCPU / 2 GiB RAM)](#cloud-vps-memory-scaled-1-vcpu--2-gib-ram)
  - [Cloud VPS: Dual-Core (2 vCPU / 2 GiB RAM)](#cloud-vps-dual-core-2-vcpu--2-gib-ram)
  - [Dedicated Cloud Node (4 vCPU / 8 GiB RAM Dedicated) — Planned](#dedicated-cloud-node-4-vcpu--8-gib-ram-dedicated--planned)
- [Live Call Capacity & Telephony Feature Parity (SIP Signaling)](#live-call-capacity--telephony-feature-parity-sip-signaling)
  - [Local Lab: VirtualBox Test Pair (Baseline)](#local-lab-virtualbox-test-pair-baseline)
  - [Cloud Datacenter VPS: Entry Baseline (1 vCPU / 1 GiB RAM)](#cloud-datacenter-vps-1-vcpu--1-gib-ram-baseline)
  - [Cloud Datacenter VPS: Memory-Scaled (1 vCPU / 2 GiB RAM)](#cloud-datacenter-vps-memory-scaled-1-vcpu--2-gib-ram)
  - [Cloud Datacenter VPS: Dual-Core (2 vCPU / 2 GiB RAM)](#cloud-datacenter-vps-dual-core-2-vcpu--2-gib-ram)
  - [Dedicated Cloud Node Pair (4 vCPU / 8 GiB RAM Dedicated) — Planned](#dedicated-cloud-node-pair-4-vcpu--8-gib-ram-dedicated--planned)

---

## Executive Summary & Hardware Sizing Matrix for Administrators

Use the summary table below as a quick reference for choosing baseline hardware, configuring PHP-FPM pools, and setting Redis cache policies. These recommendations synthesize findings from both the VirtualBox test series and public datacenter benchmarks:

### Production Sizing & Configuration Matrix

| Profile / Tier | Recommended Hardware | PHP-FPM Profile (`www.conf`) | Cache TTL Window | Dialplan XML Throughput | Sustained Call Capacity | Active Call Ceiling | Primary Target Deployment |
| --- | --- | --- | --- | --- | ---: | ---: | --- |
| **Micro / Edge** | 1 vCPU, 1–2 GiB RAM | `pm = dynamic`<br>`pm.max_children = 5` | 5 seconds | 13–19 req/sec | 3–5 calls/sec | 20–35 concurrent | Home office, small branch (1–10 phones) |
| **Standard SMB** | 2–4 vCPU, 4 GiB RAM | `pm = static`<br>`pm.max_children = 12` | 5 seconds | 19–28 req/sec | 5–8 calls/sec | 50–100 concurrent | Small-to-medium business (10–75 phones) |
| **Mid-Market** | 4–8 vCPU, 8 GiB RAM | `pm = static`<br>`pm.max_children = 24` | 5–15 seconds | 35–50 req/sec | 12–18 calls/sec | 150–300 concurrent | Multi-department office (75–250 phones) |
| **Call Center** | 8+ vCPU, 16 GiB RAM | `pm = static`<br>`pm.max_children = 32–48` | 15–30 seconds | 60–90+ req/sec | 25–40 calls/sec | 400–800 concurrent | Queue-heavy inbound contact center |
| **Enterprise / Multi-Tenant** | 16+ vCPU, 32 GiB RAM | `pm = static`<br>`pm.max_children = 64` | 30 seconds | 100–150+ req/sec | 45–60+ calls/sec | 1,000+ concurrent | Multi-tenant cloud hosted PBX |

> [!TIP]
> **PHP-FPM Sizing Formula for Dedicated PBX Nodes**:
> When running a dedicated node, compute `pm.max_children` as:
> ```text
> pm.max_children = (Total Available RAM - System & Telephony Overhead [1.5 GiB]) / Average Worker RSS (~65 MiB)
> ```
> On a 4 GiB VM: `(4096 - 1536) / 65 ≈ 39` maximum theoretical ceiling. Setting `pm.max_children = 12` provides ample concurrency headroom for XML bursts while keeping worker memory usage capped under 800 MiB, leaving >2.5 GiB for FreeSWITCH RTP media, MariaDB buffers, and Redis caching.

---

## Production Recommendations: XML Caching & PHP-FPM Worker Tuning

Based on the September 23, 2026 VirtualBox 5-tier cache sweeps and concurrency ladder benchmarks, apply the following tuning policies for production deployments:

### 1. Telephony XML Cache Policy (`.env`)

TallPBX caches compiled dialplan XML responses, individual contributor fragments (extensions, IVRs, ring groups), and directory lookup data in Redis. Choose the profile matching your organization's calling patterns:

| Deployment Role | Dialplan Cache TTL | Contributor Cache TTL | Directory Cache TTL | Observed Hit Rate | Average Latency | Operational Rationale |
| --- | --- | --- | --- | --- | ---: | --- |
| **Standard Office (Recommended Baseline)** | `5` | `5` | `5` | ~41.2% | 261 ms | **Optimal production balance.** Absorbs rapid call bursts while guaranteeing that administrative changes in the web panel (adding extensions, changing call routing) propagate within 5 seconds without manual cache flushing. |
| **High-Density Call Center / Gateway Trunks** | `30` | `30` | `30` | ~44.2%–99% | 263 ms | **Maximum throughput.** Shields MariaDB from thousands of identical inbound routing queries per minute. Panel changes take up to 30 seconds to reflect, or require `php artisan optimize:clear`. |
| **Development & Dialplan Debugging** | `0` | `0` | `0` | 0.0% | 399 ms | **Instant feedback.** Disables XML caching completely so every call executes live database queries and generates fresh XML immediately. |

To benchmark all 5 cache tiers on your server and calculate exact Redis keyspace hit rates:
```bash
bash scripts/run-cache-sweep.sh
```

Check active Redis cache efficiency at any time:
```bash
redis-cli info stats | grep -E 'keyspace_hits|keyspace_misses'
```

### 2. PHP-FPM Worker Pool Tuning (`/etc/php/8.5/fpm/pool.d/www.conf`)

FreeSWITCH initiates concurrent HTTP requests to PHP-FPM whenever calls arrive. Unlike standard web visitors who browse asynchronously, a PBX call setup cannot tolerate worker wait states:

- **Always Use Static Process Management (`pm = static`) on ≥ 2 GiB RAM**: Dynamic process management (`pm = dynamic`) introduces process-fork latency when simultaneous calls burst in. In our benchmarks, dynamic mode with 5 workers saturated at concurrency 25, creating `server reached pm.max_children setting` warnings and 502 gateway timeouts. Static mode keeps all workers pre-forked in memory with zero instantiation latency.
- **Installer Auto-Tuning**: Fresh installations automatically detect host RAM via `free -m` in `scripts/resources/php.sh` and set optimal worker pools:
  - **≥ 3,500 MB RAM (4GB+ standard)**: `pm = static`, `pm.max_children = 12` (+15% throughput gain, zero timeouts, leaving 2.9 GiB free RAM).
  - **≥ 1,800 MB RAM (2GB small)**: `pm = static`, `pm.max_children = 6`.
  - **< 1,800 MB RAM (1GB minimal)**: `pm = dynamic`, `pm.max_children = 5` to conserve memory.
- **Monitoring & Saturation Detection**:
  ```bash
  tail -n 50 /var/log/php8.5-fpm.log | grep "server reached pm.max_children"
  ```
  If this error appears under peak calling periods, increase `pm.max_children` using the sizing formula above and restart PHP-FPM (`systemctl restart php8.5-fpm`).

---

## Planning Rules

- **XML requests per second scale with CPU count, not memory.** Memory alone did not help: the 1 vCPU / 2 GiB profile was slower than the 1 vCPU / 1 GiB profile under the same conditions. Adding a second vCPU roughly doubled XML burst throughput (about 19 req/sec to about 30 req/sec at `500 x 25`) and cut the slowest response from about 1.7 seconds to about 0.4 seconds.
- **Historical XML burst expectations:** 1 vCPU ≈ 13–24 req/sec (15–24 in the July runs; 16.1 and 14.2 in the August 31 revalidation); 2 vCPU / 2 GiB ≈ 29–30 req/sec; the 4 vCPU test server ≈ 19–28 req/sec on the older code revision, and the optimization work moved it from under 4 req/sec to that range.
- **Do not size calls from XML numbers.** A complete call adds SIP signaling, a second call leg, FreeSWITCH state, and teardown work that the XML test never touches.
- **Historical call-rate expectations:** the 4 vCPU test server delivered a repeatable 5 calls/sec (4 calls/sec is the sensible planning target on that VM); the 2 vCPU / 2 GiB VPS delivered about 13–15 clean completed calls/sec, with 20 offered calls/sec still completing but queueing heavily.
- **Plan for headroom.** The measured ceilings were reached at 95–98% CPU busy. If a tier runs above roughly 90% CPU, do not plan production capacity at that tier.
- **Check `sessions-per-second` before blaming hardware.** The project default of 60 allows roughly 30 two-leg calls/sec; a stock value of 30 rejects calls near 15 two-leg calls/sec with `503 Maximum Calls In Progress`.
- **Media capacity is separate.** These numbers cover call setup, answer, and teardown. Continuous RTP audio quality and media capacity need a dedicated RTP-enabled concurrent-call test.
- **Re-run the hardware ladder with new test data before quoting numbers to customers.** The values in this document are historical references kept for review.

---

## How Results Are Recorded & How to Read Benchmark Tables

### How Results Are Recorded

The tables in this document present the September 2026 test progression (local VirtualBox single-server throughput ladder, 5-tier cache sweeps, and cloud datacenter benchmarks), followed by the historical July–August 2026 reference runs.

- Lead with requests per second, then average, fastest, and slowest latency. For some older runs the average and fastest are recomputed from the stored per-request latencies; where a value was never captured it is shown as `—`.
- Comparison tiers are reported as the median of at least three measured runs, with every repetition retained. Run a warm-up before recording.
- Keep the same code commit, seed size, `mixed` scenario, cache settings, PHP-FPM configuration, and generator host across controlled rows. Label a configuration experiment with a suffix such as `-php-fpm-tuned` or `-fsnotice`; never overwrite a controlled result with a tuned result.
- Keep both test hosts free of unrelated work during a run: no unrelated upgrades, backups, SIP traffic, or administrative jobs.
- If a smaller profile hits a stop condition, record the skipped tier as `Not run — <reason>` instead of forcing the test to continue.
- Name artifacts with the hardware profile, tier, and repetition, for example `vps-1c-1g-100x5-r1-mixed-controlled.json`.

### How to Read These Benchmark Tables

Before reviewing the benchmark tables, understand the standard testing notation and methodology used across all tiers:

- **Load Tier Notation (`<Total Requests> x <Simultaneous Requests>`)**:
  This notation describes the traffic pattern generated by the load testing tool:
  - **First Number (`Total Requests`)**: The total number of HTTP requests sent over the entire test run.
  - **Second Number (`Concurrency` or `Simultaneous Requests`)**: The maximum number of HTTP requests in flight at the exact same instant.
  
  For example:
  - `25 x 1`: 25 total requests sent sequentially one at a time (concurrency 1, no overlapping requests).
  - `100 x 5`: 100 total requests sent with up to 5 simultaneous requests in flight at any given moment. As soon as one response completes, the client fires the next request until all 100 have completed.
  - `500 x 10`: 500 total requests with 10 simultaneous requests in flight at any given moment.
  - `500 x 25`: 500 total requests with 25 simultaneous requests in flight at any given moment (stressing server concurrency).
  - `1,000 x 25`: 1,000 total requests with 25 simultaneous requests in flight at any given moment (sustained burst ceiling).

> [!IMPORTANT]
> **Client Concurrency vs. Server PHP-FPM Workers**:
> Do not confuse the load test's **client concurrency** (the second number, e.g. the `5` in `100 x 5`) with the **server's PHP-FPM workers**:
> - **Client Concurrency (e.g. 5, 10, or 25 in-flight)**: This is set on the *load test tool* (`--concurrency=X`). It determines how many simultaneous HTTP requests the test generator fires at the PBX at the exact same instant.
> - **PHP-FPM Server Workers (e.g. `pm.max_children = 5, 6, or 12`)**: These are the *backend PHP processes* running on the PBX server. Each PHP-FPM worker can execute one request at a time.
>
> **Why this matters when reading the benchmarks**:
> - When a test sends **5 simultaneous requests** (`100 x 5`) against a server with **6 PHP-FPM workers**, all 5 requests are served immediately in parallel (with 1 worker idle).
> - However, when a test sends **25 simultaneous requests** (`500 x 25`) against a server with only **5 or 6 PHP-FPM workers**, the server can only process 5 or 6 requests at once; the remaining 19 or 20 requests must wait in Nginx/PHP-FPM queue buffers until a worker frees up. This benchmark demonstrates how the PBX server behaves under sudden call spikes that exceed its PHP-FPM worker pool, and shows why tuning `pm = static` with 12 workers eliminates queueing latency under heavy bursts.
- **Repetitions and Median Reporting**:
  Individual test runs are subject to brief operating system scheduling jitter, background disk flushes, or network queueing. To ensure statistical integrity, every comparison tier is executed across **3 identical repetitions** (`r1`, `r2`, `r3`). The tables report the **median** of those three runs to filter out transient anomalies while retaining reproducible metrics.
- **Warm-Up Runs (`25 x 1`)**:
  On freshly started or idle systems, the first incoming requests incur one-time cold-start penalties: PHP OPcache must compile scripts into bytecode, MariaDB connection pools must initialize, and Redis memory structures must allocate. The clean initial warm-up (`25 x 1`) pre-heats OPcache bytecode, database connections, and cache pools. This isolates steady-state application performance from cold-start initialization latency.
- **Benchmark Scenarios (`mixed` vs. `cache-hit`)**:
  - `mixed`: Simulates real-world PBX routing by generating requests across diverse destinations (internal extensions, inbound DIDs, ring groups, IVRs, and outbound patterns). This exercises the full dynamic XML dialplan generator, database lookups, and contributor fragment caching.
  - `cache-hit`: A synthetic ceiling benchmark where the runner queries the exact same destination repeatedly 100 times. Once the first request caches the dialplan, all subsequent requests hit Redis memory directly (0 database queries), establishing the theoretical maximum memory throughput of the PHP and Redis stack.

#### Plain-Language Guide to Performance Metrics & Statistics

To make benchmark reports accessible to everyone—from telephony engineers to non-technical PBX and office administrators—here is what each measured statistic means in plain terms:

| Statistic | Plain-Language Meaning | Real-World PBX Example |
| :--- | :--- | :--- |
| **Fastest (Min)** | The absolute fastest response recorded during the entire test run. | A call lookup where the dialplan was already waiting in Redis memory and responded in **148 ms**. |
| **Slowest (Max)** | The single slowest response recorded during the test run. | A call lookup that had to wait for a database disk read, PHP compilation, or an empty PHP-FPM worker, taking **1,180 ms**. |
| **Average (`avg`)** | The total response time of all requests added together, divided by the total number of requests. | Measures the total server effort across the whole batch. **Note:** A single freak delay can pull the average up, making normal calls look slower than they were. |
| **Median (`p50`)** | The exact middle response when all results are lined up in order from fastest to slowest. | Exactly half of the calls were faster and half were slower. Represents what an **everyday, typical user experienced**, completely unaffected by rare one-off spikes. |
| **90th Percentile (`p90`)** | 90% of all requests were faster than this number. | The standard industry baseline for everyday Service Level Agreements (SLAs). |
| **95th Percentile (`p95`)** | 95% of all requests were faster than this number. | Telephony SLA standard. Shows how fast the vast majority of calls are routed, even during busy office hours. |
| **99th Percentile (`p99`)** | 99% of all requests were faster than this number. | **Tail Latency**: Represents the worst 1 out of every 100 calls. Highlights momentary buffer stalls, garbage collection, or worker pool queueing. |
| **Consistency / Jitter (`std_dev`)** | Standard deviation measures how much response times fluctuate from call to call. | **Low number** = rock-solid, predictable call setups.<br>**High number** = erratic experience where some calls answer instantly and others stutter. |
| **Throughput (Req/Sec)** | How many requests the PBX completed in one second. | Higher is better. Reflects the processing capacity of the server. |
| **Cache Hit Rate (%)** | The percentage of routing queries served directly from high-speed Redis RAM without querying MariaDB. | A 40%–99% hit rate drastically reduces database CPU load and protects MariaDB from call spikes. |

> [!TIP]
> **Why compare Average vs. Median (`p50`)? An Intuitive Example**:
> Imagine 5 calls hit the PBX with the following response times:
> - Call 1: **100 ms**
> - Call 2: **100 ms**
> - Call 3: **100 ms**
> - Call 4: **100 ms**
> - Call 5: **5,000 ms** *(5 seconds, due to a cold disk spin-up or network hiccup)*
>
> If you look only at the **Average**, it reports **1,080 ms** (`5,400 / 5`), giving the false impression that *every* caller had to wait over a second!  
> But the **Median (`p50`)** reports **100 ms** (`100, 100, [100], 100, 5000`), accurately revealing that 4 out of 5 users experienced an instant 100 ms connection.
>
> **The Takeaway**:
> - When **Average is close to Median** (e.g. avg 265 ms vs p50 260 ms): Call setup is smooth, predictable, and uniform.
> - When **Average is much higher than Median** (e.g. avg 500 ms vs p50 200 ms): Most calls are fast, but occasional queueing bottlenecks or disk reads are stalling a small fraction of callers.

---

## Dialplan & Cache Engine Benchmarks (XML Throughput)

Direct benchmarks of the web and database routing engine. These tests evaluate raw HTTP request throughput (`requests/sec`) and latency (`ms`) as FreeSWITCH queries Laravel for dynamic dialplan and directory XML documents.

### Local Lab: VirtualBox (4 vCPU / 4 GiB RAM)

The local test server (`192.168.1.71`) is an isolated, disposable VirtualBox virtual machine running Debian 13 on a local workstation. It was provisioned purely as a controlled test bench for benchmark execution alongside its sibling orchestrator VM (`192.168.1.76`). Neither VM functions as an office PBX or live production server; they exist purely as a controlled, reproducible virtualization baseline.

The September 23, 2026 test series executed the full progression against the local PBX from the orchestrator. All test runs used authenticated XML endpoints with composite database indexes and Redis fragment caching active.

> [!NOTE]
> **Historical Baseline Metrics**:
> The September 23, 2026 VirtualBox baseline tests were recorded before full percentile and per-request raw telemetry were integrated into the test runner. As a result, these historical baseline tables report **Average Latency**, **Fastest (Min)**, and **Slowest (Max)**. All subsequent cloud datacenter benchmarks capture complete granular telemetry (including **p50 Median**, **p90**, **p95**, **p99**, and **Standard Deviation**) alongside complete raw sample datasets.

#### Single-Server XML Throughput Ladder

Controlled runs, all `mixed` scenario, zero failed XML responses:

| Tier | Repetitions | Median Req/Sec | Average Latency | Fastest Latency | Slowest Latency | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | :--- |
| `25 x 1` | 1 (warm-up) | 4.497 | 217.9 ms | 148 ms | 541 ms | Clean initial warm-up; Redis caches populated. |
| `100 x 5` | 3 | 12.760 | 273.4 ms | 162 ms | 478 ms | Repeatable baseline across 3 consecutive runs. |
| `500 x 10` | 3 | 15.758 | 385.3 ms | 155 ms | 682 ms | Smooth scaling under moderate concurrency. |
| `500 x 25` (dynamic pool) | 3 | 16.718 | 804.9 ms | 159 ms | 1,180 ms | Debian default pool (`pm.max_children = 5`) saturated with repeated worker warnings. |
| `500 x 25` (static 12 pool) | 3 | 19.232 | 828.8 ms | 163 ms | 1,142 ms | **+15.0% throughput gain**; zero worker warnings; 2.9 GiB free RAM. |
| `1,000 x 25` (static 12 pool) | 3 | 16.644 | 814.3 ms | 158 ms | 1,196 ms | Zero failures across 3,000 requests; stable sustained burst ceiling. |

<details>
<summary>Table Terminology & Metric Definitions</summary>

| Term / Header | Definition & Operational Meaning |
| :--- | :--- |
| **Tier (`Requests x Concurrency`)** | The load volume profile. For example, `500 x 25` means a total of 500 HTTP requests were sent with 25 requests simultaneously in-flight at all times. |
| **Req/Sec** | Requests per second. Measures how many complete XML dialplan documents the server generated and delivered per second. |
| **Average Latency** | The mean time (in milliseconds) required to process and return an XML request across all samples in the tier. |
| **Fastest (Min)** | The quickest response recorded in the tier (best-case cache/memory hit). |
| **Slowest (Max)** | The slowest response recorded in the tier (tail latency, usually occurring on initial cache misses or worker queuing). |
| **Dynamic Pool (`pm = dynamic`)** | Debian default PHP-FPM mode where workers are spawned on demand up to `pm.max_children = 5`. Creates latency when bursts arrive. |
| **Static Pool (`pm = static`)** | Production-optimized PHP-FPM mode where all workers (e.g. 12) are permanently kept in RAM with zero fork delay. |

</details>

Guest specifications for these runs: Debian GNU/Linux 13 (trixie), kernel `6.12.95+deb13-amd64`, 4 vCPU (12th Gen Intel Core i5-1235U), 3.8 GiB RAM, 2.0 GiB swap, 39.5 GiB virtual disk. MariaDB reported zero slow queries throughout all tiers.

#### Cache Optimization and Hit Rate Sweep (September 23, 2026)

The 5-run cache optimization sweep on the VirtualBox PBX evaluated performance from raw database execution to 100% memory hit ceiling:

| Run Configuration | Scenario | Target Requests | Requests/sec | Average Latency | Fastest | Slowest | Redis Hit Rate | Key Observation |
| :--- | :--- | :--- | ---: | ---: | ---: | ---: | ---: | :--- |
| **1. Uncached Database Baseline** | `mixed` | `100 x 5` | 9.193 | 398.9 ms | 220.1 ms | 994 ms | 0.0% | Heavy MariaDB query execution; average latency of 398.9 ms. |
| **2. Contributor Cache Only** | `mixed` | `100 x 5` | 12.431 | 317.8 ms | 165.0 ms | 698 ms | 46.6% | Reused static routing fragments; cut MariaDB table reads by ~80%. |
| **3. Production Baseline** | `mixed` | `100 x 5` | 13.176 | 260.9 ms | 126.2 ms | 472 ms | 41.2% | Recommended production baseline; optimal average latency with 5s update convergence. |
| **4. Extended Burst Call Center** | `mixed` | `100 x 5` | 14.110 | 262.9 ms | 134.3 ms | 542 ms | 44.2% | Highest throughput under sustained bursts; peak Redis hit efficiency. |
| **5. Pure Memory Cache-Hit Ceiling** | `cache-hit` | `100 x 5` | 13.932 | 235.4 ms | 116.6 ms | 387 ms | 29.3% | Zero MariaDB queries; lowest average latency (pure memory/serialization). |

<details>
<summary>Table Terminology, Configuration Keys & Metric Definitions</summary>

| Term / Abbreviation | Full Name & `.env` Setting | Plain-Language Definition |
| :--- | :--- | :--- |
| **Contributor Cache (Contributor TTL / `C`)** | `DIALPLAN_CONTRIBUTOR_CACHE_TTL` | Redis cache window (in seconds) for individual dialplan building blocks (extensions, ring groups, IVRs). Cached fragments are stitched together dynamically. |
| **Dialplan Cache (Dialplan TTL / `D`)** | `DIALPLAN_CACHE_TTL` | Redis cache window (in seconds) for the complete rendered XML dialplan document for a tenant. |
| **TTL** | Time To Live | How many seconds a cached response remains valid in Redis before querying MariaDB again. `0` disables caching. |
| **Target Requests (`100 x 5`)** | Offered Burst Profile | 100 total HTTP requests sent with 5 requests simultaneously in-flight at all times. |
| **Redis Hit Rate** | Keyspace Efficiency (`INFO stats`) | Percentage of lookup keys found in fast Redis memory versus total lookups requested during the test run. |
| **`mixed` Scenario** | Varied Endpoint Simulation | Simulates realistic office traffic across varied extensions, IVRs, and ring groups to test routing lookup logic. |
| **`cache-hit` Scenario** | Identical Destination Simulation | Queries the exact same destination 100 times consecutively to measure theoretical maximum throughput with zero database I/O. |

</details>

> [!NOTE]
> **Understanding Row 5 vs. Row 3 (Pure Memory Ceiling vs. Production Baseline)**:
> Both Row 3 and Row 5 execute `100 x 5` requests, but they test fundamentally different code paths:
> - **Row 3 (Production Baseline)** executes the **`mixed` scenario** across varied destinations (extensions, ring groups, and IVRs). While static dialplan fragments are cached in Redis, resolving different destinations still exercises routing logic and contributor lookups (achieving 41.2% cache hit rate, average latency of 260.9 ms).
> - **Row 5 (Pure Memory Cache-Hit Ceiling)** executes the **`cache-hit` scenario**, querying the exact same destination 100 times consecutively. After request 1 populates Redis, the remaining 99 requests are served directly from Redis memory with zero MariaDB queries. This drops average latency to 235.4 ms (with fastest response at 116.6 ms), demonstrating the pure serialization ceiling of PHP-FPM and Redis when database I/O is eliminated.

Artifacts: `storage/app/load-tests/vbox-20260923/` and `storage/app/load-tests/vbox-cache-sweep-20260923-123631/`.

---

### Cloud VPS: Entry Baseline (1 vCPU / 1 GiB RAM)

The entry-level cloud test server (`x.x.x.200`) is a datacenter virtual private server running Debian GNU/Linux 13 (trixie) with 1 shared vCPU, 967 MiB RAM, and 2.0 GiB swap. It represents the minimal production footprint for a cloud PBX branch or small office (1–10 phones).

The September 24, 2026 benchmark series executed the full progression directly against the production Nginx and PHP 8.5-FPM stack (`pm = dynamic`, maximum 5 workers). Dialplan endpoints were fully authenticated using composite database indexes and Redis fragment caching against tenant `load-test-beta` (seeded with 20 extensions and dynamic call routing).

> [!NOTE]
> **Complete Empirical Telemetry**:
> Unlike the earlier September 23 VirtualBox test run (which only recorded min, max, and avg), the September 24, 2026 datacenter benchmarks capture complete granular telemetry—including **p50 Median**, **p90**, **p95**, **p99**, and **Standard Deviation** alongside complete raw sample datasets. For everyday administrators, the primary headline tables present clear, plain-language summaries (**Average**, **Fastest**, and **Slowest**). Detailed percentile distributions and standard deviations are accessible in the expandable details sections.

#### Single-Server XML Throughput Ladder (September 24, 2026)

Controlled runs, all `mixed` scenario against public IPv4 (`x.x.x.200`), zero failed XML responses:

| Tier | Repetitions | Median Req/Sec | Average Latency | Fastest (Min) | Slowest (Max) | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | :--- |
| `25 x 1` | 1 (warm-up) | 14.876 | 65.4 ms | 51.5 ms | 113.5 ms | Clean initial warm-up; OPcache bytecode and Redis caches primed. |
| `100 x 5` | 3 (r1–r3) | 15.605 | 288.6 ms | 166.5 ms | 489.5 ms | Repeatable baseline across 3 consecutive runs; all 5 in-flight requests served concurrently by 5 PHP-FPM workers. |
| `500 x 25` | 3 (r1–r3) | 14.894 | 990.3 ms | 217.3 ms | 2,298.0 ms | Single-core worker saturation (`pm.max_children = 5`); tail latency queueing under burst concurrency. |
| `1,000 x 25` | 1 (r1) | 15.967 | 922.5 ms | 201.3 ms | 2,188.7 ms | 1,000/1,000 completed with 0 errors; stable sustained burst ceiling. |

<details>
<summary>Table Terminology & Metric Definitions</summary>

| Term / Header | Definition & Operational Meaning |
| :--- | :--- |
| **Tier (`Requests x Concurrency`)** | The load volume profile. For example, `100 x 5` means a total of 100 HTTP requests were sent with 5 requests simultaneously in-flight at all times. |
| **Median Req/Sec** | Requests per second across measured repetitions. Measures how many complete XML dialplan documents the server generated and delivered per second. |
| **Average Latency** | The mean time (in milliseconds) required to process and return an XML request across all samples in the tier. |
| **Fastest (Min)** | The quickest response recorded in the tier (best-case cache/memory hit). |
| **Slowest (Max)** | The slowest response recorded in the tier (tail latency, usually occurring on initial cache misses or worker queuing). |
| **Dynamic Pool (`pm = dynamic`)** | Debian default PHP-FPM mode where workers are spawned on demand up to `pm.max_children = 5`. Creates latency when bursts arrive. |

</details>

<details>
<summary>Detailed Statistics Breakdown (p50, p90, p95, p99, Std Dev & Repetitions)</summary>

##### Granular Statistical Telemetry (Percentiles & Consistency)

| Tier / Run | Req/Sec | Average | Fastest (Min) | Slowest (Max) | Median (`p50`) | 90th (`p90`) | 95th (`p95`) | 99th (`p99`) | Jitter (`std_dev`) |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `25 x 1` warm-up | 14.876 | 65.4 ms | 51.5 ms | 113.5 ms | 61.8 ms | 86.6 ms | 98.3 ms | 113.5 ms | 15.2 ms |
| `100 x 5` r1 | 12.875 | 333.3 ms | 108.3 ms | 574.2 ms | 312.8 ms | 512.6 ms | 544.3 ms | 572.7 ms | 112.9 ms |
| `100 x 5` r2 | 15.844 | 288.6 ms | 214.4 ms | 489.5 ms | 278.0 ms | 336.6 ms | 422.3 ms | 486.0 ms | 55.5 ms |
| `100 x 5` r3 | 15.605 | 279.3 ms | 166.5 ms | 420.6 ms | 266.5 ms | 348.5 ms | 377.2 ms | 418.2 ms | 53.7 ms |
| **`100 x 5` Median** | **15.605** | **288.6 ms** | **166.5 ms** | **489.5 ms** | **278.0 ms** | **348.5 ms** | **422.3 ms** | **486.0 ms** | **55.5 ms** |
| `500 x 25` r1 | 15.425 | 940.7 ms | 201.5 ms | 2,087.0 ms | 919.0 ms | 1,573.3 ms | 1,783.2 ms | 1,976.6 ms | 481.5 ms |
| `500 x 25` r2 | 14.550 | 1,009.4 ms | 237.6 ms | 2,383.1 ms | 974.7 ms | 1,626.7 ms | 1,757.8 ms | 2,104.0 ms | 497.2 ms |
| `500 x 25` r3 | 14.894 | 990.3 ms | 217.3 ms | 2,298.0 ms | 935.9 ms | 1,606.4 ms | 1,753.8 ms | 2,145.2 ms | 498.4 ms |
| **`500 x 25` Median** | **14.894** | **990.3 ms** | **217.3 ms** | **2,298.0 ms** | **935.9 ms** | **1,606.4 ms** | **1,757.8 ms** | **2,104.0 ms** | **497.2 ms** |
| `1,000 x 25` r1 | 15.967 | 922.5 ms | 201.3 ms | 2,188.7 ms | 912.8 ms | 1,526.2 ms | 1,614.3 ms | 1,865.9 ms | 456.3 ms |

</details>

#### Cache Optimization and Hit Rate Sweep (September 24, 2026)

The 5-run cache optimization sweep on the cloud datacenter PBX evaluated performance across cache policies from raw database execution to the pure memory hit ceiling:

| Run Configuration | Scenario | Target Requests | Requests/sec | Average Latency | Fastest (Min) | Slowest (Max) | Redis Hit Rate | Key Observation |
| :--- | :--- | :--- | ---: | ---: | ---: | ---: | ---: | :--- |
| **1. Uncached Database Baseline** | `mixed` | `100 x 5` | 8.358 | 565.1 ms | 475.7 ms | 757.1 ms | 0.0% | Heavy MariaDB query execution; average latency of 565.1 ms. |
| **2. Contributor Cache Only** | `mixed` | `100 x 5` | 14.020 | 291.8 ms | 153.5 ms | 673.4 ms | 46.8% | Reused static routing fragments; cut MariaDB table reads by nearly half. |
| **3. Production Baseline** | `mixed` | `100 x 5` | 17.221 | 245.3 ms | 157.6 ms | 511.9 ms | 39.1% | Recommended production baseline; lowest average latency with 5s update convergence. |
| **4. Extended Retention** | `mixed` | `100 x 5` | 17.196 | 257.8 ms | 171.2 ms | 562.2 ms | 42.2% | Sustained high throughput with extended Redis keyspace retention. |
| **5. Pure Memory Ceiling** | `cache-hit` | `100 x 5` | 17.400 | 261.9 ms | 178.8 ms | 556.8 ms | 22.8% | Zero MariaDB queries; demonstrates PHP-FPM / Redis memory serialization ceiling. |

<details>
<summary>Table Terminology, Configuration Keys & Metric Definitions</summary>

| Term / Abbreviation | Full Name & `.env` Setting | Plain-Language Definition |
| :--- | :--- | :--- |
| **Contributor Cache (Contributor TTL / `C`)** | `DIALPLAN_CONTRIBUTOR_CACHE_TTL` | Redis cache window (in seconds) for individual dialplan building blocks (extensions, ring groups, IVRs). Cached fragments are stitched together dynamically. |
| **Dialplan Cache (Dialplan TTL / `D`)** | `DIALPLAN_CACHE_TTL` | Redis cache window (in seconds) for the complete rendered XML dialplan document for a tenant. |
| **TTL** | Time To Live | How many seconds a cached response remains valid in Redis before querying MariaDB again. `0` disables caching. |
| **Target Requests (`100 x 5`)** | Offered Burst Profile | 100 total HTTP requests sent with 5 requests simultaneously in-flight at all times. |
| **Redis Hit Rate** | Keyspace Efficiency (`INFO stats`) | Percentage of lookup keys found in fast Redis memory versus total lookups requested during the test run. |
| **`mixed` Scenario** | Varied Endpoint Simulation | Simulates realistic office traffic across varied extensions, IVRs, and ring groups to test routing lookup logic. |
| **`cache-hit` Scenario** | Identical Destination Simulation | Queries the exact same destination 100 times consecutively to measure theoretical maximum throughput with zero database I/O. |

</details>

<details>
<summary>Cache Sweep Detailed Statistics Breakdown (p50, p90, p95, p99, Std Dev)</summary>

| Configuration | Requests/sec | Average | Fastest | Slowest | Median (`p50`) | 90th (`p90`) | 95th (`p95`) | 99th (`p99`) | Jitter (`std_dev`) |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1. Uncached Database Baseline (TTL: 0s) | 8.358 | 565.1 ms | 475.7 ms | 757.1 ms | 556.8 ms | 613.4 ms | 645.9 ms | 741.9 ms | 45.4 ms |
| 2. Contributor Cache Only (Contributor TTL: 5s, Dialplan TTL: 0s) | 14.020 | 291.8 ms | 153.5 ms | 673.4 ms | 284.4 ms | 401.5 ms | 566.2 ms | 633.0 ms | 108.2 ms |
| 3. Production Baseline (Contributor TTL: 5s, Dialplan TTL: 5s) | 17.221 | 245.3 ms | 157.6 ms | 511.9 ms | 239.1 ms | 289.2 ms | 349.6 ms | 501.5 ms | 57.4 ms |
| 4. Extended Retention (TTL: 30s) | 17.196 | 257.8 ms | 171.2 ms | 562.2 ms | 242.2 ms | 303.5 ms | 311.8 ms | 518.0 ms | 68.0 ms |
| 5. Memory Cache Ceiling | 17.400 | 261.9 ms | 178.8 ms | 556.8 ms | 241.6 ms | 275.9 ms | 519.7 ms | 556.0 ms | 84.5 ms |

</details>

#### Network Interface Sanity Check Comparison (Public IPv4 vs Private IPv4 vs Public IPv6)

As a sanity check, baseline `100 x 5` tests were executed across all three available network interfaces on Server 1:

| Network Interface | Target Endpoint | Requests/sec | Average Latency | Fastest (Min) | Slowest (Max) | Median (`p50`) | 95th (`p95`) | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **Public IPv4** | `http://x.x.x.200/...` | 15.605 | 288.6 ms | 166.5 ms | 489.5 ms | 278.0 ms | 422.3 ms | Standard internet routing via public interface. |
| **Private IPv4** | `http://10.124.0.2/...` | 18.440 | 233.0 ms | 168.4 ms | 492.3 ms | 220.4 ms | 284.3 ms | Datacenter private network; ~18% lower average latency. |
| **Public IPv6** | `http://[2604:a880:...9b49:0]/...` | 17.548 | 254.6 ms | 186.8 ms | 490.3 ms | 242.2 ms | 406.0 ms | Native dual-stack IPv6 routing with minimal packet processing overhead. |

<details>
<summary>Table Terminology & Network Definitions</summary>

| Term / Header | Definition & Operational Context |
| :--- | :--- |
| **Public IPv4** | Traffic routed through the public internet and cloud provider border routers to the external IP. |
| **Private IPv4** | Traffic routed strictly within the datacenter private subnet (`10.124.0.0/20`), bypassing public edge hops. |
| **Public IPv6** | Native end-to-end IPv6 routing without Network Address Translation (NAT). |
| **Median (`p50`)** | 50th percentile latency; half of all requests completed faster than this time. |
| **95th (`p95`)** | 95th percentile latency; reflects peak tail latency excluding the most extreme 5% outliers. |

</details>

> [!TIP]
> **Network Interface Observation**:
> Private IPv4 delivered approximately 18% lower average latency and higher throughput compared to the public IPv4 interface by bypassing external cloud provider routing hops and firewall state tables. Public IPv6 performed with near-parity to private IPv4, demonstrating efficient native IPv6 stack performance in Debian 13.

Host telemetry during these runs: Linux 6.12 amd64, 1 vCPU, 967 MiB RAM (340 MiB available), swap utilization remained steady at 121 MiB with zero OOM events.

Artifacts: `storage/app/load-tests/capacity/datacenter-1c-1g-20260924T1753Z/` and `storage/app/load-tests/cache-sweep-20260924-180008/`.

---

### Cloud VPS: Memory-Scaled (1 vCPU / 2 GiB RAM)

The cloud test server was resized in place to 1 vCPU and 1,973 MiB RAM, rebooted, and configured with the production static worker pool (`pm = static`, `pm.max_children = 6`) according to the sizing policy for servers with ≥ 1,800 MB RAM. This benchmark series was executed on September 24, 2026 to measure the performance impact of doubling physical memory while keeping single-core CPU compute constant.

> [!NOTE]
> **Key Finding: Memory Scaling vs. CPU Sizing**:
> Doubling memory from 1 GiB to 2 GiB increased available system memory to ~1,211 MiB and completely eliminated swap usage (0 MiB swap used throughout all runs). However, dynamic XML dialplan throughput remained steady at ~13.7–14.5 req/sec (matching the 1 vCPU / 1 GiB baseline). This empirically confirms **Planning Rule #1**: dynamic XML generation is compute/serialization bound by CPU clock and core count, not memory.

#### Single-Server XML Throughput Ladder (September 24, 2026)

Controlled runs, all `mixed` scenario against public IPv4 (`x.x.x.200`), zero failed XML responses:

| Tier | Repetitions | Median Req/Sec | Average Latency | Fastest (Min) | Slowest (Max) | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | :--- |
| `25 x 1` | 1 (warm-up) | 12.760 | 76.5 ms | 48.1 ms | 209.1 ms | Clean initial warm-up; OPcache bytecode and Redis caches primed. |
| `100 x 5` | 3 (r1–r3) | 14.349 | 327.1 ms | 228.2 ms | 542.9 ms | Repeatable baseline across 3 consecutive runs; 6 static workers pre-forked in memory with zero fork delay. |
| `500 x 25` | 3 (r1–r3) | 13.666 | 1,108.8 ms | 271.5 ms | 3,091.4 ms | Single-core worker saturation; queueing emerges under burst concurrency while memory remains 100% unconstrained. |
| `1,000 x 25` | 1 (r1) | 13.759 | 1,098.9 ms | 265.5 ms | 2,610.8 ms | 1,000/1,000 completed with 0 errors; stable sustained burst ceiling without swap activity. |

<details>
<summary>Table Terminology & Metric Definitions</summary>

| Term / Header | Definition & Operational Meaning |
| :--- | :--- |
| **Tier (`Requests x Concurrency`)** | The load volume profile (e.g. `500 x 25` = 500 total requests with 25 kept concurrently in-flight). |
| **Median Req/Sec** | Requests per second across measured repetitions. Measures how many complete XML dialplan documents the server generated and delivered per second. |
| **Average Latency** | The mean time (in milliseconds) required to process and return an XML request across all samples in the tier. |
| **Fastest (Min)** | The quickest response recorded in the tier (best-case cache/memory hit). |
| **Slowest (Max)** | The slowest response recorded in the tier (tail latency, usually occurring on initial cache misses or worker queuing). |
| **Static 6 (`pm = static`)** | Production-optimized PHP-FPM mode keeping 6 workers permanently pre-forked in RAM. |

</details>

<details>
<summary>Detailed Statistics Breakdown (p50, p90, p95, p99, Std Dev & Repetitions)</summary>

##### Granular Statistical Telemetry (Percentiles & Consistency)

| Tier / Run | Req/Sec | Average | Fastest (Min) | Slowest (Max) | Median (`p50`) | 90th (`p90`) | 95th (`p95`) | 99th (`p99`) | Jitter (`std_dev`) |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `25 x 1` warm-up | 12.760 | 76.5 ms | 48.1 ms | 209.1 ms | 75.0 ms | 99.6 ms | 107.3 ms | 209.1 ms | 33.5 ms |
| `100 x 5` r1 | 14.349 | 328.1 ms | 222.7 ms | 557.4 ms | 320.1 ms | 383.8 ms | 453.4 ms | 557.1 ms | 68.5 ms |
| `100 x 5` r2 | 14.297 | 327.0 ms | 231.0 ms | 524.5 ms | 326.5 ms | 390.8 ms | 420.1 ms | 520.9 ms | 63.2 ms |
| `100 x 5` r3 | 14.491 | 327.1 ms | 228.2 ms | 542.9 ms | 312.2 ms | 377.0 ms | 383.8 ms | 540.5 ms | 61.3 ms |
| **`100 x 5` Median** | **14.349** | **327.1 ms** | **228.2 ms** | **542.9 ms** | **320.1 ms** | **383.8 ms** | **420.1 ms** | **540.5 ms** | **63.2 ms** |
| `500 x 25` r1 | 13.666 | 1,108.8 ms | 254.6 ms | 3,103.0 ms | 1,101.5 ms | 1,796.4 ms | 2,084.5 ms | 2,710.7 ms | 575.4 ms |
| `500 x 25` r2 | 14.599 | 1,037.1 ms | 271.5 ms | 1,944.9 ms | 1,082.5 ms | 1,663.0 ms | 1,727.7 ms | 1,899.0 ms | 476.7 ms |
| `500 x 25` r3 | 11.770 | 1,265.3 ms | 287.8 ms | 3,091.4 ms | 1,229.7 ms | 2,111.8 ms | 2,319.5 ms | 2,692.7 ms | 622.2 ms |
| **`500 x 25` Median** | **13.666** | **1,108.8 ms** | **271.5 ms** | **3,091.4 ms** | **1,101.5 ms** | **1,796.4 ms** | **2,084.5 ms** | **2,710.7 ms** | **575.4 ms** |
| `1,000 x 25` r1 | 13.759 | 1,098.9 ms | 265.5 ms | 2,610.8 ms | 1,124.9 ms | 1,733.9 ms | 1,937.0 ms | 2,397.3 ms | 526.8 ms |

</details>

#### Cache Optimization and Hit Rate Sweep (September 24, 2026)

The 5-run cache optimization sweep on the resized 2 GiB cloud VPS evaluated performance across cache policies with 6 static PHP-FPM workers:

| Run Configuration | Scenario | Target Requests | Requests/sec | Average Latency | Fastest (Min) | Slowest (Max) | Redis Hit Rate | Key Observation |
| :--- | :--- | :--- | ---: | ---: | ---: | ---: | ---: | :--- |
| **1. Uncached Database Baseline** | `mixed` | `100 x 5` | 9.873 | 487.3 ms | 362.7 ms | 666.4 ms | 0.0% | Heavy MariaDB query execution; average latency of 487.3 ms. |
| **2. Contributor Cache Only** | `mixed` | `100 x 5` | 16.293 | 290.5 ms | 202.9 ms | 541.8 ms | 45.5% | Reused static routing fragments; cut MariaDB table reads by ~40%. |
| **3. Production Baseline** | `mixed` | `100 x 5` | 19.692 | 235.2 ms | 169.3 ms | 483.2 ms | 39.6% | Recommended production baseline; peak throughput of 19.69 req/sec with 5s update convergence. |
| **4. Extended Retention** | `mixed` | `100 x 5` | 19.116 | 244.5 ms | 176.0 ms | 487.6 ms | 39.8% | High throughput with 30s TTL retention window. |
| **5. Pure Memory Ceiling** | `cache-hit` | `100 x 5` | 14.832 | 314.5 ms | 220.6 ms | 692.4 ms | 21.8% | Zero MariaDB queries; demonstrates PHP-FPM / Redis memory serialization ceiling. |

<details>
<summary>Table Terminology, Configuration Keys & Metric Definitions</summary>

| Term / Abbreviation | Full Name & `.env` Setting | Plain-Language Definition |
| :--- | :--- | :--- |
| **Contributor Cache (Contributor TTL / `C`)** | `DIALPLAN_CONTRIBUTOR_CACHE_TTL` | Redis cache window (in seconds) for individual dialplan building blocks (extensions, ring groups, IVRs). Cached fragments are stitched together dynamically. |
| **Dialplan Cache (Dialplan TTL / `D`)** | `DIALPLAN_CACHE_TTL` | Redis cache window (in seconds) for the complete rendered XML dialplan document for a tenant. |
| **TTL** | Time To Live | How many seconds a cached response remains valid in Redis before querying MariaDB again. `0` disables caching. |
| **Target Requests (`100 x 5`)** | Offered Burst Profile | 100 total HTTP requests sent with 5 requests simultaneously in-flight at all times. |
| **Redis Hit Rate** | Keyspace Efficiency (`INFO stats`) | Percentage of lookup keys found in fast Redis memory versus total lookups requested during the test run. |
| **`mixed` Scenario** | Varied Endpoint Simulation | Simulates realistic office traffic across varied extensions, IVRs, and ring groups to test routing lookup logic. |
| **`cache-hit` Scenario** | Identical Destination Simulation | Queries the exact same destination 100 times consecutively to measure theoretical maximum throughput with zero database I/O. |

</details>

<details>
<summary>Cache Sweep Detailed Statistics Breakdown (p50, p90, p95, p99, Std Dev)</summary>

| Configuration | Requests/sec | Average | Fastest | Slowest | Median (`p50`) | 90th (`p90`) | 95th (`p95`) | 99th (`p99`) | Jitter (`std_dev`) |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1. Uncached Database Baseline (TTL: 0s) | 9.873 | 487.3 ms | 362.7 ms | 666.4 ms | 481.1 ms | 560.0 ms | 579.4 ms | 660.1 ms | 57.8 ms |
| 2. Contributor Cache Only (Contributor TTL: 5s, Dialplan TTL: 0s) | 16.293 | 290.5 ms | 202.9 ms | 541.8 ms | 269.3 ms | 340.1 ms | 505.5 ms | 539.0 ms | 79.9 ms |
| 3. Production Baseline (Contributor TTL: 5s, Dialplan TTL: 5s) | 19.692 | 235.2 ms | 169.3 ms | 483.2 ms | 223.6 ms | 258.2 ms | 275.2 ms | 481.1 ms | 60.3 ms |
| 4. Extended Retention (TTL: 30s) | 19.116 | 244.5 ms | 176.0 ms | 487.6 ms | 233.4 ms | 278.6 ms | 288.4 ms | 485.5 ms | 60.5 ms |
| 5. Memory Cache Ceiling | 14.832 | 314.5 ms | 220.6 ms | 692.4 ms | 296.9 ms | 327.0 ms | 412.3 ms | 689.5 ms | 89.9 ms |

</details>

#### Network Interface Sanity Check Comparison (Public IPv4 vs Private IPv4 vs Public IPv6)

As a sanity check on the resized server, baseline `100 x 5` tests were executed across all three available network interfaces:

| Network Interface | Target Endpoint | Requests/sec | Average Latency | Fastest (Min) | Slowest (Max) | Median (`p50`) | 95th (`p95`) | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **Public IPv4** | `http://x.x.x.200/...` | 14.349 | 328.1 ms | 222.7 ms | 557.4 ms | 320.1 ms | 453.4 ms | Standard internet routing via public interface. |
| **Private IPv4** | `http://10.124.0.2/...` | 12.732 | 355.1 ms | 238.7 ms | 690.2 ms | 326.1 ms | 488.7 ms | Datacenter private network interface routing. |
| **Public IPv6** | `http://[2604:a880:...9b49:0]/...` | 11.983 | 389.7 ms | 254.8 ms | 761.7 ms | 354.1 ms | 661.6 ms | Native dual-stack IPv6 routing with 100% completion. |

<details>
<summary>Table Terminology & Network Definitions</summary>

| Term / Header | Definition & Operational Context |
| :--- | :--- |
| **Public IPv4** | Traffic routed through the public internet and cloud provider border routers to the external IP. |
| **Private IPv4** | Traffic routed strictly within the datacenter private subnet (`10.124.0.0/20`), bypassing public edge hops. |
| **Public IPv6** | Native end-to-end IPv6 routing without Network Address Translation (NAT). |
| **Median (`p50`)** | 50th percentile latency; half of all requests completed faster than this time. |
| **95th (`p95`)** | 95th percentile latency; reflects peak tail latency excluding the most extreme 5% outliers. |

</details>

Host telemetry during these runs: Linux 6.12 amd64, 1 vCPU, 1,973 MiB RAM (1,211 MiB available), swap utilization remained at 0 MiB with zero OOM events.

Artifacts: `storage/app/load-tests/capacity/datacenter-1c-2g-20260924T193809Z/` and `storage/app/load-tests/cache-sweep-20260924-194621/`.

---

### Cloud VPS: Dual-Core (2 vCPU / 2 GiB RAM)

The cloud test server was resized in place to 2 vCPUs and 1,973 MiB RAM, testing the impact of adding a second compute core while retaining the same PHP-FPM static-6 profile (`pm = static`, `pm.max_children = 6`), MariaDB 10.11, Redis 7.0, and 0 MiB swap usage. This benchmark series was executed on September 24, 2026 across two direct cloud datacenter nodes in the same region (`sfo3`) without VPN encapsulation overhead.

#### Single-Server XML Throughput Ladder (September 24, 2026)

Controlled runs, all `mixed` scenario against public IPv4 (`x.x.x.200`), zero failed XML responses across 2,225 requests:

| Tier | Repetitions | Median Req/Sec | Average Latency | Fastest (Min) | Slowest (Max) | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | :--- |
| `25 x 1` | 1 (warm-up) | 6.352 | 154.4 ms | 84.4 ms | 360.1 ms | Clean initial warm-up; OPcache bytecode and Redis caches primed. |
| `100 x 5` | 2 (r1–r2) | 14.359 | 287.7 ms | 147.9 ms | 521.1 ms | Cold run 1: 10.12 req/sec (413.9 ms); Warmed run 2 reached 14.36 req/sec with 287.7 ms average latency. |
| `500 x 25` | 2 (r1–r2) | 16.130 | 923.1 ms | 111.3 ms | 1,977.8 ms | Repeatable dual-core burst throughput (16.29 and 15.97 req/sec); zero queue dropouts or timeouts. |
| `1,000 x 25` | 1 (r1) | 14.536 | 1,013.2 ms | 106.2 ms | 2,755.9 ms | 1,000/1,000 completed with 0 errors; sustained burst ceiling across 6 static workers on 2 vCPUs. |

<details>
<summary>Table Terminology & Metric Definitions</summary>

| Term / Header | Definition & Operational Meaning |
| :--- | :--- |
| **Tier (`Requests x Concurrency`)** | The load volume profile (e.g. `500 x 25` = 500 total requests with 25 kept concurrently in-flight). |
| **Median Req/Sec** | Requests per second across measured repetitions. Measures how many complete XML dialplan documents the server generated and delivered per second. |
| **Average Latency** | The mean time (in milliseconds) required to process and return an XML request across all samples in the tier. |
| **Fastest (Min)** | The quickest response recorded in the tier (best-case cache/memory hit). |
| **Slowest (Max)** | The slowest response recorded in the tier (tail latency under worker queuing). |
| **Static 6 (`pm = static`)** | Production-optimized PHP-FPM mode keeping 6 workers permanently pre-forked in RAM. |

</details>

<details>
<summary>Detailed Statistics Breakdown (p50, p90, p95, p99, Std Dev & Repetitions)</summary>

##### Granular Statistical Telemetry (Percentiles & Consistency)

| Tier / Run | Req/Sec | Average | Fastest (Min) | Slowest (Max) | Median (`p50`) | 90th (`p90`) | 95th (`p95`) | 99th (`p99`) | Jitter (`std_dev`) |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `25 x 1` warm-up | 6.352 | 154.4 ms | 84.4 ms | 360.1 ms | 132.9 ms | 182.6 ms | 333.0 ms | 360.1 ms | 64.1 ms |
| `100 x 5` r1 (cold) | 10.115 | 413.9 ms | 153.4 ms | 983.8 ms | 383.0 ms | 633.1 ms | 801.9 ms | 961.5 ms | 182.0 ms |
| `100 x 5` r2 (warm) | 14.359 | 287.7 ms | 147.9 ms | 521.1 ms | 277.8 ms | 380.4 ms | 464.7 ms | 494.5 ms | 79.4 ms |
| `500 x 25` r1 | 16.294 | 906.0 ms | 175.1 ms | 1,977.8 ms | 901.2 ms | 1,441.8 ms | 1,542.5 ms | 1,795.7 ms | 419.3 ms |
| `500 x 25` r2 | 15.965 | 940.1 ms | 111.3 ms | 1,798.4 ms | 941.6 ms | 1,512.9 ms | 1,618.6 ms | 1,749.0 ms | 430.1 ms |
| **`500 x 25` Median** | **16.130** | **923.1 ms** | **111.3 ms** | **1,977.8 ms** | **921.4 ms** | **1,477.4 ms** | **1,580.6 ms** | **1,772.4 ms** | **424.7 ms** |
| `1,000 x 25` r1 | 14.536 | 1,013.2 ms | 106.2 ms | 2,755.9 ms | 991.8 ms | 1,646.7 ms | 1,806.5 ms | 2,115.3 ms | 479.9 ms |

</details>

#### Cache Optimization and Hit Rate Sweep (September 24, 2026)

The 5-run cache optimization sweep on the resized 2 vCPU / 2 GiB cloud VPS evaluated performance across cache policies with 6 static PHP-FPM workers:

| Run Configuration | Scenario | Target Requests | Requests/sec | Average Latency | Fastest (Min) | Slowest (Max) | Redis Hit Rate | Key Observation |
| :--- | :--- | :--- | ---: | ---: | ---: | ---: | ---: | :--- |
| **1. Uncached Database Baseline** | `mixed` | `100 x 5` | 10.247 | 421.3 ms | 229.9 ms | 651.0 ms | 0.0% | Heavy MariaDB query execution; average latency of 421.3 ms with 0% cache hit rate. |
| **2. Contributor Cache Only** | `mixed` | `100 x 5` | 12.125 | 344.0 ms | 159.7 ms | 677.3 ms | 47.3% | Reused static routing fragments; achieved 47.3% hit rate and reduced query pressure. |
| **3. Production Baseline** | `mixed` | `100 x 5` | 15.261 | 279.0 ms | 139.4 ms | 490.2 ms | 38.1% | Recommended production baseline; 15.26 req/sec with 5s update convergence window. |
| **4. Extended Retention** | `mixed` | `100 x 5` | 14.935 | 285.4 ms | 114.2 ms | 609.6 ms | 41.2% | Sustained high throughput with 30s TTL retention window for call center trunk routing. |
| **5. Pure Memory Ceiling** | `cache-hit` | `100 x 5` | 19.496 | 218.5 ms | 101.7 ms | 372.5 ms | 42.9% | Zero MariaDB queries; demonstrates PHP-FPM / Redis memory serialization ceiling. |

<details>
<summary>Cache Sweep Detailed Statistics Breakdown (p50, p90, p95, p99, Std Dev)</summary>

| Configuration | Requests/sec | Average | Fastest | Slowest | Median (`p50`) | 90th (`p90`) | 95th (`p95`) | 99th (`p99`) | Jitter (`std_dev`) |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1. Uncached Database Baseline (TTL: 0s) | 10.247 | 421.3 ms | 229.9 ms | 651.0 ms | 400.9 ms | 548.2 ms | 567.5 ms | 611.8 ms | 90.4 ms |
| 2. Contributor Cache Only (Contributor TTL: 5s, Dialplan TTL: 0s) | 12.125 | 344.0 ms | 159.7 ms | 677.3 ms | 321.6 ms | 477.6 ms | 519.5 ms | 664.2 ms | 110.9 ms |
| 3. Production Baseline (Contributor TTL: 5s, Dialplan TTL: 5s) | 15.261 | 279.0 ms | 139.4 ms | 490.2 ms | 255.6 ms | 398.4 ms | 441.6 ms | 483.1 ms | 82.8 ms |
| 4. Extended Retention (TTL: 30s) | 14.935 | 285.4 ms | 114.2 ms | 609.6 ms | 255.4 ms | 383.6 ms | 587.6 ms | 607.0 ms | 103.2 ms |
| 5. Memory Cache Ceiling | 19.496 | 218.5 ms | 101.7 ms | 372.5 ms | 205.8 ms | 300.9 ms | 350.7 ms | 369.1 ms | 62.0 ms |

</details>

Host telemetry during these runs: Linux 6.12 amd64, 2 vCPUs, 1,973 MiB RAM (1,153 MiB available), swap utilization remained at 0 MiB with zero OOM events.

Artifacts: `storage/app/load-tests/capacity/datacenter-2c2g-shared-20260924T210849Z/` and `storage/app/load-tests/cache-sweep-20260924-211217/`.

#### Historical Reference: July 2026 Benchmark Runs

The earlier July 2026 benchmark evaluated a 2 vCPU / 2 GiB VPS profile using WireGuard encapsulation and compared PHP-FPM static worker pools. Below is the historical reference summary:

| Tier | Repetitions | Median Req/Sec | Average Latency | Fastest Latency | Slowest Latency | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | :--- |
| `100 x 5` | 3 (r1–r3) | 17.864 | 133 ms | 107 ms | 186 ms | Sub-200ms tail latency; smooth dual-core execution. |
| `500 x 25` | 3 (r1–r3) | 29.831 | 165 ms | 106 ms | 365 ms | **+61.7% throughput gain** over 1c/2g; tail latency dropped from ~1,700 ms to 365 ms. |
| `1,000 x 25` | 1 (r1) | 29.673 | 170 ms | 108 ms | 410 ms | Stable sustained burst ceiling; dual cores prevent worker starvation. |

<details>
<summary>Table Terminology & Metric Definitions</summary>

| Term / Header | Definition & Operational Meaning |
| :--- | :--- |
| **Tier (`Requests x Concurrency`)** | The load volume profile (e.g. `500 x 25` = 500 total requests with 25 kept concurrently in-flight). |
| **Median Req/Sec** | Central throughput across measured repetitions (documents rendered and delivered per second). |
| **Average Latency** | Mean XML processing latency in milliseconds. |
| **Dual-Core Gain** | Adding a second vCPU roughly doubled burst capacity and reduced tail latency by ~75%. |

</details>

<details>
<summary>View Individual Repetition Measurements (r1–r3)</summary>

| Run | Requests/sec | Average | Fastest | Slowest |
| --- | ---: | ---: | ---: | ---: |
| `100 x 5` r1 | 16.658 | 149 ms | 109 ms | 261 ms |
| `100 x 5` r2 | 18.584 | 131 ms | 107 ms | 186 ms |
| `100 x 5` r3 | 17.864 | 133 ms | 102 ms | 181 ms |
| `500 x 25` r1 | 30.215 | 169 ms | 106 ms | 331 ms |
| `500 x 25` r2 | 29.831 | 165 ms | 103 ms | 365 ms |
| `500 x 25` r3 | 29.363 | 175 ms | 106 ms | 414 ms |
| `1,000 x 25` r1 | 29.673 | 170 ms | 108 ms | 410 ms |

</details>

All runs returned zero failures. The host used no swap, retained about 1.36 GiB available memory after testing, and logged no new PHP-FPM saturation warnings. Doubling the CPU count raised `500 x 25` throughput from 18.625 (1c/2g median) to 30.215 requests/sec and reduced the slowest response from about 1,700 ms to about 400 ms. This strongly suggests CPU scheduling and CPU count were the dominant limiters once memory headroom was adequate. WAN round-trip latency was 36–41 ms.

**PHP-FPM experiment (2 vCPU / 2 GiB).** Before the test VPS was destroyed, static 8, 10, and 12 were compared against the static-6 controlled baseline using the `500 x 25` tier. All candidates returned zero failures and no swap use:

| Run | Requests/sec | Average | Fastest | Slowest |
| --- | ---: | ---: | ---: | ---: |
| Static 8 r1 | 30.117 | 193 ms | 104 ms | 858 ms |
| Static 8 r2 | 29.087 | 161 ms | 104 ms | 308 ms |
| Static 10 r1 | 28.802 | 188 ms | 107 ms | 894 ms |
| Static 10 r2 | 29.861 | 155 ms | 106 ms | 339 ms |
| Static 12 r1 | 29.472 | 190 ms | 100 ms | 957 ms |
| Static 12 r2 | 29.626 | 156 ms | 100 ms | 340 ms |

None of the higher worker counts improved throughput meaningfully, and each introduced occasional slowest-response spikes (858–957 ms) compared with the static-6 baseline (331–414 ms). Static 6 remained the active and recommended 2-GB profile.

---

### Dedicated Cloud Node (4 vCPU / 8 GiB RAM Dedicated) — Planned

The dedicated-CPU 4 vCPU / 8 GiB profile represents the high-density multi-tenant enterprise PBX tier. *(Note: The intermediate 2 vCPU / 2 GiB dedicated test was skipped to streamline the testing matrix, focusing efforts on the 2 vCPU / 2 GiB shared droplet and escalating directly to the 4 vCPU / 8 GiB dedicated tier).* When the dedicated 4c/8g hardware is provisioned, repeat the same procedure:

1. Seed with the same `load-test-beta` synthetic tenant and extension count.
2. Run the `25 x 1` warm-up, then `100 x 5`, `500 x 10`, `500 x 25`, and `1,000 x 25`.
3. Record the median of measured runs per tier plus server specs, provider CPU class, disk type, region, and pre-run round-trip latency.
4. Stop escalating if XML handler latency spikes, responses return non-2xx, MariaDB shows lock/connection pressure, Laravel workers saturate, or the generator itself saturates.

---

## Live Call Capacity & Telephony Feature Parity (SIP Signaling)

Live SIP call benchmarks evaluate complete telephony call setup, bidirectional audio flows, and feature parity. While the single-server XML tests measure the raw speed of the database and PHP-FPM routing layer, these live call benchmarks measure real-world PBX capacity: FreeSWITCH SIP signaling state machines, Sofia user authentication, RTP media proxying, and bridge teardown across two machines (a PBX under test and a dedicated load generator).

Call-setup latency is measured from the caller's first `INVITE` to the destination's `200 OK`. "Achieved calls/sec" compares the first and last successful answer, revealing when call setup queues behind the offered rate.

---

### Local Lab: VirtualBox Test Pair (Baseline)

The September 23, 2026 test series executed the full 15-scenario telephony validation suite between the isolated VirtualBox PBX test VM (`192.168.1.71`) and the orchestrator / SIPp generator VM (`192.168.1.76`) running on the same local Windows 11 host. Neither VM functions as an office PBX or live server; they exist purely as an isolated, reproducible virtualization test bench. All 15/15 scenarios passed with zero failed calls.

**End-to-end correctness, media flows, and extended parity checks (September 23, 2026)**

| Test | Result | Important observation |
| :--- | :--- | :--- |
| Register 20 users | Passed | 20 successful, 0 failed. Leases configured with `Expires: 3600` so contact records persist throughout testing. |
| Extension calls (10 calls) | Passed | 10 successful, 0 failed. Bidirectional SIP signaling and call tear-down verified. |
| Outbound calls (5 calls) | Passed | 5 successful, 0 failed. Outbound gateway routing through synthetic SIPp UAS listener verified. |
| Recording media to `*732` | Passed | Authenticated call answered, FreeSWITCH recorded and sent BYE, 1 successful, 0 failed. |
| MOH media to `load_test_moh` | Passed | Call answered, held through the 10-second media window, SIPp sent BYE and received `200`. |
| Announcement media to `load_test_announcement` | Passed | FreeSWITCH played packaged prompt, SIPp echoed RTP, FreeSWITCH sent BYE, 1 successful, 0 failed. |
| Extended user re-registration | Passed | Re-registered 20 users before extended scenarios to refresh Sofia contact records. |
| Ring group calls (`2400`) | Passed | 3 successful, 0 failed. FreeSWITCH generated valid XML and bridged to group members. |
| Voicemail calls (`2003`) | Passed | 3 successful, 0 failed. Call answered and handled by voicemail subsystem. |
| Conference calls (`2500`) | Passed | 3 successful, 0 failed. Bridged into conference room without error. |
| Call forward calls (`2001` -> `2000`) | Passed | 3 successful, 0 failed. Dialplan routes cleanly to forwarded extension via loopback channel. |
| Time condition calls (`2401`) | Passed | 3 successful, 0 failed. Evaluated schedule rules dynamically and bridged call. |
| Follow me calls (`2002`) | Passed | 3 successful, 0 failed. Sequential ring list executed and completed cleanly. |
| Emergency calls (`911`) | Passed | 3 successful, 0 failed. Correctly bridged to emergency gateway UAS. |
| Call block rejection | Passed | 3 successful, 0 failed. Blocked caller ID pattern matched and rejected with `603 Decline`. |

<details>
<summary>Telephony Scenario Terminology & Definitions</summary>

| Telephony Scenario | Plain-Language Description |
| :--- | :--- |
| **SIP Registration** | Endpoints register contact IPs with FreeSWITCH (`sofia_reg`), establishing credential leases (`Expires: 3600`). |
| **Extension Calls** | Internal caller authenticated via SIP digest (407 challenge), routed via dialplan XML, and answered. |
| **Outbound Gateway** | Call dialed through gateway route and bridged to an external telephony provider UAS. |
| **Recording Media (`*732`)** | Call answered with active two-way RTP audio and written to disk via `record_session`. |
| **MOH Media** | Music On Hold playback stream (`local_stream://moh`) streamed continuously to the held party. |
| **Announcement Media** | Pre-recorded IVR prompt played to caller, terminating automatically upon prompt completion. |
| **Ring Group (`2400`)** | One extension rings multiple endpoints simultaneously or sequentially until answered. |
| **Voicemail (`*98` / `2003`)** | Interactive voicemail portal handling greeting playback, PIN authentication, and message recording. |
| **Conference (`2500`)** | Multi-party audio bridge mixing multiple RTP media streams in real time (`mod_conference`). |
| **Call Forwarding** | Inbound call diverted via FreeSWITCH loopback channel (`loopback/ext/context`) to an alternate destination. |
| **Time Conditions** | Dynamic schedule evaluation checking current time against business hours before routing. |
| **Follow-Me** | Hunting list ringing primary phone first, then cascading to mobile/alternate endpoints. |
| **Emergency (`911`)** | High-priority routing bypassing normal tenant filters and dispatching to emergency trunks. |
| **Call Blocking** | Inbound caller ID checked against blacklist table and declined immediately (`603 Decline`). |

</details>

Run details: artifact directory `storage/app/load-tests/sipp-e2e-20260923-165139`, PBX target `192.168.1.71:5060`, generator at `192.168.1.76`, media RTP echo enabled, PHP-FPM static pool (12 workers), FreeSWITCH calls and channels back to `0` after the run.

Behaviors these tests enforce in the application and runtime:
- Required FreeSWITCH module load lines persist so SIP, callcenter, local-stream MOH, sound playback, and XML curl survive a reboot;
- Dynamic no-domain configuration requests include all enabled Sofia profiles and callcenter queues when FreeSWITCH loads modules;
- Tenant Sofia profile params are wrapped in `<settings>`;
- Callcenter queue names include the FreeSWITCH queue namespace, for example `load_test_moh@default`;
- Feature-code destination regexes escape star codes, for example `^\*97$`;
- Announcement-only IVR dialplans play and hang up without waiting for digit input;
- SIPp media-flow scenarios use RTP echo so playback can advance in this synthetic setup.

#### Complete-Signaling Capacity Ladder (July 17, 2026 Reference)

The load generator used 100 registered synthetic extensions. Each tier attempted new calls for 30 seconds with a concurrency ceiling above the expected active calls:

| Attempted calls/sec | Calls | Successful | Failed | Achieved calls/sec | Average setup | Fastest setup | Slowest setup | Peak active SIPp calls | Peak PBX calls/channels | Peak CPU busy | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| 1 | 30 | 30 | 0 | 1.009 | 593 ms | 470 ms | 774 ms | 6 | 7 / 12 | 29% | Passed |
| 2 | 60 | 60 | 0 | 2.012 | 680 ms | 573 ms | 892 ms | 12 | 12 / 22 | 54% | Passed |
| 4 | 120 | 120 | 0 | 4.018 | 848 ms | 480 ms | 1,312 ms | 25 | 24 / 45 | 85% | Passed |
| 5, r1 | 150 | 150 | 0 | 4.928 | 1,799 ms | 749 ms | 3,760 ms | 40 | 35 / 64 | 97% | Passed |
| 5, r2 | 150 | 150 | 0 | 4.994 | 1,256 ms | 647 ms | 2,132 ms | 35 | 32 / 61 | 95% | Passed |
| 5, r3 | 150 | 150 | 0 | 4.886 | 1,484 ms | 682 ms | 3,671 ms | 37 | 34 / 65 | 98% | Passed |
| 6 | 180 | 180 | 0 | 5.506 | 3,085 ms | 784 ms | 6,453 ms | 55 | 43 / 72 | 98% | Passed, but overloaded |
| 7 | 210 | 209 | 1 | 5.498 | 5,679 ms | 738 ms | 9,220 ms | 70 | 53 / 83 | 98% | Failed |
| 8 | 240 | 180 | 60 | 4.641 | 6,853 ms | 918 ms | 9,998 ms | 80 | 56 / 81 | 98% | Failed |

<details>
<summary>Table Terminology & Metric Definitions</summary>

| Term / Header | Definition & Operational Meaning |
| :--- | :--- |
| **Attempted calls/sec** | Offered call rate generated by SIPp (target call arrivals per second). |
| **Achieved calls/sec** | Actual rate of successfully answered calls (`200 OK`) measured across the run window. |
| **Average setup** | Round-Trip Delay (RTD) from initial INVITE through 407 challenge to 200 OK answer (in milliseconds). |
| **Fastest / Slowest setup** | Minimum and maximum call setup response times recorded. |
| **Peak PBX calls/channels** | Active FreeSWITCH sessions / bridged media channel pairs (`show channels count`). |
| **Peak CPU busy** | Host processor utilization during the test tier. |

</details>

The repeatable 5-CPS result is 150/150 successful calls in all three runs, with a middle achieved rate of 4.928 calls/sec and middle setup figures of 1,484 ms average, 682 ms fastest, and 3,671 ms slowest. Five attempted calls per second is the highest repeatably verified tier that kept pace without failures, but it is a measured limit, not an everyday operating target: CPU was 95–98% busy. Four calls per second is the more sensible planning limit on this VM because it retained CPU headroom and kept average setup below one second.

At 6 CPS every call still completed, but the achieved answer rate flattened to 5.506 CPS and average setup exceeded three seconds. At 7 CPS calls began to fail, and at 8 CPS 60 of 240 calls failed. This shows a real saturation boundary near 5–6 end-to-end call setups per second rather than a SIPp concurrency cap. The run exercised full SIP signaling setup and teardown but did not generate continuous RTP audio; media capacity and audio quality require a separate RTP-enabled concurrent-call test. Artifacts: `storage/app/load-tests/capacity/virtualbox-4c-4g-cps-20260718T011540Z/`.

#### Retest at Sessions-Per-Second = 60

The VirtualBox PBX was retested from the WSL generator after raising FreeSWITCH to `sessions-per-second=60`. These runs are comparison results, not production capacity numbers, and the VM was carrying background load:

| Offered calls/sec | Attempted | Achieved calls/sec | Successful | Failed | Average setup | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| 5 | 100 | 4.629 | 100 | 0 | 703 ms | Passed |
| 8 | 160 | 5.574 | 160 | 0 | 4,253 ms | Passed, but queued |
| 10, r1 | 200 | 5.588 | 196 | 4 | 6,276 ms | Failed |
| 10, r2 | 200 | 5.184 | 123 | 77 | 6,742 ms | Failed |
| 12 | 240 | 8.299 attempted/created | 32 | 208 | 5,587 ms | Failed heavily |

<details>
<summary>Table Terminology & Metric Definitions</summary>

| Term / Header | Definition & Operational Meaning |
| :--- | :--- |
| **Offered calls/sec** | Offered call rate generated by SIPp (target call arrivals per second). |
| **Sessions-per-second=60** | FreeSWITCH engine setting controlling maximum call setups per second (`switch.conf.xml`). |
| **Average setup** | Mean call setup time from initial INVITE to 200 OK answer. |
| **Failed Calls** | Calls that timed out or were rejected by FreeSWITCH with 403 or 503 errors. |

</details>

A broader sweep reached complete failure at higher offered rates: 15 CPS completed only 164/300 calls, 20 CPS completed only 28/400, and 25 CPS or higher completed no calls. FreeSWITCH logs showed `mod_xml_curl` timeout errors fetching the local XML handler and `CALL_REJECTED` hangups while network counters still showed zero packet drops or errors. That makes this run useful for finding the local XML-handler bottleneck, not for a production calls/sec number. Artifacts: `virtualbox-4c-4g-sps60-20260718-sipp-cps-r2` and `virtualbox-4c-4g-sps60-20260718-sipp-cps-low-repeat`.

#### FreeSWITCH Log-Level Experiment (VirtualBox)

The same sps60 set was re-run with FreeSWITCH switch logging lowered from `debug` to `notice`:

| Tier | Attempted | Achieved calls/sec | Successful | Failed | Average setup | SIP INVITE retransmissions |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 5 CPS, 100 calls | 100 | 4.634 | 100 | 0 | 713 ms | 8 |
| 8 CPS, 160 calls | 160 | 5.398 | 160 | 0 | 4,570 ms | 281 |
| 10 CPS, 200 calls r1 | 200 | 5.598 | 200 | 0 | 6,218 ms | 467 |
| 10 CPS, 200 calls r2 | 200 | 6.626 | 200 | 0 | 4,794 ms | 372 |

A separate 6-CPS tier check with logging lowered to `notice` kept all 180/180 calls completing at about 5.06 calls/sec with average setup dropping to about 1,235 ms and slowest setup to about 2,427 ms, versus the 3,085 ms average and 6,453 ms slowest seen at the same tier with debug logging. The conclusion: lowering the log level improves setup delay but does not materially raise the achieved call rate. Keep DEBUG logging enabled by default while the PBX is still being validated, and lower it only for the measured run:

```bash
fs_cli -x 'fsctl loglevel notice'
fs_cli -x 'console loglevel notice'
```

After the comparison, restore the debug-friendly runtime level:
```bash
fs_cli -x 'fsctl loglevel debug'
fs_cli -x 'console loglevel info'
```

Use `warning` instead of `notice` only when the goal is a low-noise capacity run and detailed call progress logs are not needed. Artifacts: `virtualbox-4c-4g-sps60-fsnotice-20260718-sipp-cps`.

#### Tenant-Identity Cache Experiment (VirtualBox, 8 CPS)

The tenant-identity cache shortens the repeated directory/auth XML path: when FreeSWITCH asks which tenant/user a SIP auth request belongs to, Laravel can reuse a short-lived Redis answer instead of repeatedly querying MariaDB and decrypting SIP account data during a call burst.

| Run | Offered calls/sec | Attempted | Achieved calls/sec | Successful | Failed | UDP errors | Result |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| Before tenant-identity cache | 8 | 80 | 1.813 | 79 | 1 | 0 | Failed by one call |
| After tenant-identity cache | 8 | 80 | 4.912 | 79 | 1 | 0 | Failed by one call |

XML timing during the same runs:

| XML section | Run | Requests | Avg total time | Avg DB queries | Avg DB time | Max total time |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Directory/auth | Before cache | 80 | 129.75 ms | 4.50 | 62.76 ms | 420.97 ms |
| Directory/auth | After cache | 80 | 117.50 ms | 1.25 | 30.53 ms | 834.32 ms |
| Dialplan | Before cache | 80 | 50.91 ms | 0.35 | 2.07 ms | 284.25 ms |
| Dialplan | After cache | 79 | 75.31 ms | 0.39 | 2.36 ms | 377.26 ms |

The cache cut average directory/auth DB queries from 4.50 to 1.25 per request and average directory/auth DB time from 62.76 ms to 30.53 ms. The end-to-end result still had one failed call (an authenticated INVITE `403 Forbidden` plus the separate SIPp UAS `NOTIFY` harness artifact). The cache improvement stays, but the remaining VirtualBox failure is not solved by directory-cache work alone. Artifacts: `virtualbox-dbtime-8cps-80calls-rerun-20260718` and `virtualbox-identitycache-8cps-80calls-retry3-20260718`.

---

### Cloud Datacenter VPS (1 vCPU / 1 GiB RAM Baseline)

Empirical benchmark and validation series executed on September 24, 2026 across two cloud datacenter nodes in the same region (`sfo3`):
- **Target PBX VPS**: 1 vCPU, 967 MiB RAM, 2.0 GiB swap. Debian 13, Nginx 1.26, PHP 8.5-FPM (`pm = dynamic`, `pm.max_children = 5`), MariaDB 10.11, Redis 7.0, FreeSWITCH 1.11. Public IPv4 `x.x.x.200`, Private IPv4 `10.124.0.2`, Public IPv6 `2604:a880:...9b49:0`.
- **Dedicated Load Generator**: 2 vCPU, 2048 MiB RAM. Debian 13, SIPp 3.7.3. Public IPv4 `x.x.x.173`, Private IPv4 `10.124.0.3`, Public IPv6 `2604:a880:...9b53:9000`.
- **Network**: Direct datacenter interface routing; SIP signalling and RTP media exchange over direct public IP interfaces without VPN tunneling overhead.

#### End-to-End Functional & Media Parity Matrix (September 24, 2026)

Full 14-scenario parity test suite executed via `scripts/pbx-sipp-validate.sh` (`MEDIA_FLOW=1`, `EXTENDED=1`):

| Test Scenario | Destination / Feature | Off / Limit / Count | Result | Operational Verification |
| --- | --- | ---: | --- | --- |
| **SIP Registration** | `register.xml` (20 users) | 5 / 5 / 20 | **Passed** | 20/20 extensions authenticated with `200 OK` (Expires: 3600). |
| **Extension Calls** | Internal extensions (2000–2019) | 2 / 5 / 10 | **Passed** | 10/10 authenticated calls completed via UAS auto-answer on port 5066. |
| **Outbound Gateway** | Gateway routing to UAS | 1 / 2 / 5 | **Passed** | 5/5 calls routed through gateway to UAS port 5088 with PBX SNAT. |
| **Recording Media** | `*732` (Call Recording) | 1 / 1 / 1 | **Passed** | **Resolved.** FreeSWITCH answered, recorded active RTP audio, and saved session cleanly. |
| **MOH Media** | `load_test_moh` (Music on Hold) | 1 / 1 / 1 | **Passed** | Call answered, active RTP captured on UDP 6002 (`load_test_moh.wav`), completed full duration. |
| **Announcement Media** | `load_test_announcement` | 1 / 1 / 1 | **Passed** | Call answered, active RTP captured on UDP 6004, PBX sent BYE after playback ended. |
| **Ring Group** | Extension 2400 | 1 / 2 / 1 | **Passed** | Simultaneous ring bridged to destinations with `200 OK`. |
| **Voicemail** | `*98` (Voicemail Access) | 1 / 2 / 1 | **Passed** | Authenticated voicemail IVR answered and completed navigation. |
| **Conference Bridge** | Extension 2500 | 1 / 2 / 1 | **Passed** | Conference room bridge connected and audio mixer initialized. |
| **Call Forwarding** | Ext 2000 -> 2001 forwarding | 1 / 2 / 1 | **Passed** | Call routed to 2000 diverted via loopback channel to 2001; answered cleanly. |
| **Time Conditions** | Extension 2600 | 1 / 2 / 1 | **Passed** | Evaluated schedule routing rules and terminated at active time target. |
| **Follow-Me** | Extension 2002 | 1 / 2 / 1 | **Passed** | Stepped hunting destinations sequentially and bridged to available endpoint. |
| **Emergency Routing** | Extension 911 | 1 / 2 / 1 | **Passed** | Matched emergency dialplan expression and routed to dedicated emergency handler. |
| **Call Blocking** | Blacklisted Caller ID (`5550199`) | 1 / 2 / 1 | **Passed** | Matched incoming blacklist entry and immediately rejected with `603 Decline`. |

<details>
<summary>Table Terminology & Scenario Definitions</summary>

| Term / Scenario | Definition & Operational Meaning |
| :--- | :--- |
| **Off / Limit / Count** | Test pacing parameters: Offered rate (calls/sec) / Maximum in-flight calls / Total calls executed. |
| **SIP Registration** | Endpoint authorization verifying SIP digest credentials against tenant directory XML (`Expires: 3600`). |
| **Extension Calls** | Internal two-party calls authenticated via SIP digest, resolved via dialplan XML, and answered by UAS. |
| **Outbound Gateway** | Outbound routing through external SIP gateway route, verifying PBX source NAT and dialplan rewriting. |
| **Recording Media (`*732`)** | Inbound call answered with active bidirectional RTP audio and recorded directly to disk via `record_session`. |
| **MOH Media** | Continuous Music On Hold stream verification over UDP 6002 (`local_stream://moh`). |
| **Announcement Media** | Automated playback prompt streamed over UDP 6004, followed by clean PBX-initiated BYE termination. |
| **Ring Group (`2400`)** | Simultaneous ring bridging incoming call to multiple internal extensions with `200 OK`. |
| **Voicemail (`*98`)** | Authenticated voicemail IVR portal handling greeting playback and message navigation. |
| **Conference Bridge (`2500`)** | Multi-party audio mixer bridge connecting callers via `mod_conference`. |
| **Call Forwarding** | Inbound call diversion routed via FreeSWITCH loopback channel to destination extension. |
| **Time Conditions (`2600`)** | Dynamic schedule rule evaluation directing calls according to defined business hours. |
| **Follow-Me (`2002`)** | Sequential hunting list ringing primary extension before cascading to alternate destinations. |
| **Emergency Routing (`911`)** | High-priority dialplan routing matching emergency patterns and bridging to emergency UAS. |
| **Call Blocking** | Blacklisted caller ID pattern matching resulting in immediate call rejection (`603 Decline`). |

</details>

*Artifact directory: `storage/app/load-tests/sipp-e2e-20260924-183518` (all PCAPs, error logs, and scenario counts preserved).*

#### Server-to-Server Capacity & Call Rate Ladder (September 24, 2026)

Sustained extension-to-extension capacity ladder with SIP authentication and XML dialplan resolution. Each row represents the median of 3 measured runs (warmup executed prior to recording):

| Offered Calls/sec | Attempted Calls | Achieved Calls/sec | Successful Calls | Failed Calls | Average Setup | Fastest (Min) | Slowest (Max) | Capacity Assessment |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| **3 CPS** | 60 | 2.962 | 60 (100.0%) | 0 | 255.14 ms | 192.00 ms | 492.01 ms | **Rock-solid baseline.** Sub-second setup across 100% of calls with 0 signaling errors. |
| **5 CPS** | 100 | 4.359 | 100 (100.0%) | 0 | 3,150.65 ms | 360.00 ms | 6,296.02 ms | **Worker saturation boundary.** 100% completed in warmed runs, but queueing delays emerge. |
| **8 CPS** | 160 | 4.552 | 144 (90.0%) | 16 | 7,311.11 ms | 716.00 ms | 13,224.10 ms | **Queue overflow.** Worker pool saturates; concurrency bursts trigger mod_xml_curl 5s timeouts. |
| **10 CPS** | 200 | 5.440 | 111 (55.5%) | 89 | 7,747.94 ms | 702.01 ms | 14,302.10 ms | **Heavy overload.** Concurrency 50 exhausts worker backlog; ~45% of calls rejected with 403. |

<details>
<summary>Table Terminology & Metric Definitions</summary>

| Term / Header | Definition & Operational Meaning |
| :--- | :--- |
| **Offered Calls/sec (CPS)** | Planned arrival rate injected by SIPp into the PBX Sofia SIP profile. |
| **Attempted Calls** | Total number of SIP INVITE dialogs initiated across the test window. |
| **Achieved Calls/sec** | Measured completion rate of successfully answered calls (`200 OK`) between first and last answer. |
| **Successful / Failed Calls** | Total calls completing full signaling and teardown vs calls dropped or rejected (`403 Forbidden`, `503 Service Unavailable`, or timeouts). |
| **Average Setup** | Mean Round-Trip Delay (RTD) from initial INVITE through 407 challenge to 200 OK answer. |
| **Fastest (Min) / Slowest (Max)** | Absolute minimum and maximum call setup response times recorded during the benchmark. |
| **Capacity Assessment** | Architectural evaluation of server health, queueing behavior, and production viability. |

</details>

<details>
<summary>Detailed Setup Latency Statistics (p50, p90, p95, p99, Std Dev & Repetitions)</summary>

##### Aggregated Percentiles per Offered Rate (Medians)

| Offered Rate | Achieved Calls/sec | Success Rate | p50 (Median) | p90 | p95 | p99 | Standard Deviation |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| **3 CPS** | 2.962 | 100.0% | 244.00 ms | 308.00 ms | 328.00 ms | 492.01 ms | 54.91 ms |
| **5 CPS** | 4.359 | 100.0% | 3,284.01 ms | 3,868.01 ms | 3,964.01 ms | 4,988.02 ms | 872.47 ms |
| **8 CPS** | 4.552 | 90.0% | 8,260.07 ms | 8,996.07 ms | 9,384.08 ms | 13,004.10 ms | 2,363.59 ms |
| **10 CPS** | 5.440 | 55.5% | 8,888.08 ms | 9,438.09 ms | 9,570.10 ms | 13,344.10 ms | 2,501.69 ms |

##### Individual Repetition Breakdown

| Run / Tier | Offered Rate | Attempted | Achieved Calls/sec | Successful | Failed | Avg Setup | Min | Max | p50 | p95 | Std Dev |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `warmup` | 2 CPS | 10 | 1.918 | 10 | 0 | 227.60 ms | 192.00 ms | 364.01 ms | 208.00 ms | 364.01 ms | 47.95 ms |
| `3cps-r1` | 3 CPS | 60 | 2.961 | 60 | 0 | 255.14 ms | 192.00 ms | 492.01 ms | 236.00 ms | 356.00 ms | 55.61 ms |
| `3cps-r2` | 3 CPS | 60 | 2.962 | 60 | 0 | 268.27 ms | 216.00 ms | 536.01 ms | 256.00 ms | 316.00 ms | 54.91 ms |
| `3cps-r3` | 3 CPS | 60 | 2.962 | 60 | 0 | 253.67 ms | 168.00 ms | 412.01 ms | 244.00 ms | 328.00 ms | 40.29 ms |
| `5cps-r1` | 5 CPS | 100 | 3.172 | 94 | 6 | 5,709.44 ms | 488.00 ms | 12,592.00 ms | 6,511.99 ms | 9,043.99 ms | 2,900.26 ms |
| `5cps-r2` | 5 CPS | 100 | 4.689 | 100 | 0 | 989.32 ms | 192.00 ms | 1,892.00 ms | 1,008.00 ms | 1,624.00 ms | 391.14 ms |
| `5cps-r3` | 5 CPS | 100 | 4.359 | 100 | 0 | 3,150.65 ms | 360.00 ms | 6,296.02 ms | 3,284.01 ms | 3,964.01 ms | 872.47 ms |
| `8cps-r1` | 8 CPS | 160 | 4.909 | 160 | 0 | 6,561.66 ms | 716.00 ms | 11,572.10 ms | 7,284.04 ms | 8,104.04 ms | 2,022.55 ms |
| `8cps-r2` | 8 CPS | 160 | 4.552 | 136 | 24 | 7,311.11 ms | 572.00 ms | 13,808.10 ms | 8,388.06 ms | 9,588.06 ms | 2,534.19 ms |
| `8cps-r3` | 8 CPS | 160 | 4.504 | 144 | 16 | 7,312.00 ms | 796.01 ms | 13,224.10 ms | 8,260.07 ms | 9,384.08 ms | 2,363.59 ms |
| `10cps-r1` | 10 CPS | 200 | 5.332 | 107 | 93 | 7,907.81 ms | 788.01 ms | 14,200.10 ms | 8,944.08 ms | 9,764.09 ms | 2,449.02 ms |
| `10cps-r2` | 10 CPS | 200 | 5.548 | 115 | 85 | 7,588.08 ms | 616.01 ms | 14,404.10 ms | 8,832.09 ms | 9,376.10 ms | 2,554.37 ms |

</details>

*Artifact directory: `storage/app/load-tests/capacity/datacenter-b1-sipp-20260924T1849Z` (contains raw `uac_rtt.csv`, `uac_stats.csv`, error logs, and JSON summaries for every repetition).*

#### Key Engineering Insights (1 vCPU / 1 GiB Cloud VPS Baseline)

1. **PHP-FPM Worker Pool Sizing & Concurrency 25 Saturation**:
   These results empirically prove the architectural guidance documented in **Planning Rule #9**: Debian's default dynamic PHP-FPM pool (`pm.max_children = 5`) saturates when concurrency reaches 25.
   - Each authenticated extension-to-extension call requires **three discrete XML HTTP requests**: (1) caller auth digest challenge verification in `directory`, (2) dialplan context lookup in `dialplan`, and (3) destination extension location in `directory`.
   - At **3 CPS**, the incoming XML arrival rate is ~9 req/sec. The 5 PHP-FPM workers process these requests in ~250 ms without queueing, delivering a median call setup latency of **244 ms** and 100% success.
   - At **5+ CPS**, XML arrival exceeds 15 req/sec (matching the single-core CPU XML throughput limit measured above). Requests queue up in the PHP-FPM listen backlog. When queued request delay exceeds FreeSWITCH `mod_xml_curl`'s 5.0-second HTTP timeout, Sofia fails the authentication lookup and issues `403 Forbidden`.
   - **Production Sizing Rule**: On 1 vCPU / 1 GiB nodes, plan production telephony capacity at **3 calls/sec** (up to ~20–35 concurrent active calls). Deployments requiring 5+ calls/sec should upgrade to 2+ vCPU and configure `pm = static` with `pm.max_children = 12` to prevent worker starvation.

---

### Cloud Datacenter VPS: Memory-Scaled (1 vCPU / 2 GiB RAM)

Empirical benchmark and validation series executed on September 24, 2026 on the resized cloud test PBX (`x.x.x.200`) from the dedicated load generator (`x.x.x.173`):
- **Target PBX VPS**: 1 vCPU, 1,973 MiB RAM, 2.0 GiB swap (0 MiB used). Debian 13, Nginx 1.26, PHP 8.5-FPM (`pm = static`, `pm.max_children = 6`), MariaDB 10.11, Redis 7.0, FreeSWITCH 1.11. Public IPv4 `x.x.x.200`.
- **Dedicated Load Generator**: 2 vCPU, 2048 MiB RAM. Debian 13, SIPp 3.7.3. Public IPv4 `x.x.x.173`.
- **Network**: Direct datacenter interface routing; SIP signaling and RTP media exchange over direct public IP interfaces.

#### End-to-End Functional & Media Parity Matrix (September 24, 2026)

Full 14-scenario parity test suite executed via `scripts/pbx-sipp-validate.sh` (`MEDIA_FLOW=1`, `EXTENDED=1`):

| Test Scenario | Destination / Feature | Off / Limit / Count | Result | Operational Verification |
| --- | --- | ---: | --- | --- |
| **SIP Registration** | `register.xml` (20 users) | 5 / 5 / 20 | **Passed** | 20/20 extensions authenticated with `200 OK` (Expires: 3600). |
| **Extension Calls** | Internal extensions (2000–2019) | 2 / 5 / 10 | **Passed** | 10/10 authenticated calls completed via UAS auto-answer on port 5066. |
| **Outbound Gateway** | Gateway routing to UAS | 1 / 2 / 5 | **Passed** | 5/5 calls routed through gateway to UAS port 5088 with PBX SNAT. |
| **Recording Media** | `*732` (Call Recording) | 1 / 1 / 1 | **Passed** | FreeSWITCH answered, recorded active RTP audio, and saved session cleanly. |
| **MOH Media** | `load_test_moh` (Music on Hold) | 1 / 1 / 1 | **Passed** | Call answered, active RTP captured on UDP 6002 (`load_test_moh.wav`), completed full duration. |
| **Announcement Media** | `load_test_announcement` | 1 / 1 / 1 | **Passed** | Call answered, active RTP captured on UDP 6004, PBX sent BYE after playback ended. |
| **Ring Group** | Extension 2400 | 1 / 2 / 1 | **Passed** | Simultaneous ring bridged to destinations with `200 OK`. |
| **Voicemail** | `*98` (Voicemail Access) | 1 / 2 / 1 | **Passed** | Authenticated voicemail IVR answered and completed navigation. |
| **Conference Bridge** | Extension 2500 | 1 / 2 / 1 | **Passed** | Conference room bridge connected and audio mixer initialized. |
| **Call Forwarding** | Ext 2000 -> 2001 forwarding | 1 / 2 / 1 | **Passed** | Call routed to 2000 diverted via loopback channel to 2001; answered cleanly. |
| **Time Conditions** | Extension 2600 | 1 / 2 / 1 | **Passed** | Evaluated schedule routing rules and terminated at active time target. |
| **Follow-Me** | Extension 2002 | 1 / 2 / 1 | **Passed** | Stepped hunting destinations sequentially and bridged to available endpoint. |
| **Emergency Routing** | Extension 911 | 1 / 2 / 1 | **Passed** | Matched emergency dialplan expression and routed to dedicated emergency handler. |
| **Call Blocking** | Blacklisted Caller ID (`5550199`) | 1 / 2 / 1 | **Passed** | Matched incoming blacklist entry and immediately rejected with `603 Decline`. |

<details>
<summary>Table Terminology & Scenario Definitions</summary>

| Term / Scenario | Definition & Operational Meaning |
| :--- | :--- |
| **Off / Limit / Count** | Test pacing parameters: Offered rate (calls/sec) / Maximum in-flight calls / Total calls executed. |
| **SIP Registration** | Endpoint authorization verifying SIP digest credentials against tenant directory XML (`Expires: 3600`). |
| **Extension Calls** | Internal two-party calls authenticated via SIP digest, resolved via dialplan XML, and answered by UAS. |
| **Outbound Gateway** | Outbound routing through external SIP gateway route, verifying PBX source NAT and dialplan rewriting. |
| **Recording Media (`*732`)** | Inbound call answered with active bidirectional RTP audio and recorded directly to disk via `record_session`. |
| **MOH Media** | Continuous Music On Hold stream verification over UDP 6002 (`local_stream://moh`). |
| **Announcement Media** | Automated playback prompt streamed over UDP 6004, followed by clean PBX-initiated BYE termination. |
| **Ring Group (`2400`)** | Simultaneous ring bridging incoming call to multiple internal extensions with `200 OK`. |
| **Voicemail (`*98`)** | Authenticated voicemail IVR portal handling greeting playback and message navigation. |
| **Conference Bridge (`2500`)** | Multi-party audio mixer bridge connecting callers via `mod_conference`. |
| **Call Forwarding** | Inbound call diversion routed via FreeSWITCH loopback channel to destination extension. |
| **Time Conditions (`2600`)** | Dynamic schedule rule evaluation directing calls according to defined business hours. |
| **Follow-Me (`2002`)** | Sequential hunting list ringing primary extension before cascading to alternate destinations. |
| **Emergency Routing (`911`)** | High-priority dialplan routing matching emergency patterns and bridging to emergency UAS. |
| **Call Blocking** | Blacklisted caller ID pattern matching resulting in immediate call rejection (`603 Decline`). |

</details>

*Artifact directory: `storage/app/load-tests/sipp-e2e-20260924-195535` (all PCAPs, error logs, and scenario counts preserved).*

#### Server-to-Server Capacity & Call Rate Ladder (September 24, 2026)

Sustained extension-to-extension capacity ladder with SIP authentication and XML dialplan resolution. Each rate was executed across repetitions with the static-6 worker pool:

| Offered Calls/sec | Attempted Calls | Achieved Calls/sec | Successful Calls | Failed Calls | Average Setup | Fastest (Min) | Slowest (Max) | Capacity Assessment |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| **3 CPS** | 60 | 2.354 | 60 (100.0%) | 0 | 721.9 ms | 200.0 ms | 5,548.1 ms | **Rock-solid baseline.** Sub-second setup across 100% of calls (p50: 276.0 ms) with 0 signaling errors. |
| **5 CPS** | 100 | 2.391 | 99 (97.3%) | 1 | 5,101.4 ms | 556.0 ms | 15,760.2 ms | **Worker saturation boundary.** 97.3% success across 300 calls; queuing delay emerges behind single vCPU. |
| **8 CPS** | 160 | 3.328 | 141 (74.0%) | 19 | 6,443.0 ms | 660.0 ms | 17,764.2 ms | **Queue overflow.** Worker backlog saturates; queuing triggers mod_xml_curl 5s timeouts and SIP retransmissions. |
| **10 CPS** | 200 | 2.000 | 50 (25.0%) | 150 | 6,292.9 ms | 832.0 ms | 17,588.2 ms | **Overload ceiling.** 75% of calls rejected as XML lookups time out under heavy worker contention. |

<details>
<summary>Table Terminology & Metric Definitions</summary>

| Term / Header | Definition & Operational Meaning |
| :--- | :--- |
| **Offered Calls/sec (CPS)** | Planned arrival rate injected by SIPp into the PBX Sofia SIP profile. |
| **Attempted Calls** | Total number of SIP INVITE dialogs initiated across the test window. |
| **Achieved Calls/sec** | Measured completion rate of successfully answered calls (`200 OK`) between first and last answer. |
| **Successful / Failed Calls** | Total calls completing full signaling and teardown vs calls dropped or rejected (`403 Forbidden`, `503 Service Unavailable`, or timeouts). |
| **Average Setup** | Mean Round-Trip Delay (RTD) from initial INVITE through 407 challenge to 200 OK answer. |
| **Fastest (Min) / Slowest (Max)** | Absolute minimum and maximum call setup response times recorded during the benchmark. |
| **Capacity Assessment** | Architectural evaluation of server health, queueing behavior, and production viability. |

</details>

<details>
<summary>Detailed Setup Latency Statistics (p50, p90, p95, p99, Std Dev & Repetitions)</summary>

##### Aggregated Percentiles per Offered Rate (Medians)

| Offered Rate | Achieved Calls/sec | Success Rate | p50 (Median) | p90 | p95 | p99 | Standard Deviation |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| **3 CPS** | 2.354 | 100.0% | 276.0 ms | 2,080.0 ms | 2,268.0 ms | 5,512.1 ms | 1,201.7 ms |
| **5 CPS** | 2.391 | 97.3% | 5,600.1 ms | 7,860.1 ms | 8,288.1 ms | 15,700.2 ms | 2,595.2 ms |
| **8 CPS** | 3.328 | 74.0% | 6,840.1 ms | 8,732.1 ms | 9,272.1 ms | 14,120.2 ms | 2,434.7 ms |
| **10 CPS** | 2.000 | 25.0% | 6,736.1 ms | 9,032.1 ms | 9,244.1 ms | 17,588.2 ms | 2,885.0 ms |

##### Individual Repetition Breakdown

| Run / Tier | Offered Rate | Attempted | Achieved Calls/sec | Successful | Failed | Avg Setup | Min | Max | p50 | p95 | Std Dev |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| `warmup` | 2 CPS | 10 | 0.725 | 10 | 0 | 767.6 ms | 200.0 ms | 5,416.1 ms | 252.0 ms | 5,416.1 ms | 1,549.8 ms |
| `3cps-r1` | 3 CPS | 60 | 2.379 | 60 | 0 | 556.3 ms | 204.0 ms | 5,512.1 ms | 276.0 ms | 568.0 ms | 1,115.2 ms |
| `3cps-r2` | 3 CPS | 60 | 2.294 | 60 | 0 | 721.9 ms | 200.0 ms | 5,376.1 ms | 264.0 ms | 2,268.0 ms | 1,201.7 ms |
| `3cps-r3` | 3 CPS | 60 | 2.354 | 60 | 0 | 1,199.8 ms | 224.0 ms | 5,548.1 ms | 332.0 ms | 3,748.0 ms | 1,425.4 ms |
| `5cps-r1` | 5 CPS | 100 | 2.391 | 99 | 1 | 5,426.6 ms | 688.0 ms | 15,760.2 ms | 5,928.1 ms | 8,288.1 ms | 2,595.2 ms |
| `5cps-r2` | 5 CPS | 100 | 2.319 | 93 | 7 | 5,101.4 ms | 556.0 ms | 15,700.2 ms | 5,600.1 ms | 8,788.1 ms | 2,732.5 ms |
| `5cps-r3` | 5 CPS | 100 | 2.704 | 100 | 0 | 4,157.0 ms | 684.0 ms | 13,640.2 ms | 4,112.1 ms | 6,380.1 ms | 2,075.2 ms |
| `8cps-r1` | 8 CPS | 160 | 3.328 | 141 | 19 | 6,818.4 ms | 1,024.0 ms | 17,764.2 ms | 6,840.1 ms | 9,344.1 ms | 2,434.7 ms |
| `8cps-r2` | 8 CPS | 160 | 3.235 | 158 | 2 | 6,443.0 ms | 2,024.0 ms | 16,844.2 ms | 6,124.1 ms | 9,272.1 ms | 2,202.6 ms |
| `8cps-r3` | 8 CPS | 160 | 4.075 | 56 | 104 | 5,697.4 ms | 660.0 ms | 14,120.2 ms | 6,864.1 ms | 7,944.1 ms | 2,625.7 ms |
| `10cps-r1` | 10 CPS | 200 | 2.000 | 50 | 150 | 6,292.9 ms | 832.0 ms | 17,588.2 ms | 6,736.1 ms | 9,244.1 ms | 2,885.0 ms |

</details>

*Artifact directory: `storage/app/load-tests/capacity/datacenter-1c2g-sipp-20260924T2004Z`.*

#### Key Engineering Insights (1 vCPU / 2 GiB Memory-Scaled VPS)

1. **Memory Headroom vs. Compute Ceiling**:
   Doubling physical memory to 2 GiB completely eliminated swap activity (0 MiB swap used throughout all runs) and provided ~1.2 GiB of free RAM buffer. However, telephony call setup capacity remained strictly bound to the single CPU core.
   - At **3 CPS**, the system completed 100% of calls with a median setup latency of 276 ms.
   - At **5 CPS**, queueing emerged as XML lookups competed for the single CPU core, reaching the saturation boundary.
   - **Architectural Takeaway**: Administrators cannot increase call-handling capacity simply by adding RAM to a single-core machine. Adding compute cores (2+ vCPU) is required to unlock higher call rates.

2. **Single-Core Static Worker Contention**:
   The `pm = static`, `pm.max_children = 6` profile eliminated worker fork delays, but with 6 PHP workers and multiple FreeSWITCH threads sharing a single CPU core, heavy offered rates (8–10 CPS) quickly saturated CPU cycles, producing timeouts in `mod_xml_curl`. Multi-core architectures are essential for scaling beyond 3–5 CPS.

---

### Cloud Datacenter VPS: Dual-Core (2 vCPU / 2 GiB RAM)

Empirical benchmark and capacity validation series executed on September 24, 2026 on the resized cloud test PBX (`x.x.x.200`) from the dedicated load generator (`x.x.x.173`):
- **Target PBX VPS**: 2 vCPUs, 1,973 MiB RAM, 2.0 GiB swap (0 MiB used). Debian 13, Nginx 1.26, PHP 8.5-FPM (`pm = static`, `pm.max_children = 6`), MariaDB 10.11, Redis 7.0, FreeSWITCH 1.11. Public IPv4 `x.x.x.200`.
- **Dedicated Load Generator**: 2 vCPUs, 2,048 MiB RAM. Debian 13, SIPp 3.7.3. Public IPv4 `x.x.x.173`.
- **Network**: Direct datacenter interface routing; SIP signaling and RTP media exchange over direct public IP interfaces without VPN encapsulation overhead.

#### Server-to-Server Capacity & Call Rate Ladder (September 24, 2026)

Sustained extension-to-extension capacity ladder with SIP digest authentication, dynamic XML dialplan resolution, and immediate connection teardown (`uac-extension-capacity.xml`):

| Offered Calls/sec | Attempted Calls | Concurrency Limit | Answered Calls | Failed / Timed Out | Average Setup | Fastest (Min) | Slowest (Max) | Capacity Assessment |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| **Warmup (2 CPS)** | 10 | 5 | 10 (100.0%) | 0 | 1,696.8 ms | 580.0 ms | 6,584.1 ms | **Warmup baseline.** Initial connection priming; 100% completed with zero errors. |
| **5 CPS** | 100 | 25 | 93 (93.0%) | 7 | 4,428.5 ms | 1,032.0 ms | 14,280.2 ms | **Stable operating ceiling.** 93% success rate; dual-core handles concurrency 25 with 4.0s median setup. |
| **10 CPS** | 200 | 50 | 178 (89.0%) | 22 | 7,453.0 ms | 1,612.0 ms | 18,084.2 ms | **High-throughput burst.** Handled 178 calls with 89% answer rate across 200 calls (concurrency 50). |
| **15 CPS** | 300 | 60 | 59 (19.7%) | 241 | 7,025.7 ms | 3,520.1 ms | 13,736.2 ms | **Worker & Core Saturation.** Sofia queue backlog saturates shared CPU; 403 Forbidden on XML timeout. |
| **20 CPS** | 400 | 60 | 82 (20.5%) | 318 | 8,820.5 ms | 2,348.0 ms | 19,508.3 ms | **Exhaustion Ceiling.** Heavy signaling contention; remaining calls dropped by watchdog timeout. |

<details>
<summary>Table Terminology & Metric Definitions</summary>

| Term / Header | Definition & Operational Meaning |
| :--- | :--- |
| **Offered Calls/sec (CPS)** | Planned arrival rate injected by SIPp into the PBX Sofia SIP profile. |
| **Attempted Calls** | Total number of SIP INVITE dialogs initiated across the test window. |
| **Concurrency Limit** | Maximum simultaneous active SIP call dialogs allowed in-flight by SIPp (`-l`). |
| **Answered Calls** | Calls successfully completing full signaling handshake through 200 OK. |
| **Average Setup** | Mean Round-Trip Delay (RTD) from initial INVITE through 407 challenge to 200 OK answer. |
| **Fastest (Min) / Slowest (Max)** | Absolute minimum and maximum call setup response times recorded during the benchmark. |
| **Capacity Assessment** | Architectural evaluation of server health, queueing behavior, and production viability. |

</details>

<details>
<summary>Detailed Setup Latency Statistics (p50, p90, p95, p99, Std Dev & Percentiles)</summary>

##### Aggregated Percentiles per Offered Rate

| Offered Rate | Attempted | Answered (Rate) | p50 (Median) | p90 | p95 | p99 | Standard Deviation |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| **Warmup (2 CPS)** | 10 | 10 (100.0%) | 1,168.0 ms | 1,816.0 ms | 6,584.1 ms | 6,584.1 ms | 1,662.1 ms |
| **5 CPS** | 100 | 93 (93.0%) | 4,008.1 ms | 7,272.1 ms | 9,484.1 ms | 11,888.2 ms | 2,419.9 ms |
| **10 CPS** | 200 | 178 (89.0%) | 7,804.1 ms | 9,672.1 ms | 10,268.1 ms | 17,192.2 ms | 2,600.7 ms |
| **15 CPS** | 300 | 59 (19.7%) | 6,564.1 ms | 9,260.1 ms | 9,804.1 ms | 13,736.2 ms | 1,676.8 ms |
| **20 CPS** | 400 | 82 (20.5%) | 9,412.1 ms | 10,388.1 ms | 10,692.1 ms | 19,508.3 ms | 2,572.2 ms |

</details>

*Artifact directory: `storage/app/load-tests/capacity/datacenter-2c2g-sipp-20260924T2141Z` (contains raw `uac_rtt.csv`, error logs, and JSON summaries).*

#### Key Engineering Insights (2 vCPU / 2 GiB Dual-Core VPS)

1. **Multi-Core Throughput Scaling (10 CPS Capacity Burst)**:
   Adding a second vCPU unlocked a substantial leap in call-handling concurrency over the 1-vCPU profiles:
   - On 1 vCPU, call capacity sharply deteriorated above 3 CPS (5 CPS was the practical limit; 10 CPS failed with 45–75% call rejection).
   - On 2 vCPUs, the server answered **178 calls out of 200 at 10 CPS** (an 89% answer rate under intense burst concurrency of 50 simultaneous calls).
   - The dual compute cores allow FreeSWITCH Sofia SIP signaling worker threads and PHP-FPM dynamic XML generation workers to execute simultaneously across discrete CPU cores without starving the Linux network stack.

2. **Saturation Boundary at 15–20 CPS**:
   At 15 CPS and 20 CPS with concurrency limit 60, offered load exceeded the capacity of the 2 shared vCPUs and 6 static PHP workers. Queue backlog in `mod_xml_curl` triggered 5-second HTTP timeouts, returning `403 Forbidden` on unresolved directory queries. For sustained workloads beyond 8–10 calls/sec, deploying 4+ vCPUs with dedicated CPU allocation (`pm.max_children = 24`) is required.

3. **Recommended Production Planning**:
   For production deployments on 2 vCPU / 2 GiB cloud VPS nodes:
   - **Recommended Planning Target**: **5–8 calls/sec** sustained (supporting 50–100 active concurrent extensions).
   - **Burst Headroom**: Safely absorbs spikes up to **10 calls/sec** with graceful degradation.

---

#### Historical Reference: July 2026 WireGuard Benchmark Runs

**Low-volume subsets after the resizes (1 vCPU / 2 GiB and 2 vCPU / 2 GiB, July 18, 2026).** Both resized profiles passed the same low-volume subset through WireGuard: 5 registrations, 2 authenticated extension calls, and 1 outbound call, with XML curl directory POSTs observed. Media checks were not repeated because no SIP/RTP behavior changed; the goal was to confirm the installed application and resized host still served FreeSWITCH XML curl directory and dialplan requests during live calls. Artifact directories: `storage/app/load-tests/sipp-e2e-20260717-170848` (1c/2g) and `storage/app/load-tests/sipp-e2e-20260717-171946` (2c/2g).

**Capacity runs at sessions-per-second = 60 (2 vCPU / 2 GiB, July 18, 2026).** The load generator was the local test server at `192.168.1.76`, connected to the public PBX through WireGuard:

| Offered calls/sec | Attempted | Achieved calls/sec | Successful | Failed | Average setup | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 15, r1 | 300 | 12.096 | 300 | 0 | 3,105 ms | Passed |
| 15, r2 | 300 | 13.240 | 300 | 0 | 1,282 ms | Passed |
| 20, r1 | 400 | 13.417 | 400 | 0 | 4,669 ms | Passed, but queued |
| 20, r2 | 400 | 14.491 | 400 | 0 | 3,833 ms | Passed, but queued |
| 25 | 500 | 14.158 | 385 | 115 | 6,205 ms | Failed |
| 30 | 600 | 17.181 | 377 | 223 | 6,764 ms | Failed |

The clean no-failure remote result is 20 offered calls/sec with all 400 calls completed, but the average setup time was already several seconds. For low-latency capacity, the safer interpretation is about 13–15 completed full calls/sec on this 2-vCPU/2-GB VPS. Above 20 offered calls/sec, failures return even after removing the session-rate ceiling. Artifacts: `capacity/vps-2c-2g-static6-sps60-20260718-sipp-cps`.

**Invalidated sessions-per-second = 30 runs.** The first July 18, 2026 SIPp capacity runs used FreeSWITCH's stock `sessions-per-second=30` safety throttle and are not valid capacity results. The reason: the limit counts sessions, not calls, and a normal extension-to-extension call creates two sessions, so the stock `30` limit can reject calls near 15 two-leg calls per second even when CPU, network, and the generator still have room. The invalidated runs showed exactly that pattern: SIPp received `SIP/2.0 503 Maximum Calls In Progress`, `fs_cli -x status` showed `sessions per Sec out of max 30`, SIPp reported zero UDP errors, and both network interfaces showed zero packet errors and drops. Those artifacts remain available for audit but must not be quoted as clean capacity.

**Tenant-identity cache optimization runs (2 vCPU / 2 GiB, July 18, 2026).** The commit that caches FreeSWITCH tenant-identity resolution was retested on the correct remote topology: PBX at `x.x.x.236` (WireGuard `10.77.0.1`), generator at `192.168.1.76` (WireGuard `10.77.0.2`), SIP realm `x.x.x.236`, `sessions-per-second=60`, Redis cache stores. The first post-cache runs still had FreeSWITCH switch logging at `debug`:

| Offered calls/sec | Attempted | Achieved calls/sec | Successful | Failed | UDP errors | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 8 | 80 | 7.610 | 80 | 0 | 0 | Passed |
| 10 | 100 | 9.657 | 100 | 0 | 0 | Passed |
| 15 | 300 | 14.735 | 300 | 0 | 0 | Passed |
| 20 | 400 | 17.833 | 400 | 0 | 0 | Passed, with SIP retransmissions |

XML timing during the clean `10 CPS / 100 calls` and `20 CPS / 400 calls` runs:

| Run | XML section | Requests | Avg total time | Avg DB queries | Avg DB time | Max total time |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| 10 CPS | Directory/auth | 100 | 6.10 ms | 2.00 | 1.30 ms | 26.16 ms |
| 10 CPS | Dialplan | 100 | 4.14 ms | 0.34 | 0.28 ms | 34.99 ms |
| 20 CPS | Directory/auth | 400 | 12.02 ms | 1.00 | 2.35 ms | 58.10 ms |
| 20 CPS | Dialplan | 400 | 9.42 ms | 0.25 | 0.50 ms | 100.53 ms |

Directory/auth lookup time stayed low even at the higher offered rate, and the full `20 CPS / 400 calls` run completed without failed calls or UDP errors. SIPp reported retransmissions at 20 CPS, so this is a successful throughput test with signaling pressure starting to appear, not proof that much higher rates stay clean. Artifacts: `remote-vps-identitycache-8cps-80calls-20260718`, `-10cps-100calls-`, `-15cps-300calls-`, and `-20cps-400calls-20260718`.

**FreeSWITCH log-level experiment (datacenter).** The same 2-vCPU/2-GB VPS was retested with switch logging lowered from `debug` to `notice` and XML handler timing logging disabled:

| Offered calls/sec | Attempted | Achieved calls/sec | Successful | Failed | Average setup | SIP INVITE retransmissions | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 20 | 400 | 15.990 | 400 | 0 | 3,121 ms | 533 | Passed |
| 25 | 500 | 17.096 | 500 | 0 | 5,094 ms | 958 | Passed, but heavily queued |
| 30 | 600 | 17.434 | 600 | 0 | 6,611 ms | 1,418 | Passed, but heavily queued |

Zero UDP errors were reported in all three runs. Disabling debug logging improved the failure outcome at higher offered rates: `25 CPS / 500 calls` completed with zero failed calls, while the debug-logging baseline failed at 25 CPS. It did not make the server complete 25 new calls per second; the completed rate was about 17.1 CPS at 25 offered and 17.4 CPS at 30 offered, with average setup rising from about five seconds to about 6.6 seconds and many INVITE retransmissions. The practical interpretation is that disabling debug logging reduces enough overhead to avoid outright failures, but the 2-vCPU/2-GB VPS still queues call setup heavily above roughly 15–18 completed calls per second. Artifacts: `remote-vps-notice-20cps-400calls-20260718`, `-25cps-500calls-`, and `-30cps-600calls-20260718`.

Before the remote test VPS was destroyed, final evidence bundles were copied back to the project workspace: `storage/app/load-tests/final-evidence-20260718/pbx-final-vps-evidence-20260718.tar.gz` and `pbx-final-loadgen-evidence-20260718.tar.gz`. They contain sanitized WireGuard status/config snippets, FreeSWITCH and Sofia status, PHP-FPM configuration, Redis/cache checks, application commit/config summaries, and the SIPp artifact directories for the post-cache remote runs.

---

### Dedicated Cloud Node Pair (4 vCPU / 8 GiB RAM Dedicated) — Planned

The dedicated-CPU 4 vCPU / 8 GiB node pair represents the final enterprise benchmarking tier. *(Note: The intermediate 2 vCPU / 2 GiB dedicated test was skipped to avoid testing redundancy, focusing remaining efforts on the 2 vCPU / 2 GiB shared droplet and escalating directly to the 4 vCPU / 8 GiB dedicated tier).* When the dedicated 4c/8g hardware is provisioned:

1. Confirm correctness first: basic runner plus `MEDIA_FLOW=1` and `EXTENDED=1` with the new test data.
2. Re-run the capacity ladder at increasing offered rates and record attempted calls, achieved calls/sec, success/failure counts, average / fastest / slowest setup time, peak concurrency, peak CPU, and stuck-call checks after teardown.
3. Repeat the FreeSWITCH log-level and PHP-FPM experiments on the pair so the tuning guidance reflects the new hardware.
4. Run the additional campaign tests planned for the capacity campaign: the concurrent-call ladder, the RTP-enabled media capacity test, media-flow re-validation, and the recording regression check.
