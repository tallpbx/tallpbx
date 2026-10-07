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
2. **Avoid Special Characters in Remote Passwords**: When seeding a remote PBX over SSH (`php artisan pbx:load-test:seed`), avoid unescaped shell metacharacters (such as `!`) in the password argument (e.g., use alphanumeric `LoadTest1234`). Unescaped exclamation points are stripped by Bash history expansion, causing credential mismatches that result in `403 Forbidden` on SIP REGISTER.
3. **Synchronize `sipp-users.csv` to the Generator**: The seeding command writes `storage/app/load-tests/sipp-users.csv` on the PBX filesystem. Before running `scripts/pbx-sipp-validate.sh` with `SKIP_SEED=1` on the load generator, copy the CSV to the generator host:
   ```bash
   scp root@<PBX_HOST>:/var/www/tallpbx/storage/app/load-tests/sipp-users.csv storage/app/load-tests/sipp-users.csv
   ```
4. **Whitelist PBX on Generator Firewall (`nftables`)**: During outbound gateway routing and media playback, FreeSWITCH bridges calls to the generator host (UAS ports UDP 5088, 5090, RTP 6000). Because these are unsolicited inbound UDP packets, stateful firewalls on the generator drop them by default. Whitelist the PBX IP on the generator before testing:
   ```bash
   sudo nft add element inet tallpbx_filter whitelist_ips { <PBX_IP> }
   ```
5. **Use FreeSWITCH Loopback Channel for Internal Bridges**: FreeSWITCH XML dialplan bridges to internal destinations (such as call forwarding) must use `loopback/${destination}/${context}` rather than raw extension strings to avoid `CHAN_NOT_IMPLEMENTED` failures.
6. **Terminate Background UAS Listeners Between Runs**: In multi-scenario scripts, clean up background UAS processes before starting new listeners on ports 5066 and 5088 to prevent `errno 98 (Address already in use)` socket binding collisions.
7. **Allocate Dedicated Client Ports on Shared Hosts**: When the load generator host also runs FreeSWITCH, SIPp UAC scenarios must avoid ports 5060 (Sofia internal), 5080 (Sofia external), and 5088 (gateway UAS). Use local ports 5100+ (`EXTENDED_UAC_LOCAL_PORT=5100`).
8. **Tune PHP-FPM Worker Pools**: Debian's default dynamic pool (`pm.max_children = 5`) saturates at concurrency 25, creating worker starvation and latency spikes. On ≥ 2 GB RAM, configure `/etc/php/8.5/fpm/pool.d/www.conf` to `pm = static` with `pm.max_children = 6` (or `12` on 4GB+ systems).
9. **Set Long Registration Leases**: The SIP registration scenario (`tools/sipp/register.xml`) must use a 1-hour lease (`Expires: 3600`) so registrations do not expire mid-test.

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
| **Tool** | `php artisan pbx:load-test:dialplan` | `scripts/pbx-sipp-validate.sh` (SIPp) |
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
7. **Review Results**: Check `summary.md` and log files in `storage/app/load-tests/`.

### Quick Commands

```bash
# 1. Seed 100 test extensions on the PBX
php artisan pbx:load-test:seed \
  --tenant=load-test-beta --domain=load.test.local \
  --extensions=100 --start=2000 --password='LoadTest1234' --reset

# 2. Run XML routing throughput test (100 requests, 5 concurrency)
php artisan pbx:load-test:dialplan \
  --tenant=load-test-beta --url=http://127.0.0.1/api/v1/xml-handler \
  --scenario=mixed --requests=100 --concurrency=5 \
  --token="$FS_XML_HANDLER_TOKEN"

# 3. Run SIPp end-to-end validation suite from the load generator
PBX_HOST=<PBX_IP> LOAD_GENERATOR_IP=<GEN_IP> scripts/pbx-sipp-validate.sh
```

---

## Interpreting Test Metrics

When evaluating benchmark output:

- **Requests per Second (`req/sec`)**: Completed XML routing answers delivered per second. Reflects web/PHP/database processing capacity.
- **Achieved Calls per Second (`calls/sec`)**: Measured rate of successfully answered calls (`200 OK`) between the first and last answer. If achieved CPS falls well below the offered rate, calls are queueing.
- **Median Latency (`p50`)**: The middle response time. Accurately reveals what a typical caller experiences, unaffected by rare one-off startup spikes.
- **Tail Latency (`p95` & `p99`)**: Identifies how the slowest 5% and 1% of calls behaved under peak concurrency, exposing buffer stalls or worker starvation.
- **Fastest (Min) & Slowest (Max)**: The absolute best-case (pre-warmed cache hit) and worst-case (cold miss or queue wait) latencies recorded during the run.

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

---

## Seed Data Generation

The `pbx:load-test:seed` Artisan command idempotently generates test accounts, dialplans, routes, and SIP credentials:

### 1. Seeding for Test 1 (XML Throughput)

```bash
php artisan pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain=load.test.local \
  --extensions=100 \
  --start=2000 \
  --password='LoadTest1234' \
  --reset
```

### 2. Seeding for Test 2 (SIPp Live Calls & Media)

