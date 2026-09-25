# SIP Load Testing Guide

Authoritative load testing guide (September 2026). This document consolidates
and replaces all legacy load and call testing documents into one unified, self-contained guide.

The measurements and sizing recommendations in this guide represent freshly executed benchmarks from the
September 2026 cloud datacenter benchmarking campaign across dedicated and shared VPS configurations.

## Audience

This guide is written for both engineers and automated test harnesses.

- Engineers and administrators should be able to skim the purpose, quick
  commands, expected results, capacity figures, and troubleshooting notes
  without needing to understand every SIP detail.
- Automated harnesses, AI agents, and test runners should use the exact
  commands, file paths, environment variables, and artifact names when
  running or debugging the tests.

The first half explains what the two tests measure and what their results
mean. The later sections are deliberately more detailed so an engineer or
operator can reproduce the exact lab setup, runs, and checks.

## Reproducibility Guide & AI Agent Checklist

To ensure other AI agents and engineers can reproduce these tests end-to-end
without rediscovering and fixing subtle environment traps, follow this checklist:

1. **Obfuscate Public IP Addresses**: In all public documentation, test summaries,
   and committed tables, public IP addresses must be obfuscated to show only the
   last octet (e.g. `x.x.x.218` or `...YYY`). Never expose complete public IP addresses.
2. **Pest Full Suite Parallel Flag**: Always invoke Pest with `--parallel`:
   `php artisan test --compact --parallel`. Running the test suite sequentially is
   substantially slower and can lead to execution timeouts.
3. **Avoid Special Characters in Remote SSH Passwords**: When seeding the remote PBX
   via SSH (`php artisan pbx:load-test:seed`), do NOT include `!` or shell meta-characters
   in the `--password` parameter unless strictly escaped (e.g., use alphanumeric `LoadTest1234`).
   Unescaped exclamation points are stripped by Bash history expansion and subshell
   parsing over SSH, causing the database password to diverge from the local CSV and
   resulting in `403 Forbidden` on SIP `REGISTER`.
4. **Copy `sipp-users.csv` to the Orchestrator**: The seeding command writes
   `storage/app/load-tests/sipp-users.csv` on the *PBX* host. The SIPp validation script
   runs on the *load generator* host. You MUST copy `sipp-users.csv` from the PBX to the
   orchestrator's `storage/app/load-tests/` directory before running
   `scripts/pbx-sipp-validate.sh` with `SKIP_SEED=1`.
5. **Whitelist the PBX on the Generator's Firewall (`nftables`)**: During outbound
   routing, media playback, and extended scenarios, FreeSWITCH bridges calls to the
   load generator (e.g. ports UDP 5088, 5090, RTP 6000). Because these are unsolicited
   inbound UDP packets from Sofia `external` (port 5080), stateful firewalls on the
   load generator will DROP this traffic by default (`policy drop`). Before testing,
   add the PBX IP to the generator's whitelist:
   `sudo nft add element inet tallpbx_filter whitelist_ips { <PBX_IP> }`.
6. **Internal Dialplan Bridges Require FreeSWITCH Loopback**: Unconditional call
   forwarding or internal dialplan bridges must use `loopback/${destination}/${context}`.
   Attempting to bridge directly to raw numbers or `{dialplan=XML...}` causes FreeSWITCH
   to abort with `Cannot create outgoing channel of type [...] cause: [CHAN_NOT_IMPLEMENTED]`.
7. **Clean Up Background UAS Listeners Between Phases**: In multi-phase SIPp scripts,
   background UAS processes from earlier phases must be explicitly terminated (`cleanup`)
   before binding new listeners on the same ports (such as 5066 and 5088). Failure to do
   so causes SIPp to exit immediately with `errno 98 (Address already in use)`, leaving
   scenarios unmonitored and failing with `503 Service Unavailable` (`NORMAL_TEMPORARY_FAILURE`).
8. **Avoid Local Telephony Sockets for UAC Clients**: When the load generator host
   also runs FreeSWITCH, SIPp UAC scenarios must not bind to ports 5060 (Sofia internal),
   5080 (Sofia external), or 5088 (gateway UAS). `EXTENDED_UAC_LOCAL_PORT` defaults to `5100`
   (spanning 5100–5114) to prevent socket collisions.
9. **PHP-FPM Worker Pool Tuning**: The Debian default dynamic pool (`pm.max_children = 5`)
   saturates at concurrency 25, creating worker starvation and high latency. For a
   standard 4GB PBX, configure `/etc/php/8.5/fpm/pool.d/www.conf` to `pm = static` with
   `pm.max_children = 12` (+15% throughput, 0 queueing errors, 2.9 GiB free RAM).
10. **SIP Registration Expiry During Long Test Suites**: The SIP registration scenario
    (`tools/sipp/register.xml`) must specify a long lease (`Expires: 3600`) instead of
    300s so registrations do not expire before later test phases execute. In addition,
    multi-phase runners should refresh registrations before extended parity scenarios to
    prevent `Reason: SIP;cause=806;text="USER_NOT_REGISTERED"` when bridging calls.

## How This Document Is Organized

This guide focuses strictly on the operational methodology, tools, and procedures for executing telephony benchmarks:

1. **Test Methodology**: What the two tests measure, how to read the numbers, the hardware progression, and the campaign plan.
2. **Lab Setup & Seeding**: Prerequisites, runtime state, and synthetic test data creation.
3. **Execution Guides**: Detailed step-by-step procedures for running Test 1 (dynamic dialplan XML) and Test 2 (SIPp end-to-end calls).
4. **Analysis & Diagnostics**: Bottleneck hunting, troubleshooting runbooks, recovery steps, and scenario references.
5. **Results & Sizing Reference**: All empirical benchmark measurements, latency statistics, cache hit-rate sweeps, and administrative hardware sizing recommendations are maintained in the companion document: **[docs/load-testing-results.md](load-testing-results.md)**.

## The Two Tests

There are two primary benchmark tests that evaluate performance at different
layers of the telephony stack, supplemented by essential optimization sweeps
(such as cache hit-rate testing and worker pool tuning). Their results answer
different questions and must never be mixed up:

1. **Dynamic Dialplan XML (requests per second).** Measures how quickly
   Laravel can generate call-routing XML. This includes **Cache Optimization
   & Hit Rate Sweeps** to verify memory caching efficiency and determine
   optimal TTL settings. One "request" is one HTTP question sent to the
   application, not a phone call. Tool:
   `php artisan pbx:load-test:dialplan`.
2. **End-to-end calls (calls per second).** Measures how many complete
   simulated calls the whole PBX can establish and tear down: SIP
   registration, `INVITE`, authentication, Laravel XML lookups through
   FreeSWITCH, bridging, answer, hold time, and hangup. Tools:
   `scripts/pbx-sipp-validate.sh` with the SIPp scenarios in `tools/sipp/`,
   plus higher-rate capacity scenarios for staged call-rate runs.

### What Each Test Answers

| Test | What it answers | What it does not answer |
| --- | --- | --- |
| Dialplan XML requests/sec | How many Laravel XML routing answers can be generated each second? | How many complete SIP calls can connect, carry media, and hang up each second? |
| Cache optimization & hit rate sweep | What percentage of XML lookups are served directly from Redis memory without database hits, and what is the optimal TTL window? | Overall SIP signaling latency or FreeSWITCH bridging limits. |
| End-to-end calls/sec | How many complete call attempts per second can the whole PBX handle at an acceptable success rate and setup time? | Which individual component caused a slowdown without additional measurements. |
| Concurrent-call checks (part of the SIPp scenarios) | How many calls can remain active at the same time? | How quickly new calls can be established during a burst. |

Most people asking "how many calls can this PBX handle?" want the end-to-end
answer: successful calls per second and simultaneous active calls. Those
numbers must come from SIPp or another SIP load generator traversing
FreeSWITCH, Laravel XML generation, the destination call leg, and teardown.
They cannot be calculated from XML handler requests/sec alone.

### What The XML Test Covers

The XML test isolates one part of the call chain:

```text
Load generator -> Nginx/PHP-FPM -> Laravel -> MariaDB/Redis -> XML answer
```

> [!NOTE]
> **Why FreeSWITCH is not in this test chain**:
> The XML test runner acts as a synthetic replacement for FreeSWITCH's `mod_xml_curl` module, sending HTTP requests directly to Nginx and Laravel (`/api/v1/xml-handler`). This intentionally isolates database query performance, PHP-FPM worker concurrency, and Redis caching without the interference of SIP signaling, Sofia profile mutexes, RTP media, or FreeSWITCH session rate limits. Use the end-to-end SIPp suite to test FreeSWITCH directly.

A request identifies the FreeSWITCH section (`dialplan`), the tenant call
context (such as `tenant_12_internal`), the caller's number, and the number
being reached. Laravel looks up extensions, inbound routes, outbound routes,
IVRs, ring groups, time conditions, and other enabled features, then answers
with FreeSWITCH XML dialplan instructions. The `mixed` scenario spreads
requests across internal, inbound, and outbound examples such as:

- Internal: "Extension 2000 is calling extension 2001. How should it be routed?"
- Inbound: "An outside caller dialed DID 15551230000. Where should it go?"
- Outbound: "An extension dialed 91555123000. Which outbound route should it use?"

The test stops after the XML answer. It does not register a phone, send an
`INVITE`, ring, answer, carry audio, or create a call record. Use the
end-to-end test for those checks.

### What The End-To-End Test Covers

A complete simulated call covers the full chain:

```text
SIPp caller endpoint
  -> SIP INVITE and authentication
  -> FreeSWITCH receives the call
  -> FreeSWITCH asks Laravel for directory and dialplan XML
  -> FreeSWITCH follows those instructions and creates the second call leg
  -> SIPp called endpoint rings and answers with 200 OK
  -> the call stays connected for the configured hold time
  -> one side sends BYE, the other confirms
  -> FreeSWITCH releases both call legs and returns to idle
```

The SIPp scenarios prove, at low volume:

- a phone can register;
- one extension can call another extension;
- an outbound-style call follows the generated routing rules;
- a recording call starts and stops;
- a caller reaches music on hold and announcements;
- ring groups, voicemail, conferences, call forwarding, time conditions,
  and follow-me forwarding behave correctly;
- calls hang up cleanly and no channels stay stuck;
- the required FreeSWITCH modules still load after install or reboot.

Run the XML test first. If the application already returns bad XML or slow
responses, SIPp will fail too, but the failure will be harder to read. Use
the XML test to find application bottlenecks and SIPp to prove the full call
chain works.

## Quick Version

Most of the time, run the tests in this order. The dialplan XML test is the
single-server bottleneck test; the SIPp runner is the server-to-server call
test.

1. Confirm FreeSWITCH is running on the PBX server.
2. Seed the test tenant and test extensions.
3. Run the dialplan XML load test against the real HTTP endpoint.
4. Run the basic SIPp end-to-end test between the two test machines.
5. Run the media-flow SIPp test only after the basic test passes.
6. Run the extended parity SIPp test after the basic test passes.
7. Open `summary.md` in the run directory first; it is the readable
   pass/fail summary.

XML load test:

```bash
php artisan pbx:load-test:dialplan \
  --tenant=load-test-beta \
  --url=https://PBX_HOST/api/v1/xml-handler \
  --scenario=mixed \
  --requests=1000 \
  --concurrency=50 \
  --token="$FREESWITCH_XML_HANDLER_TOKEN" \
  --label="moderate-office-regression" \
  --max-failure-rate=0 \
  --max-average-ms=1000 \
  --report=storage/app/load-tests/dialplan-report.json
```

Basic SIPp test:

```bash
PBX_HOST=192.168.1.76 \
LOAD_GENERATOR_IP=192.168.1.65 \
FORCE_SEED=1 \
scripts/pbx-sipp-validate.sh
```

Expected result: the script exits `0`, `summary.md` shows the requested
scenarios passed, and registrations, extension calls, and outbound calls all
show zero failed calls.

Media-flow test:

```bash
PBX_HOST=192.168.1.76 \
LOAD_GENERATOR_IP=192.168.1.65 \
FORCE_SEED=1 \
MEDIA_FLOW=1 \
scripts/pbx-sipp-validate.sh
```

Expected result: registration, recording, music-on-hold, and announcement
checks pass. Add `EXTENDED=1` with `FORCE_SEED=1` to run the extended parity
scenarios (ring group, voicemail 2003, conference 2500, call forward, time
condition 2401, follow-me). Extended scenarios require the
`--include-extended-fixtures` seed data, which `EXTENDED=1` enables
automatically.

## How To Read The Numbers

These measurements answer two questions: how much work did the PBX complete,
and how long did callers or callers-to-be wait for it?

- **Requests/sec** means XML routing requests completed per second. Each
  request is one HTTP question asking Laravel for one call's routing
  instructions. It is not one completed SIP call.
- **Calls/sec** means complete answered simulated calls per second. If SIPp
  tries 8 calls per second but the PBX answers about 5, the measured result
  is about 5 calls/sec.
- **Average** is every wait time added together and divided by the number of
  requests or calls. It answers: about how long did one normally take?
- **Fastest** is the single quickest answer. It shows the best case when
  little or no work was queued.
- **Slowest** is the single longest wait. Treat it as a warning sign,
  especially if it repeats, rather than as the normal caller experience.

Values are in milliseconds (`ms`); 1,000 ms is one second. Example:

