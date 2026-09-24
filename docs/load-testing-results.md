# PBX SIP Load Testing & Benchmark Results

Clear, real-world capacity guidance for administrators. This document records empirical results from the September 2026 VirtualBox test series and historical datacenter VPS benchmarks, and provides hardware sizing guidance for system administrators.

> [!NOTE]
> For the operational manual, lab topology setup, seeding instructions, and test runner options, see the companion **[SIP Load Testing Guide](load-testing-guide.md)**.

## Contents

- [Executive Summary & Hardware Sizing Matrix for Administrators](#executive-summary--hardware-sizing-matrix-for-administrators)
- [Production Recommendations: XML Caching & PHP-FPM Worker Tuning](#production-recommendations-xml-caching--php-fpm-worker-tuning)
- [Planning Rules](#planning-rules)
- [How Results Are Recorded & How to Read Benchmark Tables](#how-results-are-recorded--how-to-read-benchmark-tables)
- [Results: Single-Server Dynamic Dialplan XML Tests (Phase 1)](#results-single-server-dynamic-dialplan-xml-tests-phase-1)
  - [Environment A1: Isolated VirtualBox Test VM (4 vCPU / 4 GiB RAM) — Stage 1](#environment-a1-isolated-virtualbox-test-vm-4-vcpu--4-gib-ram--stage-1)
  - [Environment B1: Cloud Datacenter VPS Minimum Baseline (1 vCPU / 1 GiB RAM) — Stage 2](#environment-b1-cloud-datacenter-vps-minimum-baseline-1-vcpu--1-gib-ram--stage-2)
  - [Environment B2: Cloud Datacenter VPS In-Place Memory Resize (1 vCPU / 2 GiB RAM) — Stage 3](#environment-b2-cloud-datacenter-vps-in-place-memory-resize-1-vcpu--2-gib-ram--stage-3)
  - [Environment B3: Cloud Datacenter VPS Dual-Core Compute Resize (2 vCPU / 2 GiB RAM) — Stage 4](#environment-b3-cloud-datacenter-vps-dual-core-compute-resize-2-vcpu--2-gib-ram--stage-4)
  - [Planned Cloud Datacenter Profiles (Environments C1 & C2: Dedicated 8 GiB) — Stages 5–6](#planned-cloud-datacenter-profiles-environments-c1--c2-dedicated-8-gib--stages-56)
- [Results: Server-To-Server Call Tests (Phase 2)](#results-server-to-server-call-tests-phase-2)
  - [Environments A1 + A2: Local VirtualBox Test Pair — Stage 1](#environments-a1--a2-local-virtualbox-test-pair--stage-1)
  - [Environments B1 + D: Cloud Datacenter Server & Remote Generator Pair — Stage 2](#environments-b1--d-cloud-datacenter-server--remote-generator-pair--stage-2)
  - [Planned Cloud Datacenter Pairs (Environments C1 & C2 with D) — Stages 4–5](#planned-cloud-datacenter-pairs-environments-c1--c2-with-d--stages-45)

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

The tables in this document present the September 23, 2026 VirtualBox A1 test progression (single-server throughput ladder and 5-tier cache sweep), followed by the historical July–August 2026 datacenter reference runs.

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

## Results: Single-Server Dynamic Dialplan XML Tests (Phase 1)

### Environment A1: Isolated VirtualBox Test VM (4 vCPU / 4 GiB RAM) — Stage 1

Environment A1 (`192.168.1.71`) is an isolated, disposable VirtualBox virtual machine running Debian 13 on a local Windows 11 workstation. It was provisioned purely as an isolated test bench for benchmark execution alongside its sibling orchestrator VM A2 (`192.168.1.76`). Neither VM functions as an office PBX or live production server; they exist purely as a controlled, reproducible virtualization test bench.

The September 23, 2026 test series executed the full progression against A1 from orchestrator A2. All test runs used authenticated XML endpoints with composite database indexes and Redis fragment caching active.

> [!NOTE]
> **Historical Baseline Metrics**:
> The September 23, 2026 VirtualBox baseline tests were recorded before full percentile and per-request raw telemetry were integrated into the test runner. As a result, these historical baseline tables report **Average Latency**, **Fastest (Min)**, and **Slowest (Max)**. All subsequent cloud datacenter benchmarks capture complete granular telemetry (including **p50 Median**, **p90**, **p95**, **p99**, and **Standard Deviation**) alongside complete raw sample datasets.

#### Single-Server XML Throughput Ladder

Controlled runs, all `mixed` scenario, zero failed XML responses:

| Tier | Repetitions | Median Req/Sec | Average Latency | Fastest Latency | Slowest Latency | Notes / Observations |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| `25 x 1` | 1 (warm-up) | 4.497 | 217.9 ms | 148 ms | 541 ms | Clean initial warm-up; Redis caches populated. |
| `100 x 5` | 3 | 12.760 | 273.4 ms | 162 ms | 478 ms | Repeatable baseline across 3 consecutive runs. |
| `500 x 10` | 3 | 15.758 | 385.3 ms | 155 ms | 682 ms | Smooth scaling under moderate concurrency. |
| `500 x 25` (dynamic pool) | 3 | 16.718 | 804.9 ms | 159 ms | 1,180 ms | Debian default pool (`pm.max_children = 5`) saturated with repeated worker warnings. |
| `500 x 25` (static 12 pool) | 3 | 19.232 | 828.8 ms | 163 ms | 1,142 ms | **+15.0% throughput gain**; zero worker warnings; 2.9 GiB free RAM. |
| `1,000 x 25` (static 12 pool) | 3 | 16.644 | 814.3 ms | 158 ms | 1,196 ms | Zero failures across 3,000 requests; stable sustained burst ceiling. |

Guest specifications for these runs: Debian GNU/Linux 13 (trixie), kernel `6.12.95+deb13-amd64`, 4 vCPU (12th Gen Intel Core i5-1235U), 3.8 GiB RAM, 2.0 GiB swap, 39.5 GiB virtual disk. MariaDB reported zero slow queries throughout all tiers.

#### Cache Optimization and Hit Rate Sweep (September 23, 2026)

The 5-run cache optimization sweep on the VirtualBox PBX evaluated performance from raw database execution to 100% memory hit ceiling:

| Run Configuration | Scenario | Target Requests | Requests/sec | Average Latency | Fastest | Slowest | Redis Hit Rate | Key Observation |
| --- | --- | --- | ---: | ---: | ---: | ---: | ---: | --- |
| 1. Uncached Cold Baseline (`TTL=0`) | `mixed` | `100 x 5` | 9.193 | 398.9 ms | 220.1 ms | 994 ms | 0.0% | Heavy MariaDB query execution; average latency of 398.9 ms. |
| 2. Contributor Fragment Cache (`C=5, D=0`) | `mixed` | `100 x 5` | 12.431 | 317.8 ms | 165.0 ms | 698 ms | 46.6% | Reused static routing fragments; cut MariaDB table reads by ~80%. |
| 3. Production Baseline (`D=5, C=5`) | `mixed` | `100 x 5` | 13.176 | 260.9 ms | 126.2 ms | 472 ms | 41.2% | Recommended production baseline; optimal average latency with 5s update convergence. |
| 4. Extended Burst Call Center (`TTL=30`) | `mixed` | `100 x 5` | 14.110 | 262.9 ms | 134.3 ms | 542 ms | 44.2% | Highest throughput under sustained bursts; peak Redis hit efficiency. |
| 5. Pure Memory Cache-Hit Ceiling | `cache-hit` | `100 x 5` | 13.932 | 235.4 ms | 116.6 ms | 387 ms | 29.3% | Zero MariaDB queries; lowest average latency (pure memory/serialization). |

> [!NOTE]
> **Understanding Row 5 vs. Row 3 (Pure Memory Ceiling vs. Production Baseline)**:
> Both Row 3 and Row 5 execute `100 x 5` requests, but they test fundamentally different code paths:
> - **Row 3 (Production Baseline)** executes the **`mixed` scenario** across varied destinations (extensions, ring groups, and IVRs). While static dialplan fragments are cached in Redis, resolving different destinations still exercises routing logic and contributor lookups (achieving 41.2% cache hit rate, average latency of 260.9 ms).
> - **Row 5 (Pure Memory Cache-Hit Ceiling)** executes the **`cache-hit` scenario**, querying the exact same destination 100 times consecutively. After request 1 populates Redis, the remaining 99 requests are served directly from Redis memory with zero MariaDB queries. This drops average latency to 235.4 ms (with fastest response at 116.6 ms), demonstrating the pure serialization ceiling of PHP-FPM and Redis when database I/O is eliminated.

Artifacts: `storage/app/load-tests/vbox-20260923/` and `storage/app/load-tests/vbox-cache-sweep-20260923-123631/`.

---

### Environment B1: Cloud Datacenter VPS Minimum Baseline (1 vCPU / 1 GiB RAM) — Stage 2

Environment B1 (`x.x.x.200`) is a cloud datacenter virtual private server running Debian GNU/Linux 13 (trixie) with 1 shared vCPU, 967 MiB RAM, and 2.0 GiB swap. It represents the entry-level production footprint for a minimal cloud PBX branch or small office.

The September 24, 2026 benchmark series executed the full Phase 1 progression directly against the production Nginx and PHP 8.5-FPM stack (`pm = dynamic`, maximum 5 workers). Dialplan endpoints were fully authenticated using composite database indexes and Redis fragment caching against tenant `load-test-beta` (seeded with 20 extensions and dynamic call routing).

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
| 1. Uncached Cold Baseline (`TTL=0`) | `mixed` | `100 x 5` | 8.358 | 565.1 ms | 475.7 ms | 757.1 ms | 0.0% | Heavy MariaDB query execution; average latency of 565.1 ms. |
| 2. Contributor Fragment Cache (`C=5, D=0`) | `mixed` | `100 x 5` | 14.020 | 291.8 ms | 153.5 ms | 673.4 ms | 46.8% | Reused static routing fragments; cut MariaDB table reads by nearly half. |
| 3. Production Baseline (`D=5, C=5`) | `mixed` | `100 x 5` | 17.221 | 245.3 ms | 157.6 ms | 511.9 ms | 39.1% | Recommended production baseline; lowest average latency with 5s update convergence. |
| 4. Extended Burst Call Center (`TTL=30`) | `mixed` | `100 x 5` | 17.196 | 257.8 ms | 171.2 ms | 562.2 ms | 42.2% | Sustained high throughput with extended Redis keyspace retention. |
| 5. Pure Memory Cache-Hit Ceiling | `cache-hit` | `100 x 5` | 17.400 | 261.9 ms | 178.8 ms | 556.8 ms | 22.8% | Zero MariaDB queries; demonstrates PHP-FPM / Redis memory serialization ceiling. |

<details>
<summary>Cache Sweep Detailed Statistics Breakdown (p50, p90, p95, p99, Std Dev)</summary>

| Configuration | Requests/sec | Average | Fastest | Slowest | Median (`p50`) | 90th (`p90`) | 95th (`p95`) | 99th (`p99`) | Jitter (`std_dev`) |
| :--- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1. Cold Baseline (`TTL=0`) | 8.358 | 565.1 ms | 475.7 ms | 757.1 ms | 556.8 ms | 613.4 ms | 645.9 ms | 741.9 ms | 45.4 ms |
| 2. Contributor Only (`C=5, D=0`) | 14.020 | 291.8 ms | 153.5 ms | 673.4 ms | 284.4 ms | 401.5 ms | 566.2 ms | 633.0 ms | 108.2 ms |
| 3. Production Baseline (`D=5, C=5`) | 17.221 | 245.3 ms | 157.6 ms | 511.9 ms | 239.1 ms | 289.2 ms | 349.6 ms | 501.5 ms | 57.4 ms |
| 4. Extended Call Center (`TTL=30`) | 17.196 | 257.8 ms | 171.2 ms | 562.2 ms | 242.2 ms | 303.5 ms | 311.8 ms | 518.0 ms | 68.0 ms |
| 5. Memory Cache Ceiling | 17.400 | 261.9 ms | 178.8 ms | 556.8 ms | 241.6 ms | 275.9 ms | 519.7 ms | 556.0 ms | 84.5 ms |

</details>

#### Network Interface Sanity Check Comparison (Public IPv4 vs Private IPv4 vs Public IPv6)

As a sanity check, baseline `100 x 5` tests were executed across all three available network interfaces on Server 1:

| Network Interface | Target Endpoint | Requests/sec | Average Latency | Fastest (Min) | Slowest (Max) | Median (`p50`) | 95th (`p95`) | Notes / Observations |
| :--- | :--- | ---: | ---: | ---: | ---: | ---: | ---: | :--- |
| **Public IPv4** | `http://x.x.x.200/...` | 15.605 | 288.6 ms | 166.5 ms | 489.5 ms | 278.0 ms | 422.3 ms | Standard internet routing via public interface. |
| **Private IPv4** | `http://10.124.0.2/...` | 18.440 | 233.0 ms | 168.4 ms | 492.3 ms | 220.4 ms | 284.3 ms | Datacenter private network; ~18% lower average latency. |
| **Public IPv6** | `http://[2604:a880:...9b49:0]/...` | 17.548 | 254.6 ms | 186.8 ms | 490.3 ms | 242.2 ms | 406.0 ms | Native dual-stack IPv6 routing with minimal packet processing overhead. |

> [!TIP]
> **Network Interface Observation**:
> Private IPv4 delivered approximately 18% lower average latency and higher throughput compared to the public IPv4 interface by bypassing external cloud provider routing hops and firewall state tables. Public IPv6 performed with near-parity to private IPv4, demonstrating efficient native IPv6 stack performance in Debian 13.

Host telemetry during these runs: Linux 6.12 amd64, 1 vCPU, 967 MiB RAM (340 MiB available), swap utilization remained steady at 121 MiB with zero OOM events.

Artifacts: `storage/app/load-tests/capacity/datacenter-1c-1g-20260924T1753Z/` and `storage/app/load-tests/cache-sweep-20260924-180008/`.

---

#### Historical Datacenter Reference Runs (July – August 2026 Archive)

The following historical benchmarks were recorded during initial application development on earlier Debian/PHP revisions and are retained here for architectural comparison.

Individual runs are designated by repetition (`r1`, `r2`, `r3`). Below is the consolidated summary comparing the Debian default dynamic pool (`pm = dynamic`, maximum 5 workers) against the tuned static pool (`pm = static`, 6 workers):

| Configuration Profile | Tier | Repetitions | Median Req/Sec | Range (Min – Max) | Median Slowest | Notes / Observations |
| --- | --- | --- | ---: | ---: | ---: | --- |
| Debian default (`pm = dynamic`, max 5) | `100 x 5` | 3 (r1–r3) | 18.913 | 17.057 – 19.179 | 304 ms | Stock dynamic pool; fast responses under low concurrency. |
| Static 6 (`pm = static`, 6 workers) | `100 x 5` | 3 (r1–r3) | 20.285 | 19.867 – 20.321 | 329 ms | +7.3% throughput gain with all workers pre-spawned. |
| Debian default (`pm = dynamic`, max 5) | `500 x 25` | 3 (r1–r3) | 23.519 | 21.582 – 23.565 | 1,259 ms | Saturated worker pool (`pm.max_children = 5` warnings logged). |
| Static 6 (`pm = static`, 6 workers) | `500 x 25` | 3 (r1–r3) | 24.040 | 24.024 – 24.233 | 1,096 ms | +2.2% throughput; tail latency reduced by 163 ms. |
| Debian default (`pm = dynamic`, max 5) | `1,000 x 25` | 1 (r1) | 22.086 | — | 1,921 ms | Heavy queueing; tail latency spiked near 2 seconds. |
| Static 6 (`pm = static`, 6 workers) | `1,000 x 25` | 1 (r1) | 23.034 | — | 1,280 ms | Sustained burst ceiling; tail latency cut by 641 ms. |

<details>
<summary>View Individual Repetition Measurements (r1–r3)</summary>

| Profile | Tier | Requests/sec | Slowest |
| --- | --- | ---: | ---: |
| Debian default r1 | `100 x 5` | 17.057 | 350 ms |
| Debian default r2 | `100 x 5` | 19.179 | 304 ms |
| Debian default r3 | `100 x 5` | 18.913 | 303 ms |
| Static 6 r1 | `100 x 5` | 20.285 | 329 ms |
| Static 6 r2 | `100 x 5` | 20.321 | 349 ms |
| Static 6 r3 | `100 x 5` | 19.867 | 316 ms |
| Debian default r1 | `500 x 25` | 23.519 | 1,259 ms |
| Debian default r2 | `500 x 25` | 21.582 | 1,352 ms |
| Debian default r3 | `500 x 25` | 23.565 | 1,157 ms |
| Static 6 r1 | `500 x 25` | 24.233 | 1,114 ms |
| Static 6 r2 | `500 x 25` | 24.040 | 1,080 ms |
| Static 6 r3 | `500 x 25` | 24.024 | 1,096 ms |
| Debian default r1 | `1,000 x 25` | 22.086 | 1,921 ms |
| Static 6 r1 | `1,000 x 25` | 23.034 | 1,280 ms |

</details>

All rows: WAN round-trip latency approximately 35 ms, swap stayed near 91 MiB, and PHP-FPM `pm.max_children` saturation warnings were observed on the `500 x 25` and `1,000 x 25` default runs. Average and fastest latency were not captured by the harness version used then.

**PHP-FPM experiment (1 vCPU / 1 GiB).** Three static pool sizes were compared against the Debian default (`dynamic`, maximum 5). Every entry is the median of three authenticated `mixed` runs; all runs returned zero failures:

| PHP-FPM profile | `100 x 5` req/sec | `500 x 25` req/sec | `1,000 x 25` req/sec | `500 x 25` slowest | Result |
| --- | ---: | ---: | ---: | ---: | --- |
| Debian default: dynamic, max 5 | 18.913 | 23.519 | 22.086 | 1,259 ms | Recommended for light or moderate use |
| Static 4 | 18.974 | 22.368 | Not run | 1,232 ms | Rejected; burst result was worse |
| Static 5 | 20.403 | 23.048 | Not run | 1,225 ms | Rejected; no burst improvement |
| Static 6 | 20.285 | 24.040 | 23.034 | 1,096 ms | Optional high-load profile |

Post-test available RAM was 473–479 MiB for the default profile and 440 MiB for static 6; swap stayed near 91 MiB and no OOM event occurred. In `static` mode all workers are already available for XML handler bursts, so `pm.start_servers`, `pm.min_spare_servers`, and `pm.max_spare_servers` are ignored. Static 6 improved this validation run's throughput by about 4.3% and its slowest response from 1,921 ms to 1,280 ms versus the default profile. This is not a universal production recommendation: raising workers can also increase CPU contention if each request is expensive. Record before/after results and revert if throughput or the slowest response does not improve. See `INSTALL.md` for small/standard/larger server sizing guidance.

**Verification after the July 17 application update.** Three `100 x 5` runs and three `500 x 25` runs completed with zero failures: average latency 166–214 ms (fastest 112–114 ms, slowest 273–452 ms) at `100 x 5`, and average 650–757 ms (fastest 142–173 ms, slowest 1,078–1,545 ms) at `500 x 25`. These averages come from stored per-request latencies.

**Public VPS revalidation (August 31, 2026).** The minimum-hardware VPS was re-tested against the real Nginx/PHP-FPM endpoint:

| Run | Result | Completed | Throughput | Average latency |
| --- | --- | --- | --- | --- |
| `100 x 5` (small office smoke) | Thresholds passed | 100/100, 0 failures | 16.1 req/sec | 278 ms |
| `500 x 25` (moderate burst) | Completed with 0 failures; tail latency at the ceiling | 500/500, 0 failures | 14.2 req/sec | 1,014 ms |

Finding: the 1-vCPU/1-GB VPS verifies baseline production capacity. The `500 x 25` tail latency reflects single-vCPU saturation with the default Debian dynamic FPM pool; larger deployments should use 2+ vCPU and static FPM worker tuning (`pm = static`, `pm.max_children = 12`). Fastest and slowest values were not captured for this run.

---

### Environment B2: Cloud Datacenter VPS In-Place Memory Resize (1 vCPU / 2 GiB RAM) — Stage 3

The same VPS was resized in place to 1 vCPU and 1973 MiB RAM, rebooted, and re-run with the same static-6 profile, seed data, and WAN path.

Individual runs are designated by repetition (`r1`, `r2`, `r3`). Below is the consolidated summary:

| Tier | Repetitions | Median Req/Sec | Average Latency | Fastest Latency | Slowest Latency | Notes / Observations |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| `100 x 5` | 3 (r1–r3) | 13.403 | 227 ms | 128 ms | 447 ms | Slower than 1c/1g baseline due to WAN jitter and CPU scheduling. |
| `500 x 25` | 4 (r1–r4) | 18.445 | 1,172 ms | 135 ms | 1,671 ms | CPU-bound at 1 vCPU; extra RAM did not relieve burst queueing. |
| `1,000 x 25` | 1 (r1) | 16.821 | 1,332 ms | 259 ms | 1,966 ms | Confirms single-core CPU saturation during heavy concurrency. |

<details>
<summary>View Individual Repetition Measurements (r1–r4)</summary>

| Run | Requests/sec | Average | Fastest | Slowest |
| --- | ---: | ---: | ---: | ---: |
| `100 x 5` r1 | 13.567 | 219 ms | 114 ms | 543 ms |
| `100 x 5` r2 | 13.403 | 227 ms | 129 ms | 413 ms |
| `100 x 5` r3 | 12.582 | 244 ms | 128 ms | 447 ms |
| `500 x 25` r1 | 19.770 | 1,078 ms | 133 ms | 1,633 ms |
| `500 x 25` r2 | 18.625 | 1,145 ms | 136 ms | 1,708 ms |
| `500 x 25` r3 | 15.882 | 1,384 ms | 246 ms | 1,992 ms |
| `500 x 25` warm r4 | 18.264 | 1,198 ms | 303 ms | 1,609 ms |
| `1,000 x 25` r1 | 16.821 | 1,332 ms | 259 ms | 1,966 ms |

</details>

All runs returned zero failures and zero slow queries. The server had about 1.38 GiB available memory after testing, used no swap, and logged no new PHP-FPM `pm.max_children` warnings. Despite the added memory, the burst tiers were slower than the 1-GB static-6 baseline. That is evidence the one-vCPU profile is CPU/scheduling-bound for these bursts; the next useful comparison is the 2-vCPU resize. WAN round-trip latency was approximately 35 ms.

---

### Environment B3: Cloud Datacenter VPS Dual-Core Compute Resize (2 vCPU / 2 GiB RAM) — Stage 4

Same VPS after a second in-place resize to 2 vCPU and 1973 MiB RAM, same static-6 profile, same WAN path, same WireGuard tunnel, same 2 GiB swap.

Individual runs are designated by repetition (`r1`, `r2`, `r3`). Below is the consolidated summary:

| Tier | Repetitions | Median Req/Sec | Average Latency | Fastest Latency | Slowest Latency | Notes / Observations |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| `100 x 5` | 3 (r1–r3) | 17.864 | 133 ms | 107 ms | 186 ms | Sub-200ms tail latency; smooth dual-core execution. |
| `500 x 25` | 3 (r1–r3) | 29.831 | 165 ms | 106 ms | 365 ms | **+61.7% throughput gain** over 1c/2g; tail latency dropped from ~1,700 ms to 365 ms. |
| `1,000 x 25` | 1 (r1) | 29.673 | 170 ms | 108 ms | 410 ms | Stable sustained burst ceiling; dual cores prevent worker starvation. |

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

### Planned Cloud Datacenter Profiles (Environments C1 & C2: Dedicated 8 GiB) — Stages 5–6

The dedicated-CPU 2 vCPU / 8 GiB and 4 vCPU / 8 GiB profiles have not been measured yet. When a server is provisioned, repeat the same procedure:

1. Seed with the same `load-test-beta` synthetic tenant and extension count.
2. Run the `25 x 1` warm-up, then `100 x 5`, `500 x 10` (safety step for small profiles), `500 x 25`, and optionally `1,000 x 25`.
3. Record the median of at least three runs per tier plus the server specs, provider CPU class, disk type, region, and pre-run round-trip latency.
4. Stop escalating if XML handler latency spikes, responses return non-2xx, MariaDB shows lock/connection pressure, Laravel workers saturate, or the generator itself saturates.

---

## Results: Server-To-Server Call Tests (Phase 2)

Server-to-server runs always use two machines: the PBX under test and a SIPp load generator on the same network path. The historical VirtualBox pair used the WSL2 host at `192.168.1.65` as the generator. The historical datacenter runs used the local test server through WireGuard until the planned two-datacenter-server topology is in place. The tables below present the September 23, 2026 VirtualBox end-to-end verification (all 15/15 SIPp scenarios verified, covering basic calls, live media RTP echo, and extended telephony parity), followed by the historical July–August 2026 reference runs.

Call-setup latency is measured from the caller's first `INVITE` to the destination's `200 OK`. "Achieved calls/sec" compares the first and last successful answer, so it reveals when answers fall behind the attempted rate. Test tables below use the original column names from the runs.

---

### Environments A1 + A2: Local VirtualBox Test Pair — Stage 1

The September 23, 2026 test series executed the full 15-scenario telephony validation suite between the isolated VirtualBox PBX test VM A1 (`192.168.1.71`) and the orchestrator / SIPp generator VM A2 (`192.168.1.76`) running on the same local Windows 11 host. Neither VM functions as an office PBX or live server; they exist purely as an isolated, reproducible virtualization test bench. All 15/15 scenarios passed with zero failed calls.

**End-to-end correctness, media flows, and extended parity checks (September 23, 2026)**

| Test | Result | Important observation |
| --- | --- | --- |
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
| Call forward calls (`2001` -> `2000`) | Passed | 3 successful, 0 failed. Dialplan routes via `loopback/2000/${context}` to prevent `CHAN_NOT_IMPLEMENTED`. |
| Time condition calls (`2401`) | Passed | 3 successful, 0 failed. Evaluated schedule rules dynamically and bridged call. |
| Follow me calls (`2002`) | Passed | 3 successful, 0 failed. Sequential ring list executed and completed cleanly. |
| Emergency calls (`911`) | Passed | 3 successful, 0 failed. Correctly bridged to emergency gateway UAS. |
| Call block rejection | Passed | 3 successful, 0 failed. Blocked caller ID pattern matched and rejected with `603 Decline`. |

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
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 1 | 30 | 30 | 0 | 1.009 | 593 ms | 470 ms | 774 ms | 6 | 7 / 12 | 29% | Passed |
| 2 | 60 | 60 | 0 | 2.012 | 680 ms | 573 ms | 892 ms | 12 | 12 / 22 | 54% | Passed |
| 4 | 120 | 120 | 0 | 4.018 | 848 ms | 480 ms | 1,312 ms | 25 | 24 / 45 | 85% | Passed |
| 5, r1 | 150 | 150 | 0 | 4.928 | 1,799 ms | 749 ms | 3,760 ms | 40 | 35 / 64 | 97% | Passed |
| 5, r2 | 150 | 150 | 0 | 4.994 | 1,256 ms | 647 ms | 2,132 ms | 35 | 32 / 61 | 95% | Passed |
| 5, r3 | 150 | 150 | 0 | 4.886 | 1,484 ms | 682 ms | 3,671 ms | 37 | 34 / 65 | 98% | Passed |
| 6 | 180 | 180 | 0 | 5.506 | 3,085 ms | 784 ms | 6,453 ms | 55 | 43 / 72 | 98% | Passed, but overloaded |
| 7 | 210 | 209 | 1 | 5.498 | 5,679 ms | 738 ms | 9,220 ms | 70 | 53 / 83 | 98% | Failed |
| 8 | 240 | 180 | 60 | 4.641 | 6,853 ms | 918 ms | 9,998 ms | 80 | 56 / 81 | 98% | Failed |

The repeatable 5-CPS result is 150/150 successful calls in all three runs, with a middle achieved rate of 4.928 calls/sec and middle setup figures of 1,484 ms average, 682 ms fastest, and 3,671 ms slowest. Five attempted calls per second is the highest repeatably verified tier that kept pace without failures, but it is a measured limit, not an everyday operating target: CPU was 95–98% busy. Four calls per second is the more sensible planning limit on this VM because it retained CPU headroom and kept average setup below one second.

At 6 CPS every call still completed, but the achieved answer rate flattened to 5.506 CPS and average setup exceeded three seconds. At 7 CPS calls began to fail, and at 8 CPS 60 of 240 calls failed. This shows a real saturation boundary near 5–6 end-to-end call setups per second rather than a SIPp concurrency cap. The run exercised full SIP signaling setup and teardown but did not generate continuous RTP audio; media capacity and audio quality require a separate RTP-enabled concurrent-call test. Artifacts: `storage/app/load-tests/capacity/virtualbox-4c-4g-cps-20260718T011540Z/`.

#### Retest at Sessions-Per-Second = 60

The VirtualBox PBX was retested from the WSL generator after raising FreeSWITCH to `sessions-per-second=60`. These runs are comparison results, not production capacity numbers, and the VM was carrying background load:

| Offered calls/sec | Attempted | Achieved calls/sec | Successful | Failed | Average setup | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 5 | 100 | 4.629 | 100 | 0 | 703 ms | Passed |
| 8 | 160 | 5.574 | 160 | 0 | 4,253 ms | Passed, but queued |
| 10, r1 | 200 | 5.588 | 196 | 4 | 6,276 ms | Failed |
| 10, r2 | 200 | 5.184 | 123 | 77 | 6,742 ms | Failed |
| 12 | 240 | 8.299 attempted/created | 32 | 208 | 5,587 ms | Failed heavily |

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

### Environments B1 + D: Cloud Datacenter Server & Remote Generator Pair — Stage 2

**Correctness (1 vCPU / 1 GiB, July 17, 2026).** The NATed load generator reached the public PBX through WireGuard while the Sofia profile stayed bound to the public IP:

| Test | Result | Important observation |
| --- | --- | --- |
| Register 20 users | Passed | Synthetic accounts used the forced PBX realm; all 20 registrations succeeded. |
| Extension calls | Passed | 10 authenticated extension calls completed through the tunnel. |
| Outbound-route calls | Passed | 5 calls reached the SIPp UAS at `10.77.0.2:5088` after PBX egress SNAT was enabled. |
| Recording media to `*732` | Failed | The dialplan ran `record_session` and immediately ran `hangup`; SIPp received `480` and FreeSWITCH discarded the empty recording. |
| MOH media to `load_test_moh` | Passed | The call answered, stayed active through the 10-second window, and ended normally. |
| Announcement media to `load_test_announcement` | Passed | The call answered and FreeSWITCH sent BYE after playback. |

The basic-run artifacts are under `capacity/vps-1c-1g-20260717T212713Z/sipp-basic-wg-nat`; targeted MOH and announcement artifacts are under the sibling `sipp-media-targeted` directory. SIPp RTP echo was enabled, `tcpdump` was not installed on the generator, and FreeSWITCH reported zero active calls after testing.

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

### Planned Cloud Datacenter Pairs (Environments C1 & C2 with D) — Stages 4–5

The dedicated-CPU 2 vCPU / 8 GiB and 4 vCPU / 8 GiB pairs have not been measured yet. When both servers are provisioned:

1. Confirm correctness first: basic runner plus `MEDIA_FLOW=1` and `EXTENDED=1` with the new test data.
2. Re-run the capacity ladder at increasing offered rates and record attempted calls, achieved calls/sec, success/failure counts, average / fastest / slowest setup time, peak concurrency, peak CPU, and stuck-call checks after teardown.
3. Repeat the FreeSWITCH log-level and PHP-FPM experiments on the pair so the tuning guidance reflects the new hardware.
4. Run the additional campaign tests listed in Test 2: the concurrent-call ladder, the RTP-enabled media capacity test, media-flow re-validation, and the recording regression check.