```bash
php artisan pbx:load-test:seed \
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

- `--include-media-fixtures`: Creates the callcenter queue `load_test_moh` (`local_stream://moh`) and IVR announcement `load_test_announcement`.
- `--include-extended-fixtures`: Creates ring group 2400, voicemail 2003, conference 2500, call forward 2001 &rarr; 2000, time condition 2401, follow-me 2002, emergency 911, and call blocking fixtures.

---

## Executing Test 1: Dynamic Dialplan XML Throughput

### Running the Test

Run against the real Nginx/PHP-FPM web endpoint using `php artisan pbx:load-test:dialplan`:

```bash
php artisan pbx:load-test:dialplan \
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

### Relevant .env Configuration

```dotenv
# Token authentication for FreeSWITCH XML handler
FS_XML_HANDLER_AUTH=true
FS_XML_HANDLER_TOKEN=generated-by-installer

# Production default: 5-second TTL across all telephony caches
FS_XML_HANDLER_CACHE_TTL=5

# Master cache store (always use Redis in production)
FS_XML_HANDLER_CACHE_STORE=redis
CACHE_STORE=redis
SESSION_DRIVER=redis

# Safety throttle for new session creation (default allows ~30 calls/sec)
FS_SESSIONS_PER_SECOND=60
```

> [!NOTE]
> Control-panel updates (adding extensions, changing routes) automatically increment `RoutingCacheVersion`, invalidating active dialplan caches immediately across all tiers without requiring manual cache flushing.

### Cache Sweep & Hit Rate Benchmarking

To benchmark all 5 standard cache tiers on your host and measure exact Redis keyspace hit rates:

```bash
bash scripts/run-cache-sweep.sh
```

The script runs a standardized 5-tier test (Cold baseline, Contributor-only, Default 5s burst, Call-center 30s profile, and Memory hit ceiling) and automatically restores production defaults (`TTL=5`) upon completion.

---

## Executing Test 2: End-to-End SIP Calls

### Running the Automated Suite (`pbx-sipp-validate.sh`)

Execute the validation suite from the load generator host:

```bash
# Basic run: Registration, internal extension calls, and outbound routing
PBX_HOST=<PBX_IP> LOAD_GENERATOR_IP=<GEN_IP> scripts/pbx-sipp-validate.sh

# Media flow run: Adds call recording (*732), music on hold, and IVR announcements
MEDIA_FLOW=1 PBX_HOST=<PBX_IP> LOAD_GENERATOR_IP=<GEN_IP> scripts/pbx-sipp-validate.sh

# Extended parity run: Adds ring groups, voicemail, conferences, forwarding, etc.
EXTENDED=1 PBX_HOST=<PBX_IP> LOAD_GENERATOR_IP=<GEN_IP> scripts/pbx-sipp-validate.sh
```

A successful run exits `0` and creates a detailed artifact directory under `storage/app/load-tests/sipp-e2e-*` containing `summary.md`, CSV timings, and error logs.

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
cd /var/www/tallpbx
php artisan tinker --execute '
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
sipp -sf tools/sipp/uas-auto-answer-capacity.xml -i <GEN_IP> -p 5066 -m 1 <PBX_IP> &
uas_pid=$!
sleep 1

# Fire a single test call
sipp <PBX_IP> -sf tools/sipp/uac-extension-capacity.xml \
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
| **`server reached pm.max_children`** | PHP-FPM worker starvation | `tail -n 50 /var/log/php8.5-fpm.log` | Switch to `pm = static` with 12 workers (on ≥ 4GB RAM). |
| **`503 Maximum Calls In Progress`** | FreeSWITCH session throttle | `fs_cli -x "fsctl sps"` | Increase `FS_SESSIONS_PER_SECOND=120` in `.env`. |
| **`403 Forbidden` on REGISTRATION** | Credential or realm mismatch | Inspect `register.log` | Regenerate seed data and re-sync `sipp-users-auth.csv`. |
| **`503 NORMAL_TEMPORARY_FAILURE`** | Early voicemail NOTIFY race | UAS log shows unexpected NOTIFY | Wait 8s after registration before starting the UAS listener. |
| **`CHAN_NOT_IMPLEMENTED`** | Direct raw bridge string | `freeswitch.log` | Use `loopback/${destination}/${context}` channel format. |

### Common Failures & Remediation

1. **High Concurrency XML Latency**:
   On single-core VPS nodes, XML generation under concurrency 25 hits a CPU limit of ~14–16 req/sec. Deploy 2+ vCPUs with pre-forked static workers to scale beyond 15 calls/sec.
2. **Delayed Audio or Dropped Packets**:
   Ensure RTP media ports (`16384-32768/udp`) are open in the firewall. If testing over WireGuard, verify MTU (`1420`) to prevent UDP fragmentation.
3. **Stuck Channels After Teardown**:
   Check active channels with `fs_cli -x 'show channels'`. Kill lingering channels using `fs_cli -x 'uuid_kill <UUID> NORMAL_CLEARING'`.

---

## References

- [PBX SIP Load Testing & Benchmark Results](load-testing-results.md) — Authoritative capacity measurements, latency percentiles, and hardware sizing matrices.
- [Operations & Maintenance Guide](operations.md) — Day-to-day service commands and session rate tuning.
- [Security Architecture & Flow Guide](security-architecture.md) — Kernel firewall rules and whitelist management.
- [SIPp Documentation](https://sipp.readthedocs.io/) — Official SIPp scenario syntax and command-line options.