```text
30 req/sec | average 220 ms | fastest 110 ms | slowest 900 ms
```

Laravel produced about 30 routing answers per second. A request took 220 ms
on average, the quickest took 110 ms, and the slowest took 900 ms. The gap
between the average and the slowest result shows that at least one request
waited much longer than usual.

For end-to-end calls, the stopwatch starts when the simulated caller sends
its first `INVITE` and stops when the destination answers with `200 OK`. It
includes authentication, Laravel XML lookups, FreeSWITCH routing, the second
call leg, ringing, and answer. It does not include the connected hold time.
An `average 1,484 ms; fastest 682 ms; slowest 3,671 ms` result means calls
answered in about one and a half seconds on average, the best call answered
in under a second, and the worst call took more than three and a half
seconds.

Test names such as `500 x 25` mean 500 total requests with up to 25 being
sent at the same time. The `25` is concurrency, not 25 requests/sec. The
achieved requests/sec value says how quickly the server actually completed
the work.

When comparing two successful runs:

- First check that neither run had failed responses. Speed does not matter
  if the PBX returns incorrect answers or errors.
- Prefer higher requests/sec or calls/sec because more work was completed.
- Prefer a lower average because the overall wait was shorter.
- Check the fastest result to understand the best case.
- Treat the slowest result as the warning light.

Call rate and concurrency are different. If calls stay connected for 30
seconds and the test starts 2 calls each second, about 60 calls will be
active at steady state. A low concurrency limit forces SIPp to wait, so the
configured attempt rate may be higher than the rate the test actually
achieves.

## Test Phases And Hardware Progression

Testing runs in two phases, and the results in this guide are presented in
that same order:

1. **Single-server bottleneck testing.** The dynamic dialplan XML test runs
   against one server to find its maximum requests per second and to expose
   application-level bottlenecks. The test client may run on the server
   itself while hunting for code bottlenecks, but capacity claims use a
   separate generator host.
2. **Server-to-server call testing.** The full SIPp end-to-end suite runs
   between two machines: one PBX server under test and one dedicated SIPp load
   generator in the same datacenter region.

The campaign executes single-server XML throughput ladders and 5-tier cache sweeps first,
followed by server-to-server capacity and parity ladders across cloud VPS hardware tiers.
The "Campaign Plan" section below defines the setups, roles, execution order, and gates;
the fresh-install procedure lives in "Fresh Install Validation (Install Script Test)".

**Phase 1 — single-server bottleneck testing (requests per second):**

| ID | Environment | Specification | Status |
| --- | --- | --- | --- |
| B1 | Shared-CPU Datacenter VPS | 1 vCPU, 967 MiB RAM, 2.0 GiB swap | Complete: single-server ladder and 5-tier cache sweep completed September 24, 2026 |
| B2 | Shared-CPU Datacenter VPS | 1 vCPU, 1973 MiB RAM, 2.0 GiB swap | Complete: single-server ladder and 5-tier cache sweep completed September 24, 2026 |
| B3 | Shared-CPU Datacenter VPS | 2 vCPU, 1973 MiB RAM, 2.0 GiB swap | Complete: single-server ladder and 5-tier cache sweep completed September 24, 2026 |
| C1 | Dedicated-CPU Datacenter VPS | 2 vCPU, 2 GiB RAM | Skipped: streamlined matrix to eliminate testing redundancy |
| C2 | Dedicated-CPU Datacenter VPS | 4 vCPU, 16 GiB RAM (adjusted from 8 GiB based on cloud availability) | Complete: single-server ladder (65+ req/sec sustained) and 5-tier cache sweep completed September 24, 2026 |

**Phase 2 — server-to-server call testing (calls per second):**

| ID | PBX under test | SIPp load generator | Status |
| --- | --- | --- | --- |
| B1 | Shared-CPU Datacenter VPS (1 vCPU / 1 GiB RAM) | Dedicated load generator in the same datacenter (`sfo3`) | Complete: 14-scenario parity suite and capacity ladder completed September 24, 2026 |
| B2 | Shared-CPU Datacenter VPS (1 vCPU / 2 GiB RAM) | Dedicated load generator in the same datacenter (`sfo3`) | Complete: capacity ladder completed September 24, 2026 |
| B3 | Shared-CPU Datacenter VPS (2 vCPU / 2 GiB RAM) | Dedicated load generator in the same datacenter (`sfo3`) | Complete: capacity ladder (5–10 CPS ceiling) completed September 24, 2026 |
| C1 | Dedicated-CPU Datacenter VPS (2 vCPU / 2 GiB RAM) | Dedicated load generator in the same datacenter (`sfo3`) | Skipped: streamlined matrix |
| C2 | Dedicated-CPU Datacenter VPS (4 vCPU / 16 GiB Dedicated) | Dedicated load generator in the same datacenter (`sfo3`) | Complete: full 2–30 CPS capacity ladder (2,110 calls, 100% completion, 0 drops) completed September 24, 2026 |

Server-to-server topology:

- Datacenter testing runs the two virtual servers in the same datacenter region (`sfo3`).
- The PBX target virtual server runs on a shared-CPU plan for the 1 vCPU and
  2 vCPU tests with up to 2 GiB RAM and on a dedicated-CPU server for the
  high-density enterprise profile (4 vCPU Dedicated / 16 GiB RAM).

Notes for both phases:

- Keep the provider, datacenter region, public IP, disk, operating system,
  application commit, seed data, and generator hosts constant across the
  datacenter stages so CPU and memory are the primary variables.
- Record whether each size was an in-place resize or a replacement server,
  plus the provider CPU model/class, disk type, and region.
- Datacenter test stages cross the datacenter network fabric. Record idle round-trip latency before
  every measured run; raw latency values include network latency.
- After each resize, reboot and confirm the new values with `lscpu`,
  `free -h`, and `swapon --show` before running the staged tiers.

## Campaign Plan

The campaign executes single-server bottleneck tests first, followed by
server-to-server call testing, and every run uses new test data created from
scratch. This section defines the campaign setups, roles, and order; the
fresh-install procedure is in "Fresh Install Validation (Install Script Test)"
in Test Lab Setup.

### Campaign Setups

| ID | Role | Notes |
| --- | --- | --- |
| B1–B3 | PBX targets | Shared-CPU datacenter VPS; specifications and status in the Phase 1 table. |
| C1–C2 | PBX targets | Dedicated-CPU datacenter VPS; specifications and status in the Phase 1 table. |
| D | SIPp load generator | Dedicated datacenter server used for the datacenter pairs. |

### Dedicated Load Generator & Orchestration Host

The load generator coordinates the campaign:

- SSH into each freshly installed server after the installer has been run, and prepare it for testing: seed the new test data, verify the runtime state, and run the preflight checks.
- Copy the seed CSVs back from each PBX and build the SIPp authentication CSVs.
- Act as the SIPp source server (caller side) for the datacenter pairs.
- Start runs, collect artifacts, and record results.

### Execution Order

1. Install the target PBX from scratch by running `scripts/install.sh`, then complete the fresh-install validation checklist over SSH.
2. Create the new test data (seed on PBX) and copy the CSVs back to the load generator.
3. Shared-CPU single-server ladder — B1, then B2, then B3.
4. Dedicated-CPU single-server ladder — C2.
5. Datacenter server-to-server pairs — B1 pair (correctness & parity), B3 pair (capacity), then C2 pair (full capacity ladder).
6. Refresh the results tables in this guide with the new measurements, and record findings in the changelog.

### Per-Setup Test Matrix

| Stage | Setup | Tests | Experiments |
| --- | --- | --- | --- |
| 1 | B1, B2, B3 (shared-CPU single-server) | XML tiers (`100 x 5`, `500 x 25`, `1,000 x 25`), three repetitions each | PHP-FPM dynamic vs. static worker pool; 5-tier cache sweep |
| 2 | C2 (dedicated-CPU single-server) | XML tiers (`100 x 5`, `500 x 25`, `1,000 x 25`), three repetitions each | PHP-FPM static 24-worker pool; 5-tier cache sweep |
| 3 | Datacenter pairs (B1, B3, C2) | B1: 14-scenario parity suite and 3 CPS baseline. B3 & C2: 2–30 CPS capacity ladder, zero stuck channels | FreeSWITCH log level (`notice`) and PHP-FPM per pair |

The concurrent-call ladder and the RTP media capacity test need new SIPp
scenarios; see the open items below.

### New Test Data

All runs use a new synthetic tenant created from scratch: a new tenant name,
SIP realm/domain, extension range, password, and CSV file names. Do not
reuse the historical `load-test-beta` values. Keep the chosen values
consistent across the PBX and the generator for the whole campaign and
record them with the campaign notes. Seed commands are in "Seed Data"; the
generator-side CSV copy and authentication column are described in Test 2
and the recovery runbook.

### Gates And Stop Conditions

- The fresh-install validation checklist must pass fully before benchmarking.
- XML stages: advance a tier only when the previous tier had zero failed
  responses; stop escalating per the stop conditions in "Per-Run Record,
  Staged Tiers, And Stop Conditions".
- Pair stages: correctness (basic, media, extended) passes before the
  capacity ladder; each capacity tier must end with zero stuck channels
  after teardown; a tier fails when the success rate or setup time degrades
  beyond the recorded thresholds.
- Record medians of at least three repetitions per comparison tier (see
  "How Results Are Recorded").

### Recording And Artifacts

Follow "How Results Are Recorded", "Per-Run Record, Staged Tiers, And Stop
Conditions", and "Artifacts". Keep every XML JSON report, sampler log, SIPp
artifact directory, and the installer logs with the campaign artifacts.
When the campaign completes, replace the historical reference tables in the
results sections with the new measurements in place.

### Open Items

- Choose the new test-data values (tenant, realm, extension range,
  password, CSV names).
- Implement the two new SIPp scenarios (concurrent-call hold ladder and
  RTP media capacity) plus the recording assertion for the media runner,
  per "Additional Tests To Add To The Campaign" in Test 2.
- Choose the datacenter provider and region for B1–B3 and C1–C2; record
  instance identity, CPU class, and disk type.
- Configure SSH access between the load generator and PBX servers (keys and
  ports), and record the SSH endpoints with the campaign notes.
- Confirm datacenter networking interfaces and firewall whitelist rules on both hosts.
- Capture idle round-trip latency before every remote run.

## Test Lab Setup

### Machines And Roles

| Role | Host | Notes |
| --- | --- | --- |
| Datacenter PBX under test | `x.x.x.200` | Cloud VPS used for the datacenter benchmark series across 1c/1g, 1c/2g, 2c/2g, and 4c/16g tiers. |
| Datacenter load generator | `x.x.x.173` | Dedicated 2 vCPU cloud node in the same region (`sfo3`) executing SIPp scenarios over direct public IP routing. |
| SIP signaling | PBX `5060` | FreeSWITCH internal Sofia profile. |
| SIPp local ports | `5066`, `5070`, `5072`, `5074+` | Separate ports prevent one scenario from colliding with another. |
| SIPp RTP ports | `6000`, `6002`, `6004+` | Media-flow scenarios use RTP echo with SIPp `-mi` and `-mp`. |

For repeatable results, run SIPp from a separate Linux host or VM instead of
the PBX server itself. That keeps the test caller away from the PBX and
closer to how real phones or trunks behave. Server-to-server call testing
therefore always uses two machines: one PBX target and one dedicated load
generator running in the same datacenter region. The HTTP generator for the XML test may run on the
server itself while hunting for code bottlenecks, but for capacity claims
run it on a separate host so its CPU and memory do not inflate usage on the
server under test.

### Network Reachability And WireGuard

The full SIPp harness needs bidirectional reachability. SIPp sends
registrations and calls to FreeSWITCH, but FreeSWITCH also opens new SIP
dialogs toward the registered SIPp endpoint and the synthetic outbound
gateway. Media validation sends RTP back to the address and ports advertised
by SIPp. A normal outbound NAT mapping does not make arbitrary SIPp UAS and
RTP listeners reliably reachable.

Use WireGuard when either host is behind NAT or a firewall and the other
host cannot directly route to its SIP/RTP addresses. A tunnel is unnecessary
when both hosts share a routable LAN or both have intentionally exposed,
firewall-restricted SIP and RTP addresses.

| Network shape | Recommendation |
| --- | --- |
| Both hosts share a routable LAN | Use their LAN addresses directly. |
| Public PBX, load generator behind NAT | Run the PBX as the reachable WireGuard endpoint; the load generator initiates the tunnel outbound. |
| Public load generator, PBX behind NAT | Run the load generator as the reachable WireGuard endpoint; the PBX initiates the tunnel outbound. |
| Both hosts behind NAT, one has a UDP port forward | Use the forwarded host as the WireGuard endpoint. |
| Both hosts behind carrier-grade NAT with no inbound port forward | Use a small public WireGuard relay or a managed mesh VPN. A two-peer configuration alone is not sufficient. |

The representative topology matches a public PBX and a load generator on a
LAN behind NAT:

| Role | Address |
| --- | --- |
| Public PBX endpoint | `PBX_PUBLIC_IP:51820/udp` |
| PBX tunnel address | `10.77.0.1` |
| NATed SIPp load generator | `10.77.0.2` |

Only UDP port `51820` needs to be reachable publicly. Keep SIPp signaling
listeners and media ports private inside WireGuard.

Install and generate keys on both Linux hosts:

