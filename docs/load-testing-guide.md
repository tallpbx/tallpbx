# SIP Load Testing & Performance Guide

Operational manual for benchmarking, stress testing, and validating telephony performance on TallPBX.

> [!NOTE]
> For empirical benchmark measurements, latency statistics, and hardware sizing recommendations across tested datacenter VPS tiers, see the companion **[PBX SIP Load Testing & Benchmark Results](load-testing-results.md)**.

## Contents

- [Best Practices for Reliable SIP Load Testing](#best-practices-for-reliable-sip-load-testing)
- [The Two Core Tests](#the-two-core-tests)
  - [What Each Test Measures](#what-each-test-measures)
  - [Test 1: Dialplan XML Routing (HTTP)](#test-1-dialplan-xml-routing-http)
  - [Test 2: End-to-End Live Calls (SIP & RTP)](#test-2-end-to-end-live-calls-sip--rtp)
- [Quick Start: Standard Test Workflow](#quick-start-standard-test-workflow)
- [Interpreting Test Metrics](#interpreting-test-metrics)
- [Lab Setup & Network Topology](#lab-setup--network-topology)
  - [Two-Host Architecture](#two-host-architecture)
  - [Network Reachability & WireGuard (NAT Traversal)](#network-reachability--wireguard-nat-traversal)
  - [Required PBX Runtime State](#required-pbx-runtime-state)
  - [Provisioning a Remote Test Server](#provisioning-a-remote-test-server)
- [Automated Refresh Harness (Matrix Runner)](#automated-refresh-harness-matrix-runner)
- [Seed Data Generation](#seed-data-generation)
- [Executing Test 1: Dynamic Dialplan XML Throughput](#executing-test-1-dynamic-dialplan-xml-throughput)
  - [Running the Test](#running-the-test)
  - [Relevant .env Configuration](#relevant-env-configuration)
  - [Cache Sweep & Hit Rate Benchmarking](#cache-sweep--hit-rate-benchmarking)
- [Executing Test 2: End-to-End SIP Calls](#executing-test-2-end-to-end-sip-calls)
  - [Running the Automated Suite (`pbx-sipp-validate.sh`)](#running-the-automated-suite-pbx-sipp-validatesh)
  - [Runner Configuration Variables](#runner-configuration-variables)
  - [Media-Flow Scenarios](#media-flow-scenarios)
  - [Extended Parity Scenarios](#extended-parity-scenarios)
- [Preflight Verification & Recovery Runbook](#preflight-verification--recovery-runbook)
- [Bottleneck Analysis & Troubleshooting](#bottleneck-analysis--troubleshooting)
  - [Bottleneck Detection Guide](#bottleneck-detection-guide)
  - [Common Failures & Remediation](#common-failures--remediation)
- [References](#references)

---

## Best Practices for Reliable SIP Load Testing

Follow these operational guidelines to ensure reproducible benchmarks and avoid common testing pitfalls:

1. **Obfuscate Public IP Addresses**: In all test reports, public documentation, and committed logs, obfuscate public IP addresses to show only the last octet (e.g. `x.x.x.200` or `...YYY`).
2. **Avoid Special Characters in Remote Passwords**: When seeding a remote PBX over SSH (`tpbx pbx:load-test:seed` or `tallpbx`, which wrap native `artisan pbx:load-test:seed`), avoid unescaped shell metacharacters (such as `!`) in the password argument (e.g., use alphanumeric `LoadTest1234`). In this guide, `tpbx` is used. Unescaped exclamation points are stripped by Bash history expansion, causing credential mismatches that result in `403 Forbidden` on SIP REGISTER.
3. **Synchronize `sipp-users.csv` to the Generator**: The seeding command writes `storage/app/load-tests/sipp-users.csv` on the PBX filesystem. `storage/app/load-tests/` is dedicated scratch space on the PBX for individual Artisan commands and single-run CSV/XML outputs (git-ignored). Before running `load-testing/scripts/pbx-sipp-validate.sh` with `SKIP_SEED=1` on the load generator, copy the CSV to the generator host:
   ```bash
   scp root@<PBX_HOST>:/var/www/tallpbx/storage/app/load-tests/sipp-users.csv storage/app/load-tests/sipp-users.csv
   ```
   *(Note: Full multi-tier matrix benchmark series archive their raw per-cell outputs to `load-testing/results/<series>/` and commit consolidated cross-series data to `load-testing/results/consolidated/`).*
4. **Whitelist PBX on Generator Firewall (`nftables`) If Running a Firewall**: When testing outbound gateway routing and media playback, FreeSWITCH bridges calls to the generator host (UAS ports UDP 5088, 5090, RTP 6000). Because these are unsolicited inbound UDP packets, stateful default-drop firewalls on the generator will block them. If the load generator host runs TallPBX or has an active default-drop `nftables` firewall, whitelist the PBX IP on the generator before testing:
   ```bash
   sudo nft add element inet tallpbx_filter whitelist_ips { <PBX_IP> }
   ```
   *(Note: On a clean, dedicated load generator VM with no firewall or an open `INPUT ACCEPT` policy, this step is not required).*
5. **Use FreeSWITCH Loopback Channel for Internal Bridges**: FreeSWITCH XML dialplan bridges to internal destinations (such as call forwarding) must use `loopback/${destination}/${context}` rather than raw extension strings to avoid `CHAN_NOT_IMPLEMENTED` failures.
6. **Terminate Background UAS Listeners Between Runs**: In multi-scenario scripts, clean up background UAS processes before starting new listeners on ports 5066 and 5088 to prevent `errno 98 (Address already in use)` socket binding collisions.
7. **Allocate Dedicated Client Ports on Shared Hosts**: When the load generator host also runs FreeSWITCH, SIPp UAC scenarios must avoid ports 5060 (Sofia internal), 5080 (Sofia external), and 5088 (gateway UAS). Use local ports 5100+ (`EXTENDED_UAC_LOCAL_PORT=5100`).
8. **Tune PHP-FPM Worker Pools**: TallPBX configures `/etc/php/8.5/fpm/pool.d/www.conf` as a dynamic pool: `pm = dynamic` with `pm.start_servers = 1`, `pm.min_spare_servers = 1`, `pm.max_spare_servers = 2`, and `pm.max_children = 3` on 1 GB systems (`6` on 2 GB, `12` on 4 GB+), plus `pm.max_requests = 10000`. The 2026-11-08 worker sweep showed more workers lose calls at the failure boundary on minimal hardware (38 % of calls completed with 5 workers versus 97 % with 1) and that Debian's stock values churn at burst edges. **Measure the host you are tuning instead of assuming that rule holds everywhere**: the 2026-11-09 re-measurement on 2 vCPU / 2 GB found worker count second-order, with five shapes spread across 34–44 % at the 12 calls/sec boundary. Supported rates with the shipped default are 5 calls/sec on 1 vCPU and 10 calls/sec on 2 vCPU (see [`load-testing-results.md`](load-testing-results.md)).
9. **Set Long Registration Leases**: The SIP registration scenario (`load-testing/tools/sipp/register.xml`) must use a 1-hour lease (`Expires: 3600`) so registrations do not expire mid-test.

---

## The Two Core Tests

TallPBX evaluates performance across two distinct workload layers:

```
[Test 1: Dialplan XML (HTTP)]
Load Generator ---> Nginx / PHP-FPM ---> Laravel ---> MariaDB / Redis ---> XML Response

[Test 2: End-to-End SIP Calls]
SIPp Caller ---> FreeSWITCH (Sofia) ---> Laravel XML ---> FreeSWITCH Bridge ---> SIPp UAS (Answer)
```

### What Each Test Measures

| Dimension | Test 1: Dynamic Dialplan XML | Test 2: End-to-End Live Calls |
| :--- | :--- | :--- |
| **Primary Metric** | Completed XML responses per second (`req/sec`) | Successfully answered calls per second (`calls/sec`) |
| **Target Subsystems** | Nginx, PHP-FPM, Laravel, MariaDB, Redis | FreeSWITCH, Sofia SIP, ESL, RTP media, plus Laravel XML |
| **Tool** | `tpbx pbx:load-test:dialplan` | `load-testing/scripts/pbx-sipp-validate.sh` (SIPp) |
| **Key Question Answered** | How fast can Laravel generate call-routing instructions under concurrency? | How many real phone calls can the PBX connect, maintain, and tear down per second? |
| **What It Does Not Measure** | SIP signaling, codec negotiation, RTP audio quality, or FreeSWITCH channel limits | Pure database/routing speed in isolation |

### Test 1: Dialplan XML Routing (HTTP)

FreeSWITCH requests dynamic routing XML through `mod_xml_curl` on every inbound and outbound call. Test 1 simulates this traffic directly over HTTP (`/api/v1/xml-handler`), intentionally bypassing SIP signaling to measure database query efficiency, PHP-FPM concurrency, and Redis caching speed.

### Test 2: End-to-End Live Calls (SIP & RTP)

Exercises complete SIP call setup across two hosts: endpoint registration, SIP digest authentication, dynamic XML lookups through FreeSWITCH, bridging, two-way RTP audio streams, and clean connection teardown.

---

## Quick Start: Standard Test Workflow

For routine benchmarking, run tests in this logical sequence:

1. **Verify Runtime State**: Ensure FreeSWITCH, Nginx, MariaDB, and Redis are running.
2. **Seed Synthetic PBX Data**: Create a test tenant, extensions, routes, and SIPp user credentials.
3. **Execute Test 1 (XML Throughput Baseline)**: Verify application routing speed and cache behavior.
4. **Execute Test 2 (Basic SIPp Call Flow)**: Verify registration, extension-to-extension calls, and outbound routing.
5. **Execute Test 2 (Media Flow)**: Verify call recording (`*732`), music-on-hold, and IVR announcements.
6. **Execute Test 2 (Extended Parity)**: Verify ring groups, voicemail, conferences, call forwards, and time conditions.
7. **Review Results**: Check `summary.md` and log files in PBX scratch storage (`storage/app/load-tests/`). (For full automated matrix series benchmarks, results are stored in `load-testing/results/<series>/` and consolidated comparison datasets in `load-testing/results/consolidated/`).

### Quick Commands

```bash
# 1. Seed 100 test extensions on the PBX (use PBX IP or configured SIP realm per Decision D15)
tpbx pbx:load-test:seed \
  --tenant=load-test-beta --domain=<PBX_IP> \
  --extensions=100 --start=2000 --password='LoadTest1234' --reset

# 2. Run XML routing throughput test (100 requests, 5 concurrency)
tpbx pbx:load-test:dialplan \
  --tenant=load-test-beta --url=http://127.0.0.1/api/v1/xml-handler \
  --scenario=mixed --section=dialplan --requests=100 --concurrency=5 \
  --token="$FS_XML_HANDLER_TOKEN"

# 2b. Directory lookups or a realistic call mix, with app-side timing
#     (requires FS_XML_HANDLER_TIMING_HEADERS=true on the PBX)
tpbx pbx:load-test:dialplan \
  --tenant=load-test-beta --url=http://127.0.0.1/api/v1/xml-handler \
  --section=mixed --scenario=internal --requests=300 --concurrency=5 \
  --timing --token="$FS_XML_HANDLER_TOKEN"

# 3. Run SIPp end-to-end validation suite from the load generator
PBX_HOST=<PBX_IP> LOAD_GENERATOR_IP=<GEN_IP> load-testing/scripts/pbx-sipp-validate.sh
```

---

## Interpreting Test Metrics

When evaluating benchmark output:

- **Requests per Second (`req/sec`)**: Completed XML routing answers delivered per second. Reflects web/PHP/database processing capacity.
- **Achieved Calls per Second (`calls/sec`)**: Measured rate of successfully answered calls (`200 OK`) between the first and last answer. If achieved CPS falls well below the offered rate, calls are queueing.
- **Median Latency (`p50`)**: The middle response time. Accurately reveals what a typical caller experiences, unaffected by rare one-off startup spikes.
- **Tail Latency (`p95` & `p99`)**: Identifies how the slowest 5% and 1% of calls behaved under peak concurrency, exposing buffer stalls or worker starvation.
- **Fastest (Min) & Slowest (Max)**: The absolute best-case (pre-warmed cache hit) and worst-case (cold miss or queue wait) latencies recorded during the run.

### App-Side Metrics

`--timing` asks the XML handler for two opt-in response headers and records them in the report's additive `timing` block:

- **`handler_ms`** — time inside the XML handler pipeline as measured by the timing middleware. Excludes framework boot.
- **`request_ms`** — time since `LARAVEL_START`, including framework boot and route resolution. Use this number when judging whether caching would remove meaningful work.
- **`db_queries`** — average and maximum database queries per lookup.
- **`by_section`** — per-section counts and `request_ms` p50/p95 (dialplan vs. directory).

Timing headers are opt-in twice: set `FS_XML_HANDLER_TIMING_HEADERS=true` on the PBX **and** pass `--timing` (which sends `X-TallPBX-Timing: 1`). Never enable the flag on production appliances.

`--section` selects which XML section the run exercises: `dialplan` (default), `directory` (REGISTER/INVITE auth lookups), or `mixed` (two directory lookups per dialplan lookup, mirroring a real call flow). The report records `target.section` and `target.section_counts`.

> [!TIP]
> For complete statistical definitions, formulas, and real-world PBX examples, consult the centralized **[Metrics & Statistics Reference in docs/load-testing-results.md](load-testing-results.md#performance-metrics--statistics)**.

---

## Lab Setup & Network Topology

### Two-Host Architecture

For valid capacity measurements, run the load generator from a **separate host or VM** in the same network or datacenter:

| Host Role | Responsibilities | Default Ports |
| :--- | :--- | :--- |
| **PBX Target** | TallPBX, FreeSWITCH, Nginx, PHP-FPM, MariaDB, Redis | `5060/udp` (SIP), `16384-32768/udp` (RTP), `80/443` (HTTP) |
| **Load Generator** | SIPp scenarios, UAC caller endpoints, UAS answer listeners | `5066` (UAS auto-answer), `5088` (Gateway UAS), `6000+` (RTP echo) |

Running SIPp on the PBX host itself is acceptable for initial functional checks, but invalidates capacity benchmarks because the generator's CPU and memory compete directly with FreeSWITCH and PHP-FPM.

### Network Reachability & WireGuard (NAT Traversal)

The SIPp test suite requires bidirectional reachability: SIPp calls FreeSWITCH, but FreeSWITCH also opens new SIP dialogs toward the registered SIPp endpoint and returns RTP media.

- **Direct Network (Routable IP / Same Datacenter)**: Use host IP addresses directly. Ensure the PBX IP is whitelisted on the generator's firewall.
- **NATed Environments**: If either host is behind NAT or a firewall where inbound UDP ports cannot be opened, establish a WireGuard tunnel:

```ini
# PBX /etc/wireguard/wg0.conf
[Interface]
Address = 10.77.0.1/24
ListenPort = 51820
PrivateKey = <PBX_PRIVATE_KEY>

[Peer]
PublicKey = <LOAD_GENERATOR_PUBLIC_KEY>
AllowedIPs = 10.77.0.2/32

# Load Generator /etc/wireguard/wg0.conf
[Interface]
Address = 10.77.0.2/24
PrivateKey = <LOAD_GENERATOR_PRIVATE_KEY>

[Peer]
PublicKey = <PBX_PUBLIC_KEY>
AllowedIPs = 10.77.0.1/32
Endpoint = <PBX_PUBLIC_IP>:51820
PersistentKeepalive = 25
```

### Required PBX Runtime State

Before initiating load tests, ensure all core FreeSWITCH runtime modules are active:

```bash
cd /var/www/tallpbx
bash scripts/resources/freeswitch.sh --configure-only
```

Verify that Sofia SIP profiles are `RUNNING` and UDP port 5060 is listening:

```bash
fs_cli -x 'sofia status'
ss -lunp | grep -E ':5060|:5080'
```

### Provisioning a Remote Test Server

When a test requires real datacenter behavior, provision a **fresh Debian 13
(amd64)** host sized to the tier under test (for example 1 shared vCPU /
1 GB RAM / 2 GB swap for the minimal tier) and install TallPBX exactly the way
an end user would:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/<branch>/scripts/bootstrap.sh | bash
```

- **Run the install interactively from a terminal.** Do not pre-seed installer
  environment variables or `/etc/default/tallpbx`; answer the prompts in the
  order they appear (Clean data, Production, Random database password,
  FreeSWITCH packages, SignalWire PAT, English prompts, Setup admin now). The
  interactive path is what validates the real end-user experience.
- **Enter the SignalWire PAT at the interactive prompt.** Never pre-install or
  pre-write the token to a setup file as a shortcut. If the token must not
  remain in the installer state file afterward, remove the `SWITCH_TOKEN`
  entry once installation completes.
- **Keep host-specific details out of documentation and reports.** Obfuscate
  public IP addresses to their last octet, and never record SSH key names or
  paths, administrator credentials, or tokens in docs, plans, or test reports.
- **Reuse the existing test host for feature comparisons.** Measuring the same
  features twice on different hosts produces numbers that cannot be compared.
  A fresh host is only needed when the point of the exercise is validating the
  installer itself.

---

## Automated Refresh Harness (Matrix Runner)

For automated capacity refreshes across multiple hardware tiers, XML serving variants, and call ladders, use the dedicated matrix harness in [`load-testing/`](../load-testing/README.md).

The matrix runner orchestrates remote test execution, applies configuration switches with readback assertions (`load-testing/scripts/lib/switch.sh`), captures machine-readable host manifests (`tpbx pbx:load-test:manifest`), and restores baseline configuration via teardown traps:

```bash
# List all matrix combinations or dry-run a specific cell
bash load-testing/scripts/load-test-matrix.sh --list
bash load-testing/scripts/load-test-matrix.sh --dry-run --tier=h1-1c1g --variant=v-octane --phase=xml

# Execute a complete benchmark series against a remote appliance (uses ambient SSH config)
PBX_HOST=<PBX_IP> bash load-testing/scripts/load-test-matrix.sh --tier=h1-1c1g --repeats=3
```

Raw per-cell artifacts land under `load-testing/results/<series>/<tier>/<variant>/` (git-ignored), while permanent cross-series datasets are consolidated in `load-testing/results/consolidated/`. For the complete execution procedure, host configuration reference, and script documentation, see **[`load-testing/README.md`](../load-testing/README.md)**.

---

## Seed Data Generation

The `pbx:load-test:seed` Artisan command idempotently generates test accounts, dialplans, routes, and SIP credentials:

### 1. Seeding for Test 1 (XML Throughput)

```bash
tpbx pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain=<PBX_IP> \
  --extensions=100 \
  --start=2000 \
  --password='LoadTest1234' \
  --reset
```

> [!NOTE]
> **SIP Realm Selection (Decision D15)**: Always pass `--domain=<PBX_IP>` (or `<DOMAIN>`), matching the PBX appliance's configured `FS_DEFAULT_SIP_REALM`. FreeSWITCH resolves user authentication under the realm configured on its Sofia profile. If seeded with an arbitrary domain (such as `load.test.local`), SIP registrations will fail with `403 Forbidden` (`Can't find user`).

### 2. Seeding for Test 2 (SIPp Live Calls & Media)

```bash
tpbx pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain=<PBX_IP> \
  --extensions=20 \
  --start=2000 \
  --password='LoadTest1234' \
  --sipp-host=<GENERATOR_IP> \
  --sipp-port=5088 \
  --output=storage/app/load-tests/sipp-users.csv \
  --include-media-fixtures \
  --include-extended-fixtures \
  --reset
```

- `--output=storage/app/load-tests/sipp-users.csv`: Writes the endpoint credential CSV to PBX scratch space (`storage/app/load-tests/`, git-ignored). In automated multi-tier matrix runs, artifact management is handled by the matrix runner.
- `--include-media-fixtures`: Creates the callcenter queue `load_test_moh` (`local_stream://moh`) and IVR announcement `load_test_announcement`.
- `--include-extended-fixtures`: Creates ring group 2400, voicemail 2003, conference 2500, call forward 2001 &rarr; 2000, time condition 2401, follow-me 2002, emergency 911, and call blocking fixtures.

---

## Executing Test 1: Dynamic Dialplan XML Throughput

### Running the Test

Run against the real Nginx/PHP-FPM web endpoint using `tpbx pbx:load-test:dialplan`:

```bash
tpbx pbx:load-test:dialplan \
  --tenant=load-test-beta \
  --url=http://<PBX_HOST>/api/v1/xml-handler \
  --scenario=mixed \
  --requests=500 \
  --concurrency=25 \
  --token="$FS_XML_HANDLER_TOKEN" \
  --label="moderate-office-burst" \
  --max-failure-rate=0 \
  --max-average-ms=1000 \
  --report=storage/app/load-tests/dialplan-500x25.json
```

**Available Scenarios:**
- `mixed`: Realistic round-robin blend of internal extension, inbound DID, and outbound gateway lookups.
- `internal`: Internal extension-to-extension routing lookups only.
- `inbound`: Inbound public DID routing lookups only.
- `outbound`: Outbound prefix/gateway routing lookups only.
- `cache-hit`: Repeats one exact destination lookup to isolate pure Redis memory hit performance (0 database queries).

### Telemetry & Output Reports

The dialplan test command streams live progress, prints a summary table to the console, and (when `--report` is specified) writes a comprehensive JSON benchmark report to PBX scratch storage (e.g. `storage/app/load-tests/dialplan-500x25.json`, git-ignored). In automated multi-tier matrix runs, per-cell reports are archived under `load-testing/results/<series>/<tier>/<variant>/` and permanent comparisons are distilled into `load-testing/results/consolidated/`:
- **Throughput & Latency**: Measures requests per second, mean, min, max, median (`p50`), 95th percentile (`p95`), and 99th percentile (`p99`) response latencies.
- **HTTP Status Codes**: Tracks total counts of `200 OK`, `4xx` client errors, and `5xx` server/gateway errors.
- **System Environment**: Records PHP version, OPcache status, CPU core count, and system load averages (`1m`, `5m`, `15m`).
- **Redis Memory Telemetry**: Captures active `used_memory_human`, configured `maxmemory_human` (e.g. 256M cap), `maxmemory_policy` (`volatile-lru`), and `evicted_keys`. This verifies that Redis memory stays within configured bounds during sustained high-concurrency bursts and confirms whether key eviction occurred under load.

### Relevant .env Configuration

```dotenv
# Token authentication for FreeSWITCH XML handler
FS_XML_HANDLER_AUTH=true
FS_XML_HANDLER_TOKEN=generated-by-installer

# Production default (since v4.2.0): 60-second TTL across all telephony caches.
# Freshness is not TTL-bound: panel writes invalidate cached XML immediately
# via versioned cache keys (RoutingCacheVersion).
FS_XML_HANDLER_CACHE_TTL=60

# Master cache store (always use Redis in production)
FS_XML_HANDLER_CACHE_STORE=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# Safety throttle for new session creation (default allows ~30 calls/sec)
FS_SESSIONS_PER_SECOND=60
```

> [!NOTE]
> Control-panel updates (adding extensions, changing routes) automatically increment `RoutingCacheVersion`, invalidating active dialplan caches immediately across all tiers without requiring manual cache flushing. See `docs/architecture.md` §7.5 for the full layered-cache design.

### Cache Sweep & Hit Rate Benchmarking

To benchmark all 5 standard cache tiers on your host and measure exact Redis keyspace hit rates:

```bash
bash load-testing/scripts/run-cache-sweep.sh
```

The script runs a standardized 5-tier test (Cold baseline, Dialplan route cache off, Production baseline 60s, Memory hit ceiling, and Directory mixed) and automatically restores the exact `.env` TTL values captured before the sweep upon completion — including when interrupted mid-run.

**The five cache settings, in plain English:**

| Setting | What it caches, in everyday terms |
| :--- | :--- |
| `FS_XML_HANDLER_CACHE_TTL` | The master default. Any of the four settings below that is not set individually simply uses this value. |
| `FS_XML_HANDLER_DIALPLAN_CACHE_TTL` | The finished routing answer for one specific phone number, so a repeat call to the same number is served instantly. |
| `FS_XML_HANDLER_CONTRIBUTOR_CACHE_TTL` | The shared pieces that telephony modules (extensions, ring groups, IVR menus, time conditions, call blocks, outbound routes, …) each add to the routing plan. "Contributor" simply means "a module that contributes one piece of the dialplan." Caching these pieces means building any number's routing answer no longer asks the database once per module. |
| `FS_XML_HANDLER_DIRECTORY_CACHE_TTL` | "Phone book" lookups: which SIP user is calling, and their credentials. |
| `FS_XML_HANDLER_ACL_CACHE_TTL` | The list of trusted IP addresses. |

**What each cache profile sets** (numbers are seconds; `0` means the cache is off; the column names refer to the four individual cache settings above):

| Profile | Dialplan Route Cache TTL | Contributor Snippet Cache TTL | Directory User Cache TTL | ACL IP List Cache TTL | What this run tells you |
| :--- | :---: | :---: | :---: | :---: | :--- |
| **Cold baseline** | `0` | `0` | `60` | `60` | The floor: dialplan and contributor caches are off, so routing logic is rebuilt from the database on every call. Shows what the caches are saving you. |
| **Dialplan cache off** (snippets on) | `0` | `60` | `60` | `60` | Each number's final routing answer is rebuilt on every request, while the shared module snippets stay cached. |
| **Production baseline (60s)** | `60` | `60` | `60` | `60` | Normal production settings: full caching enabled, with instant panel invalidation via cache versioning (`RoutingCacheVersion`). |
| **Memory hit ceiling** | `60` | `60` | `60` | `60` | The best case: the same number is requested repeatedly, so every answer comes straight from Redis memory with zero database queries. |
| **Directory mixed** | `120` | `60` | `0` | `60` | Mixed directory and dialplan lookups with app-side timing headers (`--timing`), evaluating directory lookups with zero user cache alongside a 120s dialplan route cache. |

> [!NOTE]
> The XML-only benchmark only exercises the dialplan and contributor caches — it never asks the directory or ACL caches anything. That is why their values never change between profiles, and why testing them one at a time would show no difference.
>
> **Why run the "Dialplan cache off" test?** The server keeps two kinds of memory for call routing: the **finished answer** for each phone number (so a repeat call to the same number is instant) and the **shared building blocks** used to assemble any answer (the extension list, ring groups, IVR menus, and so on). This test switches off only the finished-answer memory: every request must re-assemble its answer, but the shared building blocks stay remembered. That is useful in three ways:
> - **It measures the safety net.** If the finished-answer memory is ever emptied or fails, this test shows exactly how the system would behave — and how much speed the shared building blocks preserve on their own (historically roughly half of the database savings).
> - **It splits the credit for the caching win.** Comparing this test against the all-off test and the normal test shows how much of the total speed-up each kind of memory provides.
> - **It pinpoints slowdowns.** If the normal test ever comes back slower than expected: if this test is *also* slow, the problem is in the shared building blocks; if this test is still fast, the problem is in the finished-answer memory.

## Executing Test 2: End-to-End SIP Calls

### Running the Automated Suite (`pbx-sipp-validate.sh`)

Execute the validation suite from the load generator host:

```bash
# Basic run: Registration, internal extension calls, and outbound routing
PBX_HOST=<PBX_IP> LOAD_GENERATOR_IP=<GEN_IP> load-testing/scripts/pbx-sipp-validate.sh

# Media flow run: Adds call recording (*732), music on hold, and IVR announcements
MEDIA_FLOW=1 PBX_HOST=<PBX_IP> LOAD_GENERATOR_IP=<GEN_IP> load-testing/scripts/pbx-sipp-validate.sh

# Extended parity run: Adds ring groups, voicemail, conferences, forwarding, etc.
EXTENDED=1 PBX_HOST=<PBX_IP> LOAD_GENERATOR_IP=<GEN_IP> load-testing/scripts/pbx-sipp-validate.sh
```

A successful run exits `0` and creates a detailed artifact directory in PBX scratch storage under `storage/app/load-tests/sipp-e2e-*` (git-ignored) containing `summary.md`, CSV timings, and error logs. (For multi-tier matrix series, raw artifacts are archived to `load-testing/results/<series>/` and long-term comparison datasets to `load-testing/results/consolidated/`).

### Runner Configuration Variables

| Environment Variable | Default | Purpose |
| :--- | :--- | :--- |
| `PBX_HOST` | *(Required)* | PBX IP address or hostname. |
| `PBX_PORT` | `5060` | PBX Sofia SIP listening port. |
| `LOAD_GENERATOR_IP` | *(Required)* | Generator IP address reachable by FreeSWITCH for SIP/RTP. |
| `FORCE_SEED=1` | `0` | Re-seeds test data and regenerates user CSVs before running. |
| `SKIP_SEED=1` | `0` | Reuses existing CSVs without invoking Artisan (used on remote generator hosts). |
| `MEDIA_FLOW=1` | `0` | Activates audio media tests (recording, MOH, announcements). |
| `EXTENDED=1` | `0` | Activates all 8 extended PBX feature validation scenarios. |
| `CALL_RATE` | `2` | Offered calls per second for extension tests. |
| `MAX_SIMULTANEOUS` | `5` | Maximum concurrent in-flight calls allowed (`-l`). |
| `CALLS` | `10` | Total call count executed. |

### Media-Flow Scenarios

| Scenario | Destination | Mechanism | Operational Verification |
| :--- | :--- | :--- | :--- |
| **Call Recording** | `*732` | `uac-media-server-hangup.xml` | FreeSWITCH answers, records active RTP audio to disk, and hangs up cleanly. |
| **Music on Hold** | `load_test_moh` | `uac-media-client-hangup.xml` | Connects to `mod_callcenter` queue backed by `local_stream://moh`. |
| **Announcement** | `load_test_announcement` | `uac-media-server-hangup.xml` | FreeSWITCH streams prompt audio and issues BYE after playback. |

### Extended Parity Scenarios

| Scenario | Destination | Scenario File | Feature Validated |
| :--- | :--- | :--- | :--- |
| **Ring Group** | `2400` | `uac-ring-group.xml` | Simultaneous ring across 3 member extensions. |
| **Voicemail** | `2003` | `uac-voicemail.xml` | Authenticated voicemail IVR greeting and navigation. |
| **Conference Bridge** | `2500` | `uac-conference.xml` | Multi-party conference room audio mixer bridge. |
| **Call Forwarding** | `2001` &rarr; `2000` | `uac-call-forward.xml` | Loopback call diversion to forward destination. |
| **Time Condition** | `2401` | `uac-time-condition.xml` | Schedule evaluation directing calls to active destination. |
| **Follow-Me** | `2002` | `uac-follow-me.xml` | Sequential hunting cascading to alternate extensions. |
| **Emergency Routing** | `911` | `uac-emergency.xml` | High-priority dialplan routing matching emergency rules. |
| **Call Blocking** | Blacklist ID | `uac-call-block.xml` | Rejects blacklisted caller ID with `603 Decline`. |

---

## Preflight Verification & Recovery Runbook

If a test fails or registrations are rejected with `403 Forbidden`, execute this step-by-step preflight recovery:

### 1. Verify Seed Account on PBX

```bash
tpbx tinker --execute '
$a = Modules\SipAccounts\Models\SipAccount::withoutGlobalScopes()->where("auth_username", "2000")->first();
echo json_encode(["exists" => $a !== null, "password_ok" => $a?->auth_password === "LoadTest1234"], JSON_PRETTY_PRINT).PHP_EOL;
'
```

### 2. Verify Authentication CSV on Generator

```bash
head -n 2 storage/app/load-tests/sipp-users-auth.csv
```
Expected output:
```text
SEQUENTIAL
2000;LoadTest1234;<PBX_IP>;2001;2000;[authentication username=2000 password=LoadTest1234]
```

### 3. Clear Stale Runtime Processes

```bash
# On the generator: kill orphaned SIPp processes
pids=$(pgrep -x sipp || true); if [ -n "$pids" ]; then kill $pids || true; fi

# On the PBX: restart FreeSWITCH and confirm idle state
systemctl restart freeswitch
sleep 5
fs_cli -x 'show calls count'
```

### 4. Confirm Sofia Internal Profile Is Listening

```bash
fs_cli -x 'sofia status'
ss -lunp | grep -E ':5060'
```
The `internal` profile must report `RUNNING`.

### 5. Prove One Single Call Before Running Load

```bash
# Start background UAS listener on port 5066
sipp -sf load-testing/tools/sipp/uas-auto-answer-capacity.xml -i <GEN_IP> -p 5066 -m 1 <PBX_IP> &
uas_pid=$!
sleep 1

# Fire a single test call
sipp <PBX_IP> -sf load-testing/tools/sipp/uac-extension-capacity.xml \
  -inf storage/app/load-tests/sipp-users-auth.csv \
  -i <GEN_IP> -p 5158 -r 1 -m 1 -l 1

wait $uas_pid
```
Confirm `Successful call | 1` and `Failed call | 0` before initiating higher-rate capacity runs.

---

## Bottleneck Analysis & Troubleshooting

### Bottleneck Detection Guide

| Symptom | Suspected Bottleneck | Verification Command | Remediation Action |
| :--- | :--- | :--- | :--- |
| **Rising setup latency; CPU near 100%** | FreeSWITCH CPU starvation | `top` / `htop` on PBX | Lower logging level (`notice`); upgrade to 2+ vCPUs. |
| **`server reached pm.max_children`** | PHP-FPM worker starvation | `tail -n 50 /var/log/php8.5-fpm.log` | Raise `pm.max_children` for the tier (installer default is `3` on 1 GB, `6` on 2 GB, `12` on 4 GB+) and reload PHP-FPM. |
| **`503 Maximum Calls In Progress`** | FreeSWITCH session throttle | `fs_cli -x "fsctl sps"` | Increase `FS_SESSIONS_PER_SECOND=120` in `.env`. |
| **`403 Forbidden` on REGISTRATION** | Credential or realm mismatch | Inspect `register.log` | Regenerate seed data and re-sync `sipp-users-auth.csv`. |
| **`503 NORMAL_TEMPORARY_FAILURE`** | Early voicemail NOTIFY race | UAS log shows unexpected NOTIFY | Wait 8s after registration before starting the UAS listener. |
| **`CHAN_NOT_IMPLEMENTED`** | Direct raw bridge string | `freeswitch.log` | Use `loopback/${destination}/${context}` channel format. |

### Common Failures & Remediation

1. **High Concurrency XML Latency**:
   On single-core VPS nodes, XML generation under concurrency 25 hits a CPU limit of ~14–16 req/sec. Deploy 2+ vCPUs with the shipped dynamic FPM pool (`pm.max_children = 6`) or enable the optional Octane worker (`tallpbx-xml-worker`) to scale beyond 15 calls/sec (25+ CPS).
2. **Delayed Audio or Dropped Packets**:
   Ensure RTP media ports (`16384-32768/udp`) are open in the firewall. If testing over WireGuard, verify MTU (`1420`) to prevent UDP fragmentation.
3. **Stuck Channels After Teardown**:
   Check active channels with `fs_cli -x 'show channels'`. Kill lingering channels using `fs_cli -x 'uuid_kill <UUID> NORMAL_CLEARING'`.

---

## References

- [PBX SIP Load Testing & Benchmark Results](load-testing-results.md) — Authoritative capacity measurements, latency percentiles, and hardware sizing matrices.
- [Operations & Maintenance Guide](operations.md) — Day-to-day service commands and session rate tuning.
- [System Architecture & Developer Guide](architecture.md) — Kernel firewall rules, modular structure, and whitelist management.
- [SIPp Documentation](https://sipp.readthedocs.io/) — Official SIPp scenario syntax and command-line options.