```bash
apt-get update
apt-get install -y wireguard-tools

install -d -m 700 /etc/wireguard
umask 077
test -s /etc/wireguard/privatekey || wg genkey > /etc/wireguard/privatekey
wg pubkey < /etc/wireguard/privatekey > /etc/wireguard/publickey
chmod 600 /etc/wireguard/privatekey
chmod 644 /etc/wireguard/publickey
```

Exchange only the contents of `publickey`. Never copy or display either
host's `privatekey`.

Public PBX `/etc/wireguard/wg0.conf`:

```ini
[Interface]
Address = 10.77.0.1/24
ListenPort = 51820
PrivateKey = <PBX_PRIVATE_KEY>

[Peer]
PublicKey = <LOAD_GENERATOR_PUBLIC_KEY>
AllowedIPs = 10.77.0.2/32
```

NATed load generator `/etc/wireguard/wg0.conf`:

```ini
[Interface]
Address = 10.77.0.2/24
PrivateKey = <LOAD_GENERATOR_PRIVATE_KEY>

[Peer]
PublicKey = <PBX_PUBLIC_KEY>
AllowedIPs = 10.77.0.1/32
Endpoint = PBX_PUBLIC_IP:51820
PersistentKeepalive = 25
```

`PersistentKeepalive = 25` keeps the load generator's outbound NAT mapping
available for traffic from the PBX, following the official WireGuard NAT
traversal guidance. If the PBX is the host behind NAT, reverse the endpoint
roles and put `PersistentKeepalive = 25` on the PBX peer instead. If both
hosts are behind NAT, the peer with a UDP port forward or public relay is
the endpoint.

Enable and verify on both hosts:

```bash
chmod 600 /etc/wireguard/wg0.conf
systemctl enable --now wg-quick@wg0
systemctl is-active wg-quick@wg0
wg show wg0
```

```bash
# From the load generator
ping -c 3 10.77.0.1

# From the PBX
ping -c 3 10.77.0.2
```

A healthy result has a recent WireGuard handshake and no packet loss. No IP
forwarding or default-route change is needed for this host-to-host tunnel.

If the Sofia profile listens only on the PBX public IP, SIPp cannot target
the WireGuard address directly. Keep the existing public listener and add
narrow NAT rules to the PBX `[Interface]` section:

```ini
# Let SIPp reach the public-IP-bound internal Sofia listener through wg0.
PostUp = iptables -t nat -C PREROUTING -i %i -d 10.77.0.1 -p udp --dport 5060 -j DNAT --to-destination PBX_PUBLIC_IP:5060 || iptables -t nat -A PREROUTING -i %i -d 10.77.0.1 -p udp --dport 5060 -j DNAT --to-destination PBX_PUBLIC_IP:5060
PostDown = iptables -t nat -D PREROUTING -i %i -d 10.77.0.1 -p udp --dport 5060 -j DNAT --to-destination PBX_PUBLIC_IP:5060 || true

# Give PBX-originated SIP and RTP the source allowed by the load-generator peer.
PostUp = iptables -t nat -C POSTROUTING -o %i -d 10.77.0.2 -p udp -j SNAT --to-source 10.77.0.1 || iptables -t nat -A POSTROUTING -o %i -d 10.77.0.2 -p udp -j SNAT --to-source 10.77.0.1
PostDown = iptables -t nat -D POSTROUTING -o %i -d 10.77.0.2 -p udp -j SNAT --to-source 10.77.0.1 || true
```

Replace `PBX_PUBLIC_IP` with the address shown as `SIP-IP` by
`fs_cli -x 'sofia status profile internal'`. The DNAT rule affects only UDP
5060 arriving on `wg0`; it does not open another public listener. The SNAT
rule applies only to UDP sent through `wg0` to the one SIPp peer and is
needed because WireGuard rejects inner source addresses outside the peer's
`AllowedIPs`. Restart `wg-quick@wg0` on the PBX afterward and send traffic
from the load generator so the PBX relearns its endpoint; pinging
`10.77.0.1` forces it immediately.

### Required PBX Runtime State

Fresh and existing installs converge to the same FreeSWITCH runtime state
with:

```bash
cd /var/www/tallpbx
bash scripts/resources/freeswitch.sh --configure-only
```

That command persists module load lines in
`/etc/freeswitch/autoload_configs/modules.conf.xml`, writes
`/etc/freeswitch/autoload_configs/xml_curl.conf.xml`, reloads XML, and
attempts to load the required modules immediately. Verify the persistent
module lines:

```bash
rg -n '<load module="mod_(sofia|callcenter|dptools|local_stream|sndfile|xml_curl)"/>' \
  /etc/freeswitch/autoload_configs/modules.conf.xml
```

Expected modules:

```text
mod_xml_curl
mod_sofia
mod_dptools
mod_sndfile
mod_local_stream
mod_callcenter
```

Verify the live state:

```bash
fs_cli -x 'sofia status'
fs_cli -x 'callcenter_config queue list'
ss -lunp | rg ':5060|:5080'
```

Good result: `sofia status` shows the internal and external profiles
running, port `5060` is listening on the PBX address, and
`callcenter_config queue list` works (it includes `load_test_moh@default`
when media test data has been seeded).

Fresh installs configure the FreeSWITCH XML curl binding so FreeSWITCH asks
the application for directory, dialplan, and configuration XML:

```xml
<configuration name="xml_curl.conf" description="cURL XML Gateway">
  <bindings>
    <binding name="tallpbx">
      <param name="gateway-url" value="http://PBX_HOST/api/v1/xml-handler?token=TOKEN" bindings="directory|dialplan|configuration"/>
      <param name="timeout" value="5"/>
    </binding>
  </bindings>
</configuration>
```

The installer installs and enables `mod_sofia`, `mod_callcenter`,
`mod_dptools`, `mod_hiredis`, `mod_local_stream`, `mod_sndfile`, and
`mod_xml_curl`, and disables the legacy `mod_redis` and `mod_memcache` load
lines. `mod_hiredis` being loaded does not mean every call uses Redis; the
per-call Redis-backed actions are opt-in through
`FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED` and
`FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED`. On existing installs, run
`scripts/resources/freeswitch.sh --configure-only` after `.env` contains
`FREESWITCH_XML_HANDLER_TOKEN`, then restart or reload FreeSWITCH.

### Fresh Install Validation (Install Script Test)

The campaign installs the target PBX from scratch on a clean Debian 13 server to validate
`scripts/install.sh` end to end, since the installer has not been exercised
on a clean machine recently. The installer is re-runnable and never deletes
existing data, so it is run twice: once on the clean machine and again to
confirm idempotency. Usage: `./install.sh` prompts for demo data and
development packages; use `./install.sh --no-demo` on a test server (add
`--no-development` unless development tooling is needed there).

1. Start from a clean Debian 13 server matching the target specification,
   with the repository checked out under the documented path.
2. Take a server snapshot or backup image.
3. Run the installer manually per `INSTALL.md`, recording total duration
   and any warnings in the campaign log. The load generator host then
   connects over SSH for the remaining checks.
4. Verify the install:
   - [ ] Services active: nginx, php8.5-fpm, mariadb, redis-server, and
     freeswitch; the queue, reverb, and scheduler units are installed and
     enabled.
   - [ ] `php artisan optimize:clear` and `php artisan optimize` exit
     cleanly.
   - [ ] `php artisan permissions:repair --scope=full` completes without
     errors (the installer runs this automatically).
   - [ ] `php artisan module:sync --only-local` succeeds.
   - [ ] FreeSWITCH runtime state matches "Required PBX Runtime State"
     above: the profiles are RUNNING, UDP `5060` is listening, the module
     load lines are present, and `xml_curl.conf.xml` is written with the
     token.
   - [ ] `redis-cli ping` answers, and `.env` uses the Redis stores
     (`CACHE_STORE=redis`, `SESSION_DRIVER=redis`,
     `SESSION_CONNECTION=cache`).
   - [ ] `.env` has `FREESWITCH_XML_HANDLER_TOKEN` set and
     `FREESWITCH_XML_HANDLER_AUTH=true`.
   - [ ] `php artisan app:test --smoke` passes.
   - [ ] Optional: `php artisan app:test --full` passes when development
     tooling and Dusk are installed; follow the Dusk isolation rules if it
     is run.
5. Re-run the installer once more and confirm it completes cleanly without
   deleting data and with all services still healthy.
6. Snapshot the validated state and attach the install log to the campaign
   artifacts.

Any installer problem found here is a deliverable of the campaign: record
it, fix it, and re-validate before proceeding, because every later server
uses the same installer.

### FreeSWITCH Session-Rate Limit

FreeSWITCH has a core `sessions-per-second` limit. It is a safety throttle
for new FreeSWITCH sessions, not a bandwidth limit. An
extension-to-extension call normally creates two sessions: one caller leg
and one destination leg. The project default is:

```xml
<param name="sessions-per-second" value="60"/>
```

In plain language, this gives room to attempt roughly 30 two-leg calls per
second before FreeSWITCH's own throttle rejects new sessions. Overload tests
that hit the ceiling fail with `SIP/2.0 503 Maximum Calls In Progress`. That
response means FreeSWITCH deliberately refused new sessions; it does not by
itself prove packet loss, bandwidth saturation, or a Laravel/PHP-FPM
failure. For higher-volume servers, raise this only as a labelled
capacity-tuning change and retest with CPU, memory, network counters, and
SIPp failure reasons captured. A higher value removes the safety throttle
but does not create more CPU.

## Seed Data

Both tests use the same synthetic tenant tool, with different options. The
upcoming test campaign creates entirely new test data from scratch, so
treat every concrete value below (tenant name, realm, extension range,
password, CSV name) as an example to adapt, and keep the chosen values
consistent between the PBX and the generator hosts for the whole campaign.

### XML Load-Test Seed Data

```bash
php artisan pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain=load.test.local \
  --extensions=100 \
  --start=2000 \
  --password='LoadTest1234' \
  --sipp-host=LOAD_GENERATOR_IP \
  --sipp-port=5088
```

The command is idempotent for the named synthetic tenant. Add `--reset` to
delete and recreate only that tenant. Generated data:

- tenant `load-test-beta`; SIP realm `load.test.local`;
- extension/SIP account range starting at `--start`;
- default tenant SIP profiles, dialplans, feature codes, and music on hold;
- inbound DID `15551230000` bridged to the first generated extension;
- outbound `9` prefix route through the synthetic SIPp gateway;
- SIPp CSV at `storage/app/load-tests/sipp-users.csv`.

CSV columns:

1. `field0`: SIP username
2. `field1`: SIP password
3. `field2`: SIP realm/domain
4. `field3`: destination extension
5. `field4`: caller extension

### SIPp Seed Data

```bash
php artisan pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain=load.test.local \
  --extensions=20 \
  --start=2000 \
  --password='LoadTest1234' \
  --sipp-host=192.168.1.65 \
  --sipp-port=5088 \
  --output=storage/app/load-tests/sipp-users.csv \
  --include-media-fixtures
```

With `--include-media-fixtures`, the seed also creates the callcenter queue
`load_test_moh` backed by `local_stream://moh` and the IVR menu
`load_test_announcement` using a packaged FreeSWITCH Callie prompt.

For extended parity coverage (ring groups, voicemail, conferences, call
forwards, time conditions, follow-me, emergency, call blocks), add
`--include-extended-fixtures`, which creates ring group 2400, voicemail
mailbox 2003, conference 2500, call forward 2001 to 2000, time condition
2401, follow-me for 2002, emergency configuration, a call block rule, and an
`*98` voicemail feature code.

When seeding through WireGuard for a remote PBX, seed on the PBX with
`--sipp-host` set to the load generator's tunnel address and copy the CSV to
the same relative path on the load generator, then run with `SKIP_SEED=1`:

```bash
php artisan pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain="$SIP_REALM" \
  --extensions=20 \
  --start=2000 \
  --password='LoadTest1234' \
  --sipp-host=10.77.0.2 \
  --sipp-port=5088 \
  --output=storage/app/load-tests/sipp-users-wireguard.csv \
  --include-media-fixtures
```

Set `PBX_HOST` to the address where the FreeSWITCH internal Sofia profile
actually listens. The stock internal profile uses
`force-register-domain=$${domain}`, so seeding an unrelated realm such as
`load.test.local` while FreeSWITCH forces the PBX realm causes
authenticated registrations to be rejected with `403 Forbidden`. Use the
`FREESWITCH_DEFAULT_SIP_REALM` value from `.env` as the seed `--domain` in
that case.

## Test 1: Dynamic Dialplan XML (Requests Per Second)

### Running The Test

```bash
php artisan pbx:load-test:dialplan \
  --tenant=load-test-beta \
  --url=https://PBX_HOST/api/v1/xml-handler \
  --scenario=mixed \
  --requests=1000 \
  --concurrency=50 \
  --token="$FREESWITCH_XML_HANDLER_TOKEN" \
  --label="moderate-office-regression" \
  --max-failure-rate=0 \
  --max-average-ms=1000 \
  --report=storage/app/load-tests/dialplan-report.json
```

Use the real Nginx/PHP-FPM endpoint for performance measurements.
`php artisan serve` is useful for functional checks, but it is not
representative for concurrent XML handler load.

Scenarios:

- `internal`: tenant internal context, extension-to-extension destinations.
- `inbound`: tenant public context, seeded DID `15551230000`.
- `outbound`: tenant internal context, seeded `9` prefix route.
- `mixed`: round-robin internal, inbound, and outbound requests.
- `cache-hit`: repeats one exact internal dialplan lookup to isolate
  generated XML cache-hit behavior.

The command exercises the same application path FreeSWITCH reaches through
`mod_xml_curl`:

- tenant context parsing;
- standard dialplan row loading and detail eager loading;
- module dialplan contributors and enabled/disabled module checks;
- XML escaping and rendering;
- HTTP response generation.

The JSON report records:

- run ID, optional label, Git commit, and dirty-worktree status;
- load-generator environment details (hostname, OS, PHP, Laravel, cache
  store, session driver, memory limit, CPU count);
- target request count, concurrency, timeout, and scenario distribution;
- total, successful, and failed responses with success/failure rates;
- elapsed time and requests per second;
- average, fastest, slowest, median (p50), p90, p95, and p99 latency, plus complete raw timing sample counts;
- HTTP status distribution, configured thresholds, pass/fail reasons, and
  sample failures.

> [!TIP]
> **Understanding Measured Latency Metrics in Plain Terms**:
> - **Average (`avg`)**: Total elapsed time divided by request count; measures overall system work.
> - **Fastest (`min`) & Slowest (`max`)**: The absolute best and worst response times observed in the run.
> - **Median (`p50`)**: The middle response time (50% faster, 50% slower), showing typical user experience without outlier distortion.
> - **Tail Latency (`p95` & `p99`)**: How the slowest 5% and 1% of calls behaved under burst queueing.
> See the [Plain-Language Guide to Performance Metrics](load-testing-results.md#plain-language-guide-to-performance-metrics--statistics) in `load-testing-results.md` for complete definitions and real-world PBX examples.

Threshold options make small and medium repeat checks fail fast when
behavior changes unexpectedly:

- `--max-failure-rate=0` is the default and fails on any failed XML
  response.
- `--max-average-ms=1000` fails when average latency exceeds 1,000 ms.
- `--label=...` stores a human-readable run label for later comparison.

### Relevant .env Settings

Keep XML handler auth enabled for real-server testing and pass the
configured token to the load-test command. Show the effective values with:

```bash
php artisan config:show freeswitch.xml_handler
```

Shipped defaults:

```bash
FREESWITCH_XML_HANDLER_AUTH=true
FREESWITCH_XML_HANDLER_TOKEN=generated-by-installer
FREESWITCH_XML_HANDLER_LOG_REQUESTS=false
FREESWITCH_XML_HANDLER_LOG_TIMING=false
XML_CACHE_TTL=5
# XML_CACHE_DIALPLAN_TTL=5
# XML_CACHE_CONTRIBUTOR_TTL=5
# XML_CACHE_DIRECTORY_TTL=5
# XML_CACHE_ACL_TTL=5
XML_CACHE_STORE=redis
# XML_CACHE_DIALPLAN_STORE=redis
# XML_CACHE_DIRECTORY_STORE=redis
# XML_CACHE_ACL_STORE=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_CONNECTION=cache
FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED=false
FREESWITCH_HIREDIS_DIALPLAN_LIMIT_MAX=100000
FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED=false
FREESWITCH_SWITCH_LOG_LEVEL=debug
```

What each setting does:

- `FREESWITCH_XML_HANDLER_LOG_REQUESTS=false` avoids per-request success
  log I/O. Warnings and errors still log.
- `FREESWITCH_XML_HANDLER_LOG_TIMING=false` keeps per-request timing
  logging off. Enable it only for diagnostics; it adds log I/O to the path
  being measured.
- `XML_CACHE_TTL=5` acts as the master default TTL (in seconds)
  for all FreeSWITCH XML handler caches (dialplan, contributor, directory, and ACL).
  Uncomment any individual setting below if you need a granular override.
- `XML_CACHE_DIALPLAN_TTL=5` caches generated dialplan
  XML by tenant/context/destination for short bursts. Set it to `0` to
  measure fully cold generation. Control-panel changes may take up to this
  many seconds to appear in FreeSWITCH dialplan lookups.
- `XML_CACHE_CONTRIBUTOR_TTL=5` caches standard
  dialplan fragments and first-party context-wide contributor fragments by
  tenant/context, so cold misses for different destinations avoid repeated
  MariaDB reads.
- `XML_CACHE_DIRECTORY_TTL=5` caches SIP directory XML
  so repeated registration and authentication lookups avoid rebuilding the
  same user or domain XML.
- `XML_CACHE_ACL_TTL=5` caches ACL XML the same way.
- `XML_CACHE_STORE=redis` acts as the master cache store for all
  telephony XML handler caches. Granular overrides (`XML_CACHE_DIALPLAN_STORE`,
  `XML_CACHE_DIRECTORY_STORE`, `XML_CACHE_ACL_STORE`) fall back to this master store
  when not explicitly set.
- `FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED=false` means `mod_hiredis` is
  loaded, but normal local-extension calls do not use Redis-backed
  FreeSWITCH counters. Set it to `true` only when testing or enforcing
  Redis-backed FreeSWITCH call limits.
- `FREESWITCH_HIREDIS_DIALPLAN_LIMIT_MAX=100000` is the safety ceiling used
  when the optional counter is enabled for instrumentation rather than real
  throttling.
- `FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED=false` keeps the deliberate
  `hiredis_raw` marker action out of normal calls. Set it to `true` during
  diagnostics when Redis `MONITOR` should show a FreeSWITCH-written
  `pbx:mod_hiredis:last_call:*` key for each local-extension call.
- `FREESWITCH_SWITCH_LOG_LEVEL=debug` keeps detailed FreeSWITCH logs while
  the PBX is still being validated. Lower it at runtime for measured
  capacity runs (see the FreeSWITCH log-level experiments).
- Redis is the recommended default cache and session store. File cache or
  session drivers can become part of the measured latency under
  concurrency, and database-backed cache or sessions add DB traffic to the
  path being measured.
- Fresh installs should have `redis-server`, `redis-tools`, and
  `php8.5-redis` installed with `redis-server` enabled. Verify with
  `systemctl status redis-server` and `redis-cli ping`.
- After changing XML handler config, run `php artisan optimize:clear`
  followed by `php artisan optimize` before performance testing. Do not run
  `optimize:clear` while PHP-FPM is serving test traffic; workers can
  briefly fail while bootstrap cache files are rebuilt.

### Cache Optimization and Hit Rate Sweep Testing

FreeSWITCH requests dynamic dialplan routing XML through `mod_xml_curl` on
every inbound and outbound call attempt. In an uncached deployment, every call
forces Laravel to boot, resolve the tenant context, query MariaDB for enabled
extensions, IVRs, ring groups, time conditions, and feature codes, and render
the XML payload. Under concurrent call bursts, this uncached path creates a
severe database bottleneck (approximately 2.8 requests/second on modest
hardware).

TallPBX addresses this through a layered, multi-tier caching architecture in
Redis. The cache optimization test determines the optimal balance between
**data freshness** (how quickly administrative portal updates take effect) and
**burst throughput** (absorbing call storms without database saturation), while
measuring exact Redis hit rates.

#### The Layered Telephony Caching Architecture

1. **Outer Full Dialplan Cache (`XML_CACHE_DIALPLAN_TTL`, default `5`)**:
   - Caches the complete rendered FreeSWITCH dialplan XML document.
   - Cache key: `freeswitch:xml-handler:dialplan:{tenant_id}:{context}:{destination}:{version}`.
   - When a call arrives for the same destination number within the TTL window,
     Laravel serves the complete XML directly from Redis in ~10–25 ms, bypassing
     all MariaDB queries and XML rendering logic.
2. **Inner Contributor & Fragment Cache (`XML_CACHE_CONTRIBUTOR_TTL`, default `5`)**:
   - Caches static context-wide dialplan fragments (call forwards, IVR menus,
     ring groups, time conditions, conference bridges) that do not vary by
     destination number.
   - Cache keys: `freeswitch:xml-handler:dialplan-standard:{tenant_id}:{context}:{version}`
     and `freeswitch:xml-handler:dialplan-contributor:{hash}`.
   - When calls arrive for *different* destinations (resulting in an outer cache
     miss), the contributor cache still prevents 80%+ of MariaDB table reads by
     reusing the compiled static routing fragments.
3. **Directory / SIP Auth Cache (`XML_CACHE_DIRECTORY_TTL`, default `5`)**:
   - Caches SIP user credentials, auth tokens, and domain configuration.
   - Cache key: `freeswitch:xml-handler:directory:{tenant_id}:{tag_name}:{domain}:{username}:{key_value}`.
   - Absorbs repeated authentication challenges during SIP registration storms
     and inbound call setup.
4. **Automatic Cache Invalidation (`RoutingCacheVersion`)**:
   - Every dialplan and contributor cache key embeds the tenant's current routing
     version. Whenever an administrator adds, modifies, or deletes an extension,
     inbound route, outbound route, IVR, or ring group in the web panel,
     TallPBX increments `RoutingCacheVersion::increment($tenantId)`.
   - This invalidates all active dialplan caches immediately, eliminating stale
     routing without requiring manual cache flushes.

#### How To Measure Cache Hit Rates in Redis

Redis tracks operational hit and miss counts across all keyspace lookups. A
rigorous cache test records keyspace statistics immediately before and after the
test run to compute the exact hit rate percentage.

##### Method 1: Keyspace Statistics (Delta Hits / Misses)

Capture stats before the run:

```bash
HITS_BEFORE=$(redis-cli info stats | awk -F: '/keyspace_hits/ {print $2}' | tr -d '\r')
MISSES_BEFORE=$(redis-cli info stats | awk -F: '/keyspace_misses/ {print $2}' | tr -d '\r')
```

Execute the test command (e.g. `php artisan pbx:load-test:dialplan ...`), then
capture stats after the run:

```bash
HITS_AFTER=$(redis-cli info stats | awk -F: '/keyspace_hits/ {print $2}' | tr -d '\r')
MISSES_AFTER=$(redis-cli info stats | awk -F: '/keyspace_misses/ {print $2}' | tr -d '\r')

DELTA_HITS=$((HITS_AFTER - HITS_BEFORE))
DELTA_MISSES=$((MISSES_AFTER - MISSES_BEFORE))
TOTAL_OPS=$((DELTA_HITS + DELTA_MISSES))

if [ "$TOTAL_OPS" -gt 0 ]; then
  HIT_RATE=$(awk "BEGIN {printf \"%.2f\", ($DELTA_HITS / $TOTAL_OPS) * 100}")
else
  HIT_RATE="0.00"
fi

echo "Redis Hits: $DELTA_HITS | Misses: $DELTA_MISSES | Hit Rate: ${HIT_RATE}%"
```

The mathematical formula:

```text
Hit Rate (%) = (Delta keyspace_hits / (Delta keyspace_hits + Delta keyspace_misses)) * 100
```

##### Method 2: Real-Time Command Inspection (`redis-cli monitor`)

To observe cache interaction live, open a second terminal and monitor the
command stream filtered for XML handler keys:

```bash
redis-cli monitor | grep --line-buffered "xml-handler"
```

- **Cache Miss**: Shows a `GET` command followed immediately by a `SETEX` command
  caching the rendered XML with its configured TTL:
  ```text
  "GET" "tallpbx-database-tallpbx-cache-freeswitch:xml-handler:dialplan-contributor:5fbb18c5..."
  "SETEX" "tallpbx-database-tallpbx-cache-freeswitch:xml-handler:dialplan-contributor:5fbb18c5..." "5" "s:4052:\"...\""
  ```
- **Cache Hit**: Shows only the `GET` command without any corresponding `SETEX`
  write, confirming the response was served directly from memory:
  ```text
  "GET" "tallpbx-database-tallpbx-cache-freeswitch:xml-handler:dialplan:5:tenant_5_internal:2001:1"
  ```

##### Method 3: Active Key Inspection & TTLs

Inspect active cached keys and verify remaining expiration timers:

```bash
# Count active XML handler cache entries
redis-cli --scan --pattern "*xml-handler*" | wc -l

# View remaining TTL (in seconds) for a specific cached key
redis-cli ttl "$(redis-cli --scan --pattern "*xml-handler:dialplan:*" | head -n 1)"
```

#### The 5-Run Cache Sweep Procedure

The cache sweep runs 5 distinct configurations to map performance across the
entire spectrum, from bare database reads to 100% in-memory cache hits:

| Run | Name | Cache Configuration | Scenario | Target Requests | Expected Hit Rate | Purpose |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | Cold Baseline | `XML_CACHE_TTL=0` | `mixed` | 100 (c=5) | 0.0% | Measures raw MariaDB read throughput and worst-case latency with zero caching. |
| 2 | Contributor Only | `XML_CACHE_DIALPLAN_TTL=0`<br>`XML_CACHE_CONTRIBUTOR_TTL=5` | `mixed` | 100 (c=5) | 45.0% – 60.0% | Validates static fragment reuse across differing destination numbers. |
| 3 | Production Baseline | `XML_CACHE_TTL=5` | `mixed` | 100 (c=5) & 500 (c=25) | 75.0% – 85.0% | Validates the recommended production profile (5-second convergence window). |
| 4 | Call Center Profile | `XML_CACHE_TTL=30` | `mixed` | 100 (c=5) | 85.0% – 95.0% | Evaluates extended burst absorption for static, high-volume call centers. |
| 5 | Memory Hit Ceiling | `XML_CACHE_TTL=5` | `cache-hit` | 100 (c=5) | 99.0% | Isolates framework and Redis serialization ceiling (zero database queries). |

##### Execution Steps for Each Run

Before each run, apply the configuration in `.env`, clear and rebuild the
application caches, and flush the Redis database:

```bash
# Example for Run 1 (Cold Baseline):
sed -i 's/^XML_CACHE_TTL=.*/XML_CACHE_TTL=0/' .env
php artisan optimize:clear && php artisan optimize
redis-cli flushdb

# Capture baseline Redis stats
H_PRE=$(redis-cli info stats | awk -F: '/keyspace_hits/ {print $2}' | tr -d '\r')
M_PRE=$(redis-cli info stats | awk -F: '/keyspace_misses/ {print $2}' | tr -d '\r')

# Run the load test
php artisan pbx:load-test:dialplan \
  --tenant=load-test-beta \
  --url=http://127.0.0.1/api/v1/xml-handler \
  --scenario=mixed \
  --requests=100 \
  --concurrency=5 \
  --token="$FREESWITCH_XML_HANDLER_TOKEN" \
  --label="cache-sweep-run1-cold" \
  --report=storage/app/load-tests/cache-sweep-run1-cold.json

# Capture post-test Redis stats and calculate hit rate
H_POST=$(redis-cli info stats | awk -F: '/keyspace_hits/ {print $2}' | tr -d '\r')
M_POST=$(redis-cli info stats | awk -F: '/keyspace_misses/ {print $2}' | tr -d '\r')
DH=$((H_POST - H_PRE))
DM=$((M_POST - M_PRE))
RATE=$(awk "BEGIN {printf \"%.2f\", ($DH / ($DH + $DM + 0.0001)) * 100}")
echo "Run 1 Complete: $DH hits, $DM misses, ${RATE}% hit rate."
```

#### Automated Cache Sweep Script

To automate the entire 5-run sweep without manual editing, use the following
automation script (save as `scripts/run-cache-sweep.sh`):

```bash
#!/usr/bin/env bash
set -euo pipefail

# Cache Optimization & Hit Rate Sweep Runner for TallPBX
PBX_URL="${PBX_URL:-http://127.0.0.1/api/v1/xml-handler}"
TENANT="${TENANT:-load-test-beta}"
TOKEN="${FREESWITCH_XML_HANDLER_TOKEN:-}"
OUTPUT_DIR="storage/app/load-tests/cache-sweep-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUTPUT_DIR"

echo "=========================================================="
echo " Starting TallPBX Cache Optimization & Hit Rate Sweep"
echo " Target: $PBX_URL | Tenant: $TENANT"
echo " Artifacts: $OUTPUT_DIR"
echo "=========================================================="

declare -a RUNS=(
  "1|cold-baseline|0|0|mixed|100|5"
  "2|contributor-only|0|5|mixed|100|5"
  "3|prod-baseline-100|5|5|mixed|100|5"
  "4|call-center-ttl30|30|30|mixed|100|5"
  "5|memory-hit-ceiling|5|5|cache-hit|100|5"
)

printf "%-4s %-20s %-8s %-8s %-10s %-10s %-10s\n" "Run" "Name" "D-TTL" "C-TTL" "Req/sec" "Avg (ms)" "Hit Rate"
printf "%-4s %-20s %-8s %-8s %-10s %-10s %-10s\n" "----" "--------------------" "--------" "--------" "----------" "----------" "----------"

for item in "${RUNS[@]}"; do
  IFS="|" read -r num name d_ttl c_ttl scenario reqs conc <<< "$item"

  # Update configuration
  sed -i "s/^#\? \?XML_CACHE_DIALPLAN_TTL=.*/XML_CACHE_DIALPLAN_TTL=$d_ttl/" .env
  sed -i "s/^#\? \?XML_CACHE_CONTRIBUTOR_TTL=.*/XML_CACHE_CONTRIBUTOR_TTL=$c_ttl/" .env
  php artisan optimize:clear > /dev/null 2>&1
  php artisan optimize > /dev/null 2>&1
  redis-cli flushdb > /dev/null 2>&1

  # Stats before
  H_PRE=$(redis-cli info stats | awk -F: '/keyspace_hits/ {print $2}' | tr -d '\r')
  M_PRE=$(redis-cli info stats | awk -F: '/keyspace_misses/ {print $2}' | tr -d '\r')

  # Run load test
  REPORT="$OUTPUT_DIR/run${num}-${name}.json"
  php artisan pbx:load-test:dialplan \
    --tenant="$TENANT" \
    --url="$PBX_URL" \
    --scenario="$scenario" \
    --requests="$reqs" \
    --concurrency="$conc" \
    --token="$TOKEN" \
    --label="cache-sweep-${name}" \
    --report="$REPORT" > /dev/null 2>&1

  # Stats after
  H_POST=$(redis-cli info stats | awk -F: '/keyspace_hits/ {print $2}' | tr -d '\r')
  M_POST=$(redis-cli info stats | awk -F: '/keyspace_misses/ {print $2}' | tr -d '\r')
  DH=$((H_POST - H_PRE))
  DM=$((M_POST - M_PRE))
  TOT=$((DH + DM))
  RATE="0.0%"
  [ "$TOT" -gt 0 ] && RATE=$(awk "BEGIN {printf \"%.1f%%\", ($DH / $TOT) * 100}")

  # Parse JSON results
  RPS=$(grep '"requests_per_second"' "$REPORT" | awk -F': ' '{print $2}' | tr -d ',')
  AVG=$(grep '"average"' "$REPORT" | awk -F': ' '{print $2}' | tr -d ',')

  printf "%-4s %-20s %-8s %-8s %-10s %-10s %-10s\n" "$num" "$name" "${d_ttl}s" "${c_ttl}s" "$RPS" "${AVG}ms" "$RATE"
done

# Restore recommended defaults
sed -i 's/^XML_CACHE_TTL=.*/XML_CACHE_TTL=5/' .env
sed -i 's/^XML_CACHE_DIALPLAN_TTL=.*/# XML_CACHE_DIALPLAN_TTL=5/' .env
sed -i 's/^XML_CACHE_CONTRIBUTOR_TTL=.*/# XML_CACHE_CONTRIBUTOR_TTL=5/' .env
php artisan optimize:clear > /dev/null 2>&1
php artisan optimize > /dev/null 2>&1
echo "=========================================================="
echo " Cache sweep completed. Production defaults restored (TTL=5s)."
```

#### Production Recommendations and Tuning Trade-offs

- **Why 5 Seconds is the Recommended Production Default**:
  - In telephony, call traffic arrives in spikes (e.g. at the top of the hour or
    during advertising bursts). A 5-second TTL collapses 100 concurrent incoming
    calls to a single MariaDB render, while 99 calls are served instantly from
    Redis memory.
  - At the same time, 5 seconds guarantees that when an office manager changes an
    extension's call forwarding or updates an IVR destination in the web portal,
    the change is live across all FreeSWITCH calls in at most 5 seconds without
    requiring manual administrative intervention.
- **When to Use 30–60 Second TTLs**:
  - High-volume, static call centers (e.g., inbound support centers with
    hundreds of agents and static queue routes) benefit from longer TTLs (30s or
    60s). This provides a higher sustained hit rate (>90%) across rolling call
    bursts.
- **Cache Store Selection**:
  - Always keep `XML_CACHE_STORE=redis` and
    `CACHE_STORE=redis`. File-based cache drivers (`CACHE_STORE=file`) introduce
    filesystem lock contention on `storage/framework/cache/` during concurrent
    bursts, and database-backed cache drivers (`database`) defeat the purpose by
    shifting read load right back to MariaDB.

### Optimizations Already In Place

The current hot path includes these measured optimizations:

- successful XML handler debug logs are disabled by default;
- normal-path outbound legacy gateway INFO logging was removed;
- seed data points the SIPp outbound validation directly at the SIPp UAS
  listener, so app-generated outbound routing is validated without a Sofia
  gateway reload before every run;
- module registry/schema state is cached per request in
  `App\Services\ModuleState`, reducing repeated module-state queries while
  preserving next-request enable/disable behavior;
- generated dialplan XML is cached briefly in `XmlHandlerController`
  (tenant/context/destination key);
- context-wide standard dialplan fragments are cached for base dialplans
  whose XML does not vary by destination;
- opt-in context-wide contributor fragment caching in
  `DialplanXmlCollector` for first-party contributors whose output does not
  vary by destination;
- composite MariaDB indexes for the standard dialplan XML queries,
  `dialplans(tenant_id, context, enabled, order)` and
  `dialplan_details(dialplan_id, order)`;
- composite indexes for cold-path contributor queries that repeatedly read
  enabled tenant rows ordered by routing key/name/priority (call blocks,
  call flows, call forwards, conferences, feature codes, follow-me,
  inbound/outbound routes, IVRs, ring groups, time conditions, voicemail,
  and related child ordering);
- a `cache-hit` scenario so repeated exact requests can isolate generated
  XML cache-hit behavior.

Cold misses still execute contributor queries, but those reads now have
indexes that match their tenant/enabled/order access pattern. Cache hits
skip contributor generation and return the previously generated XML.

## Test 2: End-To-End Calls (Calls Per Second)

### Running The Full Runner

Basic end-to-end run:

```bash
PBX_HOST=192.168.1.76 \
PBX_PORT=5060 \
LOAD_GENERATOR_IP=192.168.1.65 \
FORCE_SEED=1 \
scripts/pbx-sipp-validate.sh
```

Expected result: the script exits `0`; `summary.md` says registration,
extension calls, and outbound calls passed with zero failures; a run
directory is created under `storage/app/load-tests/sipp-e2e-*` containing
`summary.md`, SIPp logs, and FreeSWITCH snapshots when available. A passing
basic run proves FreeSWITCH is listening, test phones can register, TallPBX
generates the call routing FreeSWITCH needs, and calls answer and hang up
cleanly.

Media-flow run (add only after the basic run passes):

```bash
PBX_HOST=192.168.1.76 \
PBX_PORT=5060 \
LOAD_GENERATOR_IP=192.168.1.65 \
FORCE_SEED=1 \
MEDIA_FLOW=1 \
scripts/pbx-sipp-validate.sh
```

Expected result: registration, recording, music-on-hold, and announcement
checks pass with zero failed calls. A passing media run proves the recording
call path works, the music-on-hold queue path works, the announcement path
plays and hangs up cleanly, and audio can flow between the two servers.
When `tcpdump` is available, the runner also saves RTP capture files.

Extended parity run:

```bash
EXTENDED=1 FORCE_SEED=1 \
  PBX_HOST=192.168.1.76 LOAD_GENERATOR_IP=192.168.1.65 \
  scripts/pbx-sipp-validate.sh
```

Expected result: ring-group, voicemail (2003), conference (2500),
call-forward, time-condition (2401), and follow-me scenarios pass. Emergency
(911) and call-block may fail because of the known limitations described
later in this guide.

The current runner defaults are deliberately small: 10 extension calls
attempted at 2 per second with no more than 5 active, followed by 5 outbound
calls attempted at 1 per second with no more than 2 active. These defaults
prove the full chain works; they do not establish capacity. A capacity claim
requires staged, repeated runs with increasing `CALL_RATE`,
`MAX_SIMULTANEOUS`, and `CALLS`, plus retained statistics and server
metrics. The safe capacity is the highest repeatable tier that keeps the
chosen success-rate and setup-time limits and leaves no stuck calls after
teardown.

An end-to-end capacity report should include:

- attempted calls per second: how quickly SIPp tried to start new calls;
- achieved calls per second: fully successful calls divided by elapsed test
  time;
- successful and failed call counts and percentages;
- average, fastest, and slowest call-setup time (first `INVITE` to `200 OK`);
- peak simultaneous active calls;
- clean teardown results, including whether channels remained stuck;
- CPU, memory, network, FreeSWITCH, PHP-FPM, MariaDB, and Redis
  observations;
- RTP loss or media errors when media is part of the scenario.

### Runner Variables

| Variable | Purpose |
| --- | --- |
| `PBX_HOST` | PBX SIP target. |
| `PBX_PORT` | PBX SIP port, usually `5060`. |
| `LOAD_GENERATOR_IP` | IP address FreeSWITCH can reach for SIPp signaling/RTP. |
| `FORCE_SEED=1` | Refresh seed data and CSV before running. |
| `SKIP_SEED=1` | Use an existing CSV without running Artisan; useful when the runner runs on a copied host. |
| `REGISTER_RATE`, `REGISTER_COUNT` | SIP REGISTER rate/count. |
| `CALL_RATE`, `MAX_SIMULTANEOUS`, `CALLS` | Extension-to-extension call rate, concurrency, count. |
| `OUTBOUND_CALL_RATE`, `OUTBOUND_MAX_SIMULTANEOUS`, `OUTBOUND_CALLS` | Outbound-route call rate, concurrency, count. |
| `MEDIA_FLOW=1` | Enable recording, MOH, and announcement media checks. |
| `MEDIA_CALL_RATE`, `MEDIA_MAX_SIMULTANEOUS`, `MEDIA_CALLS` | Media scenario rate/concurrency/count. Keep small. |
| `MEDIA_RTP_PORT` | Base RTP port for media checks. Defaults to `6000`. |
| `MEDIA_RTP_ECHO=1` | Echo RTP back to FreeSWITCH during media checks. Leave enabled for playback/announcement tests. |
| `MEDIA_CAPTURE=0` | Disable optional `tcpdump` capture. |
| `MEDIA_CAPTURE_INTERFACE` | Interface for optional RTP capture. Defaults to `any`. |
| `RUN_DIR` | Override the artifact directory. |

### Reading Run Artifacts

The runner stores artifacts under `storage/app/load-tests/sipp-e2e-*`. Open
`summary.md` first. For a healthy run it shows every scenario passed, and
the individual SIPp logs show no timeout, successful calls equal to the
requested count, and zero failed calls. For a failed run, preserve the
entire directory; the most useful files are `summary.md`, `register.log`,
`*-media.log`, `*_errors.log`, `*_messages.log`, `freeswitch-before.log`,
and `freeswitch-after.log`.

### Manual Server-To-Server Run

Most people should use the runner above. This manual path is for debugging
one step at a time when the runner fails. It assumes SIPp runs on a separate
generator host reached over SSH.

Confirm SSH to the generator host from the PBX:

```bash
ssh -i /root/.ssh/ppx2-client.rsa \
  -p 2222 \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=no \
  root@192.168.1.65 \
  'hostname -I; command -v sipp; sipp -v 2>&1 | head -5'
```

Prepare a run directory on the generator and copy the SIPp scenarios and
CSV into it:

```bash
RUN_DIR=/tmp/pbx-media-$(date +%Y%m%d-%H%M%S)

ssh -i /root/.ssh/ppx2-client.rsa -p 2222 root@192.168.1.65 "mkdir -p ${RUN_DIR}/tools/sipp"

scp -i /root/.ssh/ppx2-client.rsa -P 2222 \
  tools/sipp/register.xml \
  tools/sipp/uac-media-client-hangup.xml \
  tools/sipp/uac-media-server-hangup.xml \
  root@192.168.1.65:${RUN_DIR}/tools/sipp/

scp -i /root/.ssh/ppx2-client.rsa -P 2222 \
  storage/app/load-tests/sipp-users.csv \
  root@192.168.1.65:${RUN_DIR}/sipp-users.csv
```

Generate the SIPp authentication CSV and the media destination CSVs. The
seed command writes a plain CSV; SIPp additionally needs an authentication
macro column:

```bash
awk -F';' 'BEGIN { OFS = ";" } NR == 1 { print; next } { print $0, "[authentication username="$1" password="$2"]" }' \
  sipp-users.csv > sipp-users-auth.csv

awk -F';' -v destination='*732' 'BEGIN { OFS = ";" } NR == 1 { print; next } { print $0, "[authentication username="$1" password="$2"]", destination }' \
  sipp-users.csv > recording-media.csv

awk -F';' -v destination='load_test_moh' 'BEGIN { OFS = ";" } NR == 1 { print; next } { print $0, "[authentication username="$1" password="$2"]", destination }' \
  sipp-users.csv > moh-media.csv

awk -F';' -v destination='load_test_announcement' 'BEGIN { OFS = ";" } NR == 1 { print; next } { print $0, "[authentication username="$1" password="$2"]", destination }' \
  sipp-users.csv > announcement-media.csv
```

CSV meaning:

- `field0`: SIP auth username.
- `field1`: SIP password.
- `field2`: SIP realm/domain.
- `field3`: destination extension for normal extension tests.
- `field4`: caller ID extension.
- `field5`: SIPp authentication header injection.
- `field6`: destination for media-flow tests.

Register the seeded users from the generator host against the PBX:

```bash
timeout 120 sipp 192.168.1.76:5060 \
  -sf tools/sipp/register.xml \
  -inf sipp-users-auth.csv \
  -i 192.168.1.65 \
  -p 5066 \
  -r 5 \
  -m 20 \
  -trace_err \
  -trace_counts \
  -trace_stat \
  -fd 5
```

Passing result: exit code `0`, `Successful call` equal to the registration
count, `Failed call` equal to `0`, and a message flow of initial `401`,
authenticated retry, then `200`. Out-of-call `NOTIFY` messages after
registration are expected because FreeSWITCH may send voicemail
message-summary notifications; SIPp can report them as discarded without
failing registration.

### Media-Flow Checks

| Check | Destination | SIPp scenario | What it proves |
| --- | --- | --- | --- |
| Recording | `*732` | `uac-media-server-hangup.xml` | SIP auth works, the tenant internal context is selected, the generated feature-code dialplan matches `*732`, and FreeSWITCH records, negotiates media, and hangs up cleanly. |
| Music on hold | `load_test_moh` | `uac-media-client-hangup.xml` | `mod_callcenter` is loaded, dynamic `callcenter.conf` includes `load_test_moh@default`, the generated queue name matches the dialplan bridge target, and `local_stream://moh` is usable. |
| Announcement | `load_test_announcement` | `uac-media-server-hangup.xml` | The generated IVR dialplan and packaged prompt path expand correctly, playback completes, FreeSWITCH sends BYE, and teardown is clean. |

Media checks use SIPp RTP echo (`-rtp_echo`, plus `-mi` and `-mp`), so
FreeSWITCH playback can advance normally in this synthetic setup. Expected
result for each check: `Successful call` is `1` and `Failed call` is `0`,
with the call answered and ended by the expected side.

### Additional Tests To Add To The Campaign

Four tests are specified here but are not yet first-class runner modes.
They close the gaps the source documents themselves call out.

1. **Concurrent-call capacity test.** This guide lists "how many calls can
   remain active at the same time?" as a capacity question, but no current
   scenario measures it: the calls-per-second ladders hold calls only
   seconds and score setup speed. Ramp simultaneous calls with a longer
   hold time (for example 60–120 seconds each) until the success rate or
   setup time degrades, then record peak simultaneous calls, peak
   FreeSWITCH channels, CPU and memory, and confirm zero stuck channels
   after teardown. Run it on each Phase 2 pair after the signaling ladder.
2. **RTP-enabled media capacity test.** The signaling capacity runs carry
   no continuous audio. Add a media scenario that plays real RTP (pcap
   playback) for a fixed duration at increasing concurrency, and record
   packet loss, jitter, and the highest concurrent-call level that still
   meets the audio-quality bar. Do not use RTP echo for this test; echo
   proves media flow and negotiation, not capacity.
3. **Media-flow re-validation per profile.** The recording check failed on
   the 1 vCPU / 1 GiB profile because of a dialplan bug, and the 1 vCPU /
   2 GiB and 2 vCPU / 2 GiB profiles never re-ran media checks afterward.
   Run `MEDIA_FLOW=1` with fresh seed data on every Phase 2 pair,
   and record each scenario's pass/fail in the stage tables.
4. **Recording regression check.** No test currently asserts that `*732`
   produces a usable recording end to end. Add a step that places a call
   to `*732`, holds for at least 10 seconds, hangs up, and then verifies
   the PBX did not answer with `480` and that the recording file exists
   and is non-empty. Fold this into the media runner so it runs with every
   `MEDIA_FLOW=1` invocation.

## Results & Benchmark Data

All empirical benchmark measurements, latency statistics, cache hit-rate sweeps, and hardware sizing recommendations have been consolidated into the dedicated companion reference:

👉 **[docs/load-testing-results.md](load-testing-results.md)**

### Quick Reference: Production Hardware Sizing

For capacity planning, use this baseline matrix synthesized from the September 2026 cloud datacenter VPS stress runs:

| Profile / Tier | Recommended Hardware | PHP-FPM Profile (`www.conf`) | Cache TTL Window | Dialplan XML Throughput | Sustained Call Capacity | Active Call Ceiling | Primary Target Deployment |
| --- | --- | --- | --- | --- | ---: | ---: | --- |
| **Micro / Edge** | 1 vCPU, 1–2 GiB RAM | `pm = dynamic`<br>`pm.max_children = 5` | 5 seconds | 13–19 req/sec | 3–5 calls/sec | 20–35 concurrent | Home office, small branch (1–10 phones) |
| **Standard SMB** | 2–4 vCPU, 4 GiB RAM | `pm = static`<br>`pm.max_children = 12` | 5 seconds | 19–28 req/sec | 5–8 calls/sec | 50–100 concurrent | Small-to-medium business (10–75 phones) |
| **Mid-Market** | 4–8 vCPU, 8 GiB RAM | `pm = static`<br>`pm.max_children = 24` | 5–15 seconds | 35–50 req/sec | 12–18 calls/sec | 150–300 concurrent | Multi-department office (75–250 phones) |
| **Call Center** | 8+ vCPU, 16 GiB RAM | `pm = static`<br>`pm.max_children = 32–48` | 15–30 seconds | 60–90+ req/sec | 25–40 calls/sec | 400–800 concurrent | Queue-heavy inbound contact center |
| **Enterprise / Multi-Tenant** | 16+ vCPU, 32 GiB RAM | `pm = static`<br>`pm.max_children = 64` | 30 seconds | 100–150+ req/sec | 45–60+ calls/sec | 1,000+ concurrent | Multi-tenant cloud hosted PBX |

For full details on the single-server XML throughput ladder, the 5-tier cache sweep, the 14-scenario telephony validation suite, SIPp calls-per-second capacity ladders, and FreeSWITCH/PHP-FPM configuration experiments, consult **[docs/load-testing-results.md](load-testing-results.md)**.

## How To Look For Bottlenecks

Do not stop at the final requests/sec or calls/sec number. A result is
useful only when the run also shows what ran out first. For end-to-end
runs, treat FreeSWITCH load as the primary suspect until measurements prove
otherwise. Redis, MariaDB, and PHP-FPM tuning is useful only when it reduces
the work FreeSWITCH waits on during call setup; the goal is not to make an
isolated database benchmark faster.

| Suspect | What to look for | Likely next action |
| --- | --- | --- |
| FreeSWITCH SIP/session work | CPU busy near 95–100%, rising run queue, setup time slowing before calls fail. | Reduce unnecessary FreeSWITCH work first (lower logging, avoid debug/SIP trace, keep scenarios realistic), then add CPU or split roles. |
| FreeSWITCH debug logging | `freeswitch.log` fills with DEBUG lines during the run. | Lower the runtime log level for the measured run (see the FreeSWITCH log-level experiments). |
| Laravel XML generation | XML handler latency rises at the same time setup time rises. | Use Redis XML caches and query/index fixes only where they shorten FreeSWITCH's wait during SIP setup. |
| PHP-FPM saturation | Logs show `pm.max_children` warnings or XML latency jumps while CPU is not fully busy. | Tune `pm.max_children`, but avoid adding workers past available CPU/RAM. |
| MariaDB | Slow queries, high disk wait, or DB CPU spikes during XML lookups. | Add indexes or cache hot XML so FreeSWITCH spends less time waiting; do not treat DB speed as the main calls/sec target by itself. |
| Redis | Redis errors, high latency, or the cache store accidentally set to file/database. | Use a local Redis with PhpRedis, verify `redis-cli ping`, and compare with cache enabled and disabled. |
| SIPp/load generator | SIPp CPU high, failed sends, outbound congestion, or achieved rate below target while the PBX has headroom. | Move SIPp to a stronger/separate host, raise file descriptors/ports, or lower local logging. |
| Network/WireGuard | Retransmissions, packet loss, high RTT, or NAT/WireGuard endpoint churn. | Test from the same datacenter, or fix the tunnel/UDP path before trusting the numbers. |

Findings from empirical datacenter and lab benchmark runs:

- **Shared-CPU Bottleneck (14–16 req/sec)**: On 1-core and 2-core shared-CPU instances, dynamic XML generation hits a hard compute ceiling between 14 and 16 requests/second under burst concurrency (`500 x 25` and `1,000 x 25`). Because PHP-FPM workers compete with the Linux network stack and Sofia SIP threads for shared host CPU cycles, requests queue in buffers, pushing peak tail latency past 2.2–3.0 seconds and limiting sustained call capacity to 3–8 calls/sec.
- **Dedicated CPU Headroom (>4x Multiplier)**: Moving to 4 dedicated vCPUs with 24 pre-forked static workers completely eliminates worker starvation, multiplying throughput to 65+ req/sec, capping tail latency under 450 ms, and enabling 15–20 calls/sec sustained (bursting cleanly to 30 CPS with zero drops across 2,110 calls).
- **Memory Scaling**: Memory alone does not raise throughput on single-core instances (1 vCPU / 2 GiB performed similarly to 1 vCPU / 1 GiB), while adding dedicated compute cores provides immediate linear scaling.
- **FreeSWITCH Switch Logging**: Lowering FreeSWITCH switch logging from `debug` to `notice` reduces setup latency and prevents log-disk I/O bottlenecks during high-throughput runs.
- **FreeSWITCH `sessions-per-second`**: For high-rate SIPp runs, always configure FreeSWITCH `sessions-per-second=60` (or higher) to prevent the default safety cap (`30`) from dropping two-leg calls near 15 calls/sec.

For the current phase, keep metrics collection simple and repeatable: use
the JSON report from the XML test plus the host sampler instead of
introducing a dedicated metrics exporter. Consider Prometheus, Grafana, or
another exporter stack only when longer campaigns need continuous
dashboards or retention across many hosts.

Run the sampler on the PBX server while the XML test or SIPp is active:

```bash
INTERVAL=5 SAMPLES=120 XML_URL=http://127.0.0.1/api/v1/xml-handler scripts/pbx-load-sample.sh | tee storage/logs/pbx-load-$(date +%Y%m%d-%H%M%S).log
```

The sampler captures `fs_cli -x status`, `show channels count`, `show calls
count`, `show registrations count`, XML handler HTTP status and latency
through `curl`, MariaDB status through `mysqladmin status`, and a host
CPU/run-queue snapshot through `vmstat`. Also keep the SIPp screen output
and any SIPp CSV/stat files from the generator host.

### PHP-FPM Review And Tuning

Check active worker settings before and after changing PHP-FPM:

```bash
php-fpm8.5 -tt 2>&1 | grep -E 'pm\.max_children|pm\.start_servers|pm\.min_spare_servers|pm\.max_spare_servers|pm\.max_requests'
tail -n 50 /var/log/php8.5-fpm.log
```

The warning below means XML handler requests are queueing behind PHP-FPM
workers:

```text
server reached pm.max_children setting
```

The standard install recommendation for a 4 GB combined PBX/application
server is:

```ini
pm = static
pm.max_children = 12
```

For a lightly or moderately used 1-vCPU/1-GB server, leaving Debian's
default `dynamic` pool with `pm.max_children = 5` is also a valid operating
choice; capacity tuning is optional when the default profile meets the
installation's latency and call-volume needs. Test static worker settings
as a separately labelled optimization instead of implying that the default
configuration is unsuitable.

Keep `pm.max_requests` at the PHP-FPM default of `0` for controlled
hardware comparisons. Set a nonzero recycling interval only if sustained
monitoring shows that worker memory grows over time, and record that change
as a separate tuning variable.

The measured PHP-FPM comparisons (default against static 4/5/6 at 1 vCPU /
1 GiB and static 6/8/10/12 at 2 vCPU / 2 GiB) are in the results sections.
See `INSTALL.md` for small/standard/larger server sizing guidance.

## Troubleshooting

### Registration Failures

Likely causes: `mod_sofia` is not loaded; UDP `5060` is not listening on
the PBX address; directory XML is not resolving the tenant or SIP account;
the SIP realm/domain in the CSV does not match a seeded tenant domain; the
password does not match.

```bash
fs_cli -x 'sofia status'
ss -lunp | rg ':5060'
tail -160 /var/log/freeswitch/freeswitch.log
```

### Recording Failures

Likely causes: the feature-code dialplan did not match `*732`; the
feature-code regex is escaped incorrectly (`^\*97$`, never `^*97$`);
recording directory or the FreeSWITCH recording application has a runtime
Ensure feature codes like `*97` are properly regex-escaped (`^\*97$`, not `^*97$`) to prevent FreeSWITCH regex compilation errors. Ensure recording scenarios establish active bidirectional RTP media before session termination.

### Music-On-Hold Failures

Likely causes: `freeswitch-mod-callcenter` missing or not loaded;
`callcenter.conf` did not include no-domain queues during module load; the
queue name does not match between dialplan and callcenter configuration
(FreeSWITCH expects names such as `load_test_moh@default`); `mod_local_stream`
missing or not loaded; packaged music files missing.

```bash
fs_cli -x 'callcenter_config queue list'
fs_cli -x 'show application callcenter'
fs_cli -x 'show file'
```

### Announcement Failures

Likely causes: the IVR/announcement dialplan did not match; the prompt file
is missing; `mod_sndfile` or playback support is missing; the generated IVR
action sequence waits for input or sleeps longer than the SIPp scenario
expects; SIPp is not echoing RTP (in this lab, playback did not complete
until the media generator echoed RTP back to FreeSWITCH); the SIPp scenario
expects a server BYE but the dialplan now hangs up from the client side or
waits longer.

### Runtime Debug Commands

Use these on the PBX server while a test is running or immediately after a
failure:

```bash
fs_cli -x 'show calls'
fs_cli -x 'show channels'
fs_cli -x 'sofia status'
fs_cli -x 'callcenter_config queue list'
tail -240 /var/log/freeswitch/freeswitch.log
```

Useful XML handler checks:

```bash
curl -sS 'http://127.0.0.1/api/v1/xml-handler?token=TOKEN&section=configuration&key_name=name&key_value=sofia.conf'
curl -sS 'http://127.0.0.1/api/v1/xml-handler?token=TOKEN&section=configuration&key_name=name&key_value=callcenter.conf'
curl -sS 'http://127.0.0.1/api/v1/xml-handler?token=TOKEN&section=configuration&key_name=name&key_value=ivr.conf'
```

When a call gets stuck:

```bash
fs_cli -x 'show calls'
fs_cli -x 'uuid_kill UUID NORMAL_CLEARING'
```

### Quick Failure Map

| Symptom | Meaning | Fix before testing capacity |
| --- | --- | --- |
| `sofia status` shows `0 profiles 0 aliases` | SIP is not listening; FreeSWITCH cannot register or receive calls. | Seed the SIP profiles and/or remove `configuration` from the XML curl binding for this lab, then restart FreeSWITCH. |
| REGISTER times out before `401` | The SIP listener/profile is not ready or not reachable. | Confirm the `internal` profile is `RUNNING` and UDP `5060` is listening. |
| REGISTER gets `403 Forbidden` | The SIPp authentication CSV is missing or wrong, or the PBX has no matching SIP users. | Recreate the load-test data and regenerate the authentication CSV. |
| Log says `Can't find user [2000@...]` | The seed data does not match the SIP realm/user in the CSV. | Re-run the seed with the matching domain and copy the CSV again. |
| First call fails with `503 NORMAL_TEMPORARY_FAILURE` and the UAS saw a `NOTIFY` | The SIPp answer side exited because a voicemail `NOTIFY` arrived before the `INVITE`. | Wait about 8 seconds after registration before starting the UAS. |
| Calls fail with `mod_xml_curl` timeout | The test is valid and the PBX is waiting too long for XML curl responses. | Investigate Laravel/PHP-FPM/XML handler timing; do not blame SIPp until UDP errors or generator CPU prove it. |

## SIPp Load Testing Preflight & Recovery Runbook

Use this runbook before any call-rate run from the generator
host. It exists so the lab environment does not need to be rediscovered after every
reboot, FreeSWITCH restart, test runner restart, or interrupted SIPp
process. The example values below use the standard test credentials
(`load-test-beta`, extension `2000`, password `LoadTest1234`, realm
`<PBX_IP>`); substitute your target IP and test data values where they appear.

Standard lab parameters:

- PBX / FreeSWITCH / Laravel: `<PBX_IP>` (e.g. `x.x.x.200`)
- SIPp generator host: `<GENERATOR_IP>` (e.g. `x.x.x.173`)
- SIPp UAS answer port: `5066`
- SIP realm used by this harness: `<PBX_IP>`

### 1. Run The Mandatory Preflight Gate

Do this before every call-rate test. Reboots, database refreshes,
interrupted runs, app key resets, and manual seed resets can remove or
invalidate the synthetic SIP accounts. If this preflight fails, reseed
first and regenerate the generator's auth CSV.

On the PBX, verify the test user, domain, and password without printing the
password:

```bash
cd /var/www/tallpbx

php artisan tinker --execute '
use Modules\SipAccounts\Models\SipAccount;

$account = SipAccount::withoutGlobalScopes()
    ->where("auth_username", "2000")
    ->first();

echo json_encode([
    "exists" => $account !== null,
    "enabled" => $account?->enabled,
    "realm_ok" => $account?->tenantDomain?->domain !== null,
    "password_ok" => $account?->auth_password === "LoadTest1234",
], JSON_PRETTY_PRINT).PHP_EOL;
'
```

Expected result:

```json
{
    "exists": true,
    "enabled": true,
    "realm_ok": true,
    "password_ok": true
}
```

On the generator host, verify the auth CSV header and first data row:

```bash
ssh root@<GENERATOR_IP> \
  'cd /root/pbx-sipp-validation && \
   head -n 2 storage/app/load-tests/sipp-users-auth.csv'
```

Expected output:

```text
SEQUENTIAL
2000;LoadTest1234;<PBX_IP>;2001;2000;[authentication username=2000 password=LoadTest1234]
```

If any PBX value is false, or the CSV first line is not exactly
`SEQUENTIAL`, perform sections 4 and 5 before starting SIPp. A missing
account or malformed auth CSV causes immediate `403 Forbidden` failures,
which are setup failures and not capacity results.

### 2. Clear Stale SIPp And FreeSWITCH Runtime State

Stop any SIPp processes left behind by an interrupted run:

```bash
ssh root@<GENERATOR_IP> \
  'pids=$(pgrep -x sipp || true); if [ -n "$pids" ]; then kill $pids || true; fi'
```

Do not use `pkill -f /usr/local/bin/sipp` inside the SSH command string:
the pattern can match the SSH command itself and kill the shell before the
test starts.

Restart FreeSWITCH and confirm it is idle:

```bash
systemctl restart freeswitch
sleep 8
fs_cli -x 'show calls count'
fs_cli -x 'show channels count'
fs_cli -x 'show registrations count'
```

Expected before a fresh run: `0 total` calls, `0 total` channels, and
`0 total` registrations.

### 3. Confirm Sofia Is Really Listening

After a reboot or FreeSWITCH restart, do not assume SIP is ready:

```bash
fs_cli -x 'sofia status'
ss -lunp | grep -E ':5060|:5080'
```

Expected: the `internal` profile is `RUNNING` and UDP `5060` is listening.
If `sofia status` shows `0 profiles 0 aliases`, SIPp registrations cannot
work. The fastest lab recovery is:

```bash
sed -i 's/bindings="directory|dialplan|configuration"/bindings="directory|dialplan"/' \
  /etc/freeswitch/autoload_configs/xml_curl.conf.xml
systemctl restart freeswitch
sleep 8
fs_cli -x 'sofia status'
```

Why this matters: if XML curl is bound to `configuration`, FreeSWITCH may
ask Laravel for `sofia.conf` during startup. That is only safe when the
database has enabled SIP profile records and the generated configuration
includes them. If the database was refreshed, reseeded incorrectly, or has
no SIP profiles, Sofia starts with no profiles and there is no SIP listener
for SIPp to test. This does not bypass XML curl for the call test itself;
directory and dialplan lookups still go through `/api/v1/xml-handler`.

### 4. Recreate The PBX Load-Test Data

If registrations fail with messages like `Can't find user
[2000@<PBX_IP>]`, the test tenant/SIP accounts are missing or
mismatched. Recreate them on the PBX:

```bash
cd /var/www/tallpbx

php artisan pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain=<PBX_IP> \
  --extensions=20 \
  --start=2000 \
  --password='LoadTest1234' \
  --sipp-host=<GENERATOR_IP> \
  --sipp-port=5066 \
  --output=storage/app/load-tests/sipp-users.csv \
  --reset \
  --no-interaction

php artisan optimize:clear
php artisan optimize
```

Expected PBX data after seeding:

```bash
php artisan tinker --execute 'echo "tenants=".App\Models\Tenant::count()."\n"; echo "sip_accounts=".Modules\SipAccounts\Models\SipAccount::withoutGlobalScope("tenant")->count()."\n"; echo "sip_profiles=".Modules\SipProfiles\Models\SipProfile::withoutGlobalScope("tenant")->count()."\n";'
```

```text
tenants=1
sip_accounts=20
sip_profiles=2
```

### 5. Copy The CSV To The Generator And Add SIPp Authentication

The seed command writes a plain CSV. The SIPp UAC/register scenarios also
need an authentication macro column:

```bash
scp /var/www/tallpbx/storage/app/load-tests/sipp-users.csv \
  root@<GENERATOR_IP>:/root/pbx-sipp-validation/storage/app/load-tests/sipp-users.csv

ssh root@<GENERATOR_IP> \
  'cd /root/pbx-sipp-validation && \
   awk -F";" 'NR==1{print;next}{print $0 ";[authentication username=" $1 " password=" $2 "]"}' \
     storage/app/load-tests/sipp-users.csv \
     > storage/app/load-tests/sipp-users-auth.csv'
```

Expected first data row:

```text
SEQUENTIAL
2000;LoadTest1234;<PBX_IP>;2001;2000;[authentication username=2000 password=LoadTest1234]
```

Without the final authentication column, SIPp receives `407` and then sends
a second `INVITE` or `REGISTER` without usable credentials. That produces
misleading `403 Forbidden` failures that are test setup failures, not PBX
capacity failures.

### 6. Register Users, Then Let Voicemail NOTIFY Traffic Drain

Register the 20 users from the generator host:

```bash
ssh root@<GENERATOR_IP> \
  'cd /root/pbx-sipp-validation && \
   /usr/local/bin/sipp <PBX_IP> \
     -sf tools/sipp/register.xml \
     -inf storage/app/load-tests/sipp-users-auth.csv \
     -i <GENERATOR_IP> \
     -p 5066 \
     -r 20 \
     -m 20 \
     -l 20 \
     -trace_err \
     -error_file storage/app/load-tests/register-errors.log'
```

Expected result: `20` successful registrations, `0` failed, and
FreeSWITCH `show registrations count` returns `20 total`.

Then wait about 8 seconds before starting the SIPp answer-side UAS.
FreeSWITCH often sends voicemail message-summary `NOTIFY` packets
immediately after registration. If the UAS starts too soon it can receive a
`NOTIFY` before the test `INVITE`, treat it as unexpected, exit, and cause
the real call to fail with `503 NORMAL_TEMPORARY_FAILURE`.

### 7. Prove One Complete Call Before Any Capacity Run

Do not run a capacity ladder until this one-call proof succeeds:

```bash
ssh root@<GENERATOR_IP> \
  'cd /root/pbx-sipp-validation && \
   OUT=storage/app/load-tests/capacity/one-call-proof && \
   mkdir -p "$OUT" && \
   /usr/local/bin/sipp -sf tools/sipp/uas-auto-answer-capacity.xml \
     -i <GENERATOR_IP> -p 5066 -m 1 \
     -trace_err -error_file "$OUT/uas_errors.log" \
     <PBX_IP> > "$OUT/uas_stdout.log" 2>&1 & \
   uas=$!; sleep 1; \
   /usr/local/bin/sipp <PBX_IP> \
     -sf tools/sipp/uac-extension-capacity.xml \
     -inf storage/app/load-tests/sipp-users-auth.csv \
     -i <GENERATOR_IP> -p 5158 -r 1 -m 1 -l 1 \
     -trace_err -error_file "$OUT/uac_errors.log" \
     > "$OUT/uac_stdout.log" 2>&1; \
   rc=$?; wait "$uas" >/dev/null 2>&1 || true; \
   echo "exit=$rc"; grep -E "Successful call|Failed call" "$OUT/uac_stdout.log"; \
   exit "$rc"'
```

Expected: UAC shows `Successful call | 1` and `Failed call | 0`, and the
UAS error log has no unexpected SIP message except the harmless epoll
cleanup warning SIPp sometimes prints at shutdown. Only after this proof
passes should a capacity result be interpreted.

### 8. Run The Short Clean Diagnostic

A quick repeat check after the lab has been recovered:

```bash
ssh root@<GENERATOR_IP> \
  'cd /root/pbx-sipp-validation && \
   OUT=storage/app/load-tests/capacity/xmltiming-8cps-80calls-clean-$(date +%Y%m%d-%H%M%S) && \
   mkdir -p "$OUT" && \
   /usr/local/bin/sipp -sf tools/sipp/uas-auto-answer-capacity.xml \
     -i <GENERATOR_IP> -p 5066 -m 80 \
     -trace_err -trace_msg \
     -message_file "$OUT/uas_messages.log" \
     -error_file "$OUT/uas_errors.log" \
     <PBX_IP> > "$OUT/uas_stdout.log" 2>&1 & \
   uas=$!; sleep 1; \
   /usr/local/bin/sipp <PBX_IP> \
     -sf tools/sipp/uac-extension-capacity.xml \
     -inf storage/app/load-tests/sipp-users-auth.csv \
     -i <GENERATOR_IP> -p 5180 \
     -r 8 -m 80 -l 40 \
     -trace_stat -trace_err -trace_msg \
     -stf "$OUT/uac_stats.csv" \
     -message_file "$OUT/uac_messages.log" \
     -error_file "$OUT/uac_errors.log" \
     > "$OUT/uac_stdout.log" 2>&1; \
   rc=$?; \
   for i in 1 2 3 4 5; do kill -0 "$uas" 2>/dev/null || break; sleep 1; done; \
   kill -TERM "$uas" 2>/dev/null || true; wait "$uas" >/dev/null 2>&1 || true; \
   echo "exit=$rc"; grep -E "Call Rate|Successful call|Failed call|UDP errors" "$OUT/uac_stdout.log"; \
   echo "artifacts=$OUT"; exit "$rc"'
```

The short diagnostic proof verifies that SIP INVITE dialogs complete with zero SIPp UDP send/receive/congestion errors. Treat short test runs as a diagnostic check, not a replacement for the full staged ladder.

## Artifacts

The runner and manual tests produce these files. Keep them when reporting
failures; the SIPp screen output alone is often not enough, and the
FreeSWITCH log tells whether the app-generated dialplan matched and which
FreeSWITCH application was executing.

- `summary.md`: human-readable run summary. Open this first.
- `register.log` and `register_*_errors.log`: registration output and
  discarded/unexpected messages.
- `*_counts.csv`, `*_stats.csv`, `uac_stats.csv`: SIPp counters and timings
  over time.
- `*_messages.log`: SIP message traces when `-trace_msg` is enabled.
- `recording-media.log`, `moh-media.log`, `announcement-media.log`: media
  scenario outputs.
- `freeswitch-before.log`, `freeswitch-after.log`: full-run FreeSWITCH
  snapshots when available.
- `*.rtp.pcap`: optional RTP captures when `tcpdump` capture is enabled.
- The XML test JSON report: run ID, label, Git commit and dirty state,
  generator environment, target counts, success/failure rates, elapsed
  time, requests per second, average/fastest/slowest latency with sample
  counts, HTTP status distribution, thresholds, and sample failures.

## Extended Parity Scenarios

All eight extended parity scenarios were validated against the live PBX. They cover every context-wide dialplan contributor module not already exercised by the basic runner:

| Scenario | Destination | SIPp XML | Result |
| --- | --- | --- | --- |
| Ring group (simultaneous, 3 members) | `2400` | `tools/sipp/uac-ring-group.xml` | Passed |
| Voicemail mailbox access | `2003` | `tools/sipp/uac-voicemail.xml` | Passed |
| Conference bridge | `2500` | `tools/sipp/uac-conference.xml` | Passed |
| Unconditional call forward | `2001` to `2000` | `tools/sipp/uac-call-forward.xml` | Passed |
| Time condition (always-match) | `2401` | `tools/sipp/uac-time-condition.xml` | Passed |
| Follow-me forwarding | `2002` | `tools/sipp/uac-follow-me.xml` | Passed |
| Emergency (911) routing | `911` | `tools/sipp/uac-emergency.xml` | Passed |
| Call block (blocked caller ID) | any | `tools/sipp/uac-call-block.xml` | Passed |

The extended scenarios rely on the `--include-extended-fixtures` seed data:

```bash
php artisan pbx:load-test:seed \
  --include-extended-fixtures \
  --domain=192.168.1.76 \
  --sipp-host=192.168.1.65 \
  --sipp-port=5088
```

This creates ring group 2400, voicemail mailbox 2003, conference 2500, call
forward 2001 to 2000, time condition 2401, follow-me for 2002, emergency
configuration, a call block rule, and an `*98` voicemail feature code.

### Bugs Discovered and Resolved During Parity Testing

Extended parity validation revealed several FreeSWITCH dialplan and bridging bugs that were resolved prior to release:

| Bug Discovered | Impact | Resolution / Files Changed |
| --- | --- | --- |
| Ring group bridged to DB UUIDs instead of extension numbers | SIP `480 Temporarily Unavailable` on ring group calls | Updated `RingGroupService.php` and `RingGroupExtension.php` to bridge to numerical destinations. |
| Context-wide contributor extensions placed after greedy `local_extension` catch-all | Feature codes (`*732`, `*98`) were shadowed by local extension pattern | Reordered dialplan compilation in `XmlHandlerController.php` so contributor rules evaluate with higher priority. |
| Time condition had no `destination_number` constraint | Acted as a global unconditional redirect for all calls | Added explicit destination pattern constraint in `TimeConditionService.php`. |
| `user/` channel type unsupported in FreeSWITCH bridge | FreeSWITCH returned `CHAN_NOT_IMPLEMENTED` | Switched to `loopback/` channel format in `RingGroupService.php` and `FollowMeService.php`. |
| `sip_from_uri` empty post-authentication | Call block rule could not identify caller ID on incoming INVITE | Switched to `orig_caller_id_number` channel variable export in `XmlHandlerController.php` and `CallBlockService.php`. |
| Feature-code log markers shadowed real feature dialplans (`continue=false`) | Single-leg stereo recording failed to start | Fixed execution order in `XmlHandlerController.php` and `recording-start` dialplan. |
| Call forward condition matched extension UUID instead of number | Call forward bridge failed without tenant context | Corrected destination matching in `CallForwardService.php` and `XmlHandlerController.php`. |

## Per-Run Record, Staged Tiers, And Stop Conditions

### Staged Run Tiers (XML Test)

Start small and increase only after the prior tier is stable:

| Tier | Dialplan Requests | Concurrency | Goal |
| --- | ---: | ---: | --- |
| Baseline | 25 | 1 | Confirm XML handler health and report output. |
| Small office burst | 100 | 5 | Confirm correct contexts, auth, XML shape, and no failed responses. |
| Moderate office burst | 500 | 10–25 | Catch PHP-FPM, MariaDB, Redis, and contributor problems that appear under practical load. |
| Optional medium stability | 1,000 | 25 | Confirm a longer small/medium burst stays stable after meaningful changes. |

Avoid heavier tiers such as `10,000 x 100` on the test VM unless the goal is
deliberately destructive stress testing. For real capacity claims, repeat
the harness on representative hardware and record the server shape.

Stop escalation when any of these appear:

- XML handler latency spikes or returns non-2xx;
- MariaDB shows lock or connection pressure;
- Laravel CPU, memory, queue, or PHP-FPM workers saturate;
- generator CPU or network saturation appears.

### Per-Run Record

Record this after each run:

- date/time and Git commit;
- PBX server and generator hardware/VM size;
- hardware profile, repetition number, and controlled comparison series;
- provider/region, instance identity, and whether the VPS was resized or
  replaced;
- network path and pre-run round-trip latency;
- Laravel/PHP-FPM/Nginx versions;
- seed command options;
- load command and scenario (or SIPp tiers);
- targets, achieved requests/sec or calls/sec, failed responses;
- average/fastest/slowest latency;
- application and PHP worker observations, MariaDB observations;
- suspected bottleneck and the next recommended tier or fix.

## Recommended Use

For routine development:

1. Run focused Pest tests for XML handler and contributor changes.
2. Run `php artisan app:test --smoke`.
3. Run the dialplan XML load test for performance-sensitive changes.
4. Run SIPp basic end-to-end validation before beta handoff.
5. Run SIPp extended parity validation before promoting a module from
   `partial` to `complete` in the parity audit.
6. Run `MEDIA_FLOW=1` only when changing recording, MOH, announcements,
   SIP/RTP behavior, or FreeSWITCH install defaults.

For beta or release candidates:

1. Verify a fresh install or installer re-run.
2. Reboot the PBX server.
3. Confirm `sofia status` and `callcenter_config queue list`.
4. Run the basic SIPp test.
5. Run the media-flow SIPp test.
6. Archive the SIPp artifact directory with the build or release notes.

## Known Limitations

- Generator-host networking (for example WSL2) can behave differently from
  a separate Linux VM on the LAN.
- The basic and media runners are low-volume by design and are intended for
  correctness, not capacity claims.
- SIPp RTP echo confirms media negotiation and packet flow, not audio
  quality.
- Direct SIPp load mostly stresses FreeSWITCH and network behavior; it does
  not isolate Laravel XML handler performance the way the XML test does.
- For high-rate SIPp capacity runs, confirm FreeSWITCH `sessions-per-second`
  before interpreting failures. The project default is `60`; the stock
  FreeSWITCH default of `30` can reject two-leg extension calls near 15
  offered calls/sec with `SIP/2.0 503 Maximum Calls In Progress`.
- The XML test intentionally isolates Laravel dialplan XML generation from
  FreeSWITCH SIP/media handling; its requests/sec result does not say how
  many real calls per second the whole PBX can establish.
- CI includes a tiny seeded XML handler smoke check
  (`PbxXmlHandlerSmokeTest`) that validates internal, inbound, outbound, and
  generated-cache dialplan responses without SIPp or high-volume traffic.
- Feature-specific XML validation covers call recording start/stop dialplan
  actions, local-stream music-on-hold configuration, and IVR announcement
  playback. Optional `MEDIA_FLOW=1` SIPp validation covers low-volume live
  RTP checks for those paths. Default prompt and MOH assets come from
  FreeSWITCH sound packages; app-managed recording and voicemail media
  remains file-backed with database paths and metadata only.
- The dialplan XML cache introduces a short propagation delay for
  control-panel changes, controlled by
  `XML_CACHE_DIALPLAN_TTL` (or default `XML_CACHE_TTL`).
- Emergency (911) routing bridges to
  `sofia/external/911@<gateway-host>:<gateway-port>` using the emergency
  gateway's host and port from the database. The bridge executes correctly
  in FreeSWITCH, but SIPp scenario success depends on the gateway UAS
  (port 5088) being alive when the emergency scenario runs.
- Call block testing (`tools/sipp/uac-call-block.xml`) verifies that
  calls with a blocked From user (`15550000123`) are matched against the block
  rules and rejected with `603 Decline`. The scenario sends an immediate `ACK`
  upon receiving `603` to cleanly terminate the SIP transaction without
  unnecessary retransmissions.

## References

- SIPp CSV injection: https://sipp.readthedocs.io/en/latest/scenarios/inject_from_csv.html
- SIPp statistics: https://sipp.readthedocs.io/en/v3.6.1/statistics.html
- FreeSWITCH `mod_xml_curl`: https://developer.signalwire.com/freeswitch/integration/xml-curl/
- FreeSWITCH core settings: https://developer.signalwire.com/freeswitch/configuration/core-settings
- WireGuard NAT and firewall traversal guidance: https://www.wireguard.com/quickstart/#nat-and-firewall-traversal-persistence
