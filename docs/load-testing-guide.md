# SIP Load Testing Guide

Draft merged guide (September 22, 2026). This document combines
`docs/call-simulation-load-testing.md` and
`docs/sipp-server-to-server-validation.md` into one streamlined guide for
review. The two source documents remain unchanged until this merged version
is approved.

The measurements in this draft were collected July 16–18, 2026 on the local
test server and a remote datacenter VPS series, with a public VPS
validation on August 31, 2026. They are kept as historical references for
review. The planned test campaign will create entirely new test data from
scratch and re-run the full hardware progression, replacing these tables
with fresh measurements.

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

## How This Document Is Organized

The order is intentional and matches how the work is done:

1. What the two tests measure, how to read the numbers, the hardware
   progression, and the campaign plan (what gets tested, in which order,
   and who orchestrates it).
2. Lab setup, seed data, and how to run each test.
3. Results, following the hardware progression: single-server bottleneck
   tests for VirtualBox and the datacenter ladder, then server-to-server
   call tests for the VirtualBox pair and the datacenter pair. Each results
   section includes its configuration experiments (PHP-FPM, FreeSWITCH log
   levels) in place.
4. Sizing guidance for administrators, bottleneck hunting for developers,
   troubleshooting, the lab recovery runbook, and reference material.

The results form one contiguous region of this document. When a new test
campaign runs, update the tables inside the results sections in place; the
explanation and setup sections do not change.

## The Two Tests

There are exactly two tests. They answer two different questions and their
results must never be mixed up.

1. **Dynamic Dialplan XML (requests per second).** Measures how quickly
   Laravel can generate call-routing XML. One "request" is one HTTP question
   sent to the application, not a phone call. Tool:
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
   between two machines: one PBX server under test and one SIPp load
   generator. For VirtualBox-class testing this means two virtual servers of
   the same shape; for datacenter testing it means two datacenter virtual
   servers.

The campaign executes the VirtualBox work first: the single-server tests,
then the server-to-server pair, before any datacenter stage. The "Campaign
Plan" section below defines the setups, roles, execution order, and gates;
the fresh-install procedure lives in "Fresh Install Validation (Install
Script Test)". This document always presents single-server results for
VirtualBox and then the datacenter ladder first, followed by server-to-server
results for VirtualBox and then the datacenter ladder.

**Phase 1 — single-server bottleneck testing (requests per second):**

| ID | Environment | Specification | Status |
| --- | --- | --- | --- |
| A1 | VirtualBox test server | 4 vCPU (12th Gen Intel i5-1235U), 3.8 GiB RAM, 2.0 GiB swap, Debian 13 | Complete (July 15–16, 2026); fresh install planned for the campaign |
| B1 | Shared-CPU Datacenter VPS | 1 vCPU, 967 MiB RAM, 2.0 GiB swap | Complete (July 17–18, 2026); revalidated August 31, 2026 |
| B2 | Shared-CPU Datacenter VPS | 1 vCPU, 1973 MiB RAM, 2.0 GiB swap | Complete (July 18, 2026) |
| B3 | Shared-CPU Datacenter VPS | 2 vCPU, 1973 MiB RAM, 2.0 GiB swap | Complete (July 18, 2026) |
| C1 | Dedicated-CPU Datacenter VPS | 2 vCPU, 8 GiB RAM | Planned |
| C2 | Dedicated-CPU Datacenter VPS | 4 vCPU, 8 GiB RAM | Planned |

**Phase 2 — server-to-server call testing (calls per second):**

| ID | PBX under test | SIPp load generator | Status |
| --- | --- | --- | --- |
| A1 | VirtualBox test server (4 vCPU / 4 GiB / 2 GiB swap) | A2 — orchestration and SIPp source server (`192.168.1.76`) on the same Windows 11 hardware; the historical runs used the WSL2 host at `192.168.1.65` | Complete: correctness (July 16, 2026) and capacity ladder (July 17–18, 2026) |
| B1 | Shared-CPU Datacenter VPS 1 vCPU / 967 MiB | D — second datacenter server; historical runs used the local test server through WireGuard | Complete: correctness only (July 17, 2026) |
| B3 | Shared-CPU Datacenter VPS 2 vCPU / 1973 MiB | D — second datacenter server; historical runs used the local test server through WireGuard | Complete: capacity runs (July 18, 2026) |
| C1 | Dedicated-CPU Datacenter VPS 2 vCPU / 8 GiB | D — second datacenter server in the same datacenter | Planned |
| C2 | Dedicated-CPU Datacenter VPS 4 vCPU / 8 GiB | D — second datacenter server in the same datacenter | Planned |

Server-to-server topology:

- VirtualBox testing runs two virtual Linux servers on the same hardware
  (A1 as the PBX under test, A2 as the orchestration and source server);
  that hardware runs the Windows 11 operating system.
- Datacenter testing runs the two virtual servers in the same datacenter.
- The PBX target virtual server runs on a shared-CPU plan for the 1 vCPU and
  2 vCPU tests with up to 2 GiB RAM and on a dedicated-CPU server for the
  8 GiB profiles (2 vCPU and 4 vCPU).

Notes for both phases:

- Keep the provider, datacenter region, public IP, disk, operating system,
  application commit, seed data, and generator hosts constant across the
  datacenter stages so CPU and memory are the primary variables.
- Record whether each size was an in-place resize or a replacement server,
  plus the provider CPU model/class, disk type, and region.
- The historical VirtualBox stages used a local/LAN endpoint while the
  datacenter stages cross the WAN. Record idle round-trip latency before
  every measured run; raw latency values include WAN latency and are not a
  pure CPU/RAM comparison between the two environments.
- After each resize, reboot and confirm the new values with `lscpu`,
  `free -h`, and `swapon --show` before running the staged tiers.

## Campaign Plan

The campaign executes the VirtualBox work first (single-server tests, then
the server-to-server pair) before any datacenter stage, and every run uses
new test data created from scratch. This section defines the campaign
setups, roles, and order; the fresh-install procedure is in "Fresh Install
Validation (Install Script Test)" in Test Lab Setup.

### Campaign Setups

| ID | Role | Notes |
| --- | --- | --- |
| A1 | PBX under test | Freshly installed VirtualBox server; specifications and status in the Phase 1 table. |
| A2 | Orchestration and SIPp source server | `192.168.1.76`; prepares freshly installed servers over SSH, seeds test data, builds authentication CSVs, and acts as the caller side for the VirtualBox pair. |
| B1–B3 | PBX targets | Shared-CPU datacenter VPS; specifications and status in the Phase 1 table. |
| C1–C2 | PBX targets | Dedicated-CPU datacenter VPS; specifications and status in the Phase 1 table. |
| D | SIPp load generator | Second datacenter server used for the datacenter pairs. |

### Orchestration Server (A2, `192.168.1.76`)

The orchestration server coordinates the campaign:

- SSH into each freshly installed server after the installer has been run
  manually there, and prepare it for testing: seed the new test data,
  verify the runtime state, and run the preflight checks.
- Copy the seed CSVs back from each PBX and build the SIPp authentication
  CSVs.
- Act as the SIPp source server (caller side) for the VirtualBox pair.
- Start runs, collect artifacts, and record results.

For the VirtualBox pair, both virtual Linux servers run on the same Windows
11 hardware: A1 is the PBX under test and A2 is the caller side.

### Execution Order

1. Install A1 from scratch by running `scripts/install.sh` manually on the
   new VM, then complete the fresh-install validation checklist from the
   orchestration server over SSH.
2. Create the new test data (seed on A1) and copy the CSVs back to the
   orchestration server.
3. **VirtualBox single-server tests** — the XML requests/sec ladder on A1,
   generated from the orchestration server.
4. **VirtualBox server-to-server tests** — the pair between A1 (PBX under
   test) and A2 as the SIPp source.
5. Datacenter shared-CPU single-server ladder — B1, then B2, then B3.
6. Datacenter dedicated-CPU single-server ladder — C1, then C2.
7. Datacenter server-to-server pairs — B1 pair (correctness), B3 pair
   (capacity), then C1 and C2 pairs (full matrix).
8. Refresh the results tables in this guide with the new measurements, and
   record installer findings in the changelog.

Do not start datacenter work until the VirtualBox single-server and
server-to-server results are recorded. Do not run any test on A1 until the
fresh-install validation checklist passes.

### Per-Setup Test Matrix

| Stage | Setup | Tests | Experiments |
| --- | --- | --- | --- |
| 1 | A1 (VirtualBox single-server) | XML tiers: `25 x 1` warm-up, `100 x 5`, `500 x 10`, `500 x 25`, optional `1,000 x 25`; three repetitions; PBX sampler running | None |
| 2 | VirtualBox pair (A1 + A2) | Correctness: basic runner, `MEDIA_FLOW=1`, `EXTENDED=1`; then the calls/sec ladder; then the additional campaign tests (concurrent-call capacity, RTP media capacity, media-flow re-validation including the recording regression) | FreeSWITCH log level (`debug` vs `notice`); PHP-FPM profile |
| 3 | B1, B2, B3 (shared-CPU single-server) | XML tiers, three repetitions each | PHP-FPM static worker sweep on B3 |
| 4 | C1, C2 (dedicated-CPU single-server) | XML tiers, three repetitions each | PHP-FPM worker sweep per profile |
| 5 | Datacenter pairs (B1, B3, C1, C2) | B1 pair: correctness only. B3, C1, and C2 pairs: correctness, then the calls/sec ladder, then the additional campaign tests | FreeSWITCH log level and PHP-FPM per pair |

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

- The fresh-install validation checklist must pass fully before any test on
  A1.
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
- Configure SSH access from A2 to A1 and the datacenter servers (keys and
  ports), and record the SSH endpoints with the campaign notes.
- Decide the exact VM networking mode for the VirtualBox pair (bridged or
  host-only) and record it, so results stay comparable across re-runs.
- Capture idle round-trip latency before every remote run.

## Test Lab Setup

### Machines And Roles

| Role | Host | Notes |
| --- | --- | --- |
| PBX server | `192.168.1.76` (test server) | Debian 13. Runs Laravel, Nginx/PHP-FPM, MariaDB, Redis, and FreeSWITCH. The historical validation ran on a VirtualBox VM with 4 vCPU, 3.8 GiB RAM, and 2.0 GiB swap. |
| SIPp load generator | `192.168.1.65` | WSL2 on a Windows 11 workstation, reached from the PBX server over SSH port `2222`. Later runs also used a separate Debian host as the generator and WireGuard peer. |
| Datacenter PBX under test | `x.x.x.218` | Public VPS used for the 1c/1g, 1c/2g, and 2c/2g stages through WireGuard. |
| SIP signaling | PBX `5060` | FreeSWITCH internal Sofia profile. |
| SIPp local ports | `5066`, `5070`, `5072`, `5074+` | Separate ports prevent one scenario from colliding with another. |
| SIPp RTP ports | `6000`, `6002`, `6004+` | Media-flow scenarios use RTP echo with SIPp `-mi` and `-mp`. |

For repeatable results, run SIPp from a separate Linux host or VM instead of
the PBX server itself. That keeps the test caller away from the PBX and
closer to how real phones or trunks behave. Server-to-server call testing
therefore always uses two machines: for VirtualBox testing, two virtual
Linux servers running on the same hardware (a machine running the Windows 11
operating system); for datacenter testing, two virtual servers running in
the same datacenter. The HTTP generator for the XML test may run on the
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

The campaign installs the VirtualBox PBX (A1) from scratch to validate
`scripts/install.sh` end to end, since the installer has not been exercised
on a clean machine recently. The installer is re-runnable and never deletes
existing data, so it is run twice: once on the clean machine and again to
confirm idempotency. Usage: `./install.sh` prompts for demo data and
development packages; use `./install.sh --no-demo` on a test server (add
`--no-development` unless development tooling is needed there).

1. Start from a clean Debian 13 VM matching the A1 specification in the
   Phase 1 table, with the repository checked out under the documented
   path.
2. Take a VM snapshot.
3. Run the installer manually per `INSTALL.md`, recording total duration
   and any warnings in the campaign log. The orchestration server (A2) then
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
  --password='LoadTest1234!' \
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
  --password='LoadTest1234!' \
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
  --password='LoadTest1234!' \
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
- average, fastest, and slowest latency, plus timing sample counts;
- HTTP status distribution, configured thresholds, pass/fail reasons, and
  sample failures.

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
FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=5
FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=5
FREESWITCH_XML_HANDLER_DIRECTORY_CACHE_TTL=5
FREESWITCH_XML_HANDLER_ACL_CACHE_TTL=5
FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=redis
FREESWITCH_XML_HANDLER_DIRECTORY_CACHE_STORE=redis
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
- `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=5` caches generated dialplan
  XML by tenant/context/destination for short bursts. Set it to `0` to
  measure fully cold generation. Control-panel changes may take up to this
  many seconds to appear in FreeSWITCH dialplan lookups.
- `FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=5` caches standard
  dialplan fragments and first-party context-wide contributor fragments by
  tenant/context, so cold misses for different destinations avoid repeated
  MariaDB reads.
- `FREESWITCH_XML_HANDLER_DIRECTORY_CACHE_TTL=5` caches SIP directory XML
  so repeated registration and authentication lookups avoid rebuilding the
  same user or domain XML.
- `FREESWITCH_XML_HANDLER_ACL_CACHE_TTL=5` caches ACL XML the same way. The
  directory and ACL cache stores fall back to the dialplan cache store when
  not set explicitly.
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
   including the VirtualBox pair, and record each scenario's pass/fail in
   the stage tables.
4. **Recording regression check.** No test currently asserts that `*732`
   produces a usable recording end to end. Add a step that places a call
   to `*732`, holds for at least 10 seconds, hangs up, and then verifies
   the PBX did not answer with `480` and that the recording file exists
   and is non-empty. Fold this into the media runner so it runs with every
   `MEDIA_FLOW=1` invocation.

## Results: Single-Server Bottleneck Tests (Phase 1)

### How Results Are Recorded

The tables in this section are historical July–August 2026 references kept
for review; the planned runs with the new test data replace them.

- Lead with requests per second, then average, fastest, and slowest latency.
  For some older runs the average and fastest are recomputed from the
  stored per-request latencies; where a value was never captured it is shown
  as `—`.
- Comparison tiers are reported as the median of at least three measured
  runs, with every repetition retained. Run a warm-up before recording.
- Keep the same code commit, seed size, `mixed` scenario, cache settings,
  PHP-FPM configuration, and generator host across controlled rows. Label a
  configuration experiment with a suffix such as `-php-fpm-tuned` or
  `-fsnotice`; never overwrite a controlled result with a tuned result.
- Keep both test hosts free of unrelated work during a run: no unrelated
  upgrades, backups, SIP traffic, or administrative jobs.
- If a smaller profile hits a stop condition, record the skipped tier as
  `Not run — <reason>` instead of forcing the test to continue.
- Name artifacts with the hardware profile, tier, and repetition, for
  example `vps-1c-1g-100x5-r1-mixed-controlled.json`.

### VirtualBox Test Server (Phase 1, Stage 1)

The first pass hunted for application-level bottlenecks on the test server
with the XML test. Before caching and index work, the endpoint answered a
few requests per second under load and slowed dramatically under
concurrency. The same tiers after each optimization pass:

| Tier | Before XML cache | After XML cache | After contributor indexes |
| --- | --- | --- | --- |
| `25 x 1` | 0.879 req/sec, slowest 1,328 ms | 3.381 req/sec, 499 ms | Not rerun |
| `100 x 5` | 3.010 req/sec, slowest 2,272 ms | 11.354 req/sec, 903 ms | Not rerun |
| `500 x 25` | 3.704 req/sec, slowest 7,809 ms | 14.208 req/sec, 2,175 ms | 25.636 req/sec, 1,145 ms |
| `1,000 x 25` | Not run | Not run | 24.515 req/sec, 1,463 ms |

The contributor-index follow-up ran with `pm.max_children = 12`, Redis XML
handler caches enabled, and the application cache cleared before each
measured run. Both follow-up tiers completed with zero failed XML responses
and `mysqladmin status` reported zero slow queries at the end of the runs.

The practical repeat check on the same server used the real endpoint with
XML handler auth enabled, Redis caches active, and the synthetic
`load-test-beta` tenant seeded with 100 extensions:

| Tier | Success | Requests/sec | Slowest |
| --- | ---: | ---: | ---: |
| `100 x 5` mixed | 100/100 | 19.011 | 454 ms |
| `500 x 25` mixed | 500/500 | 28.169 | 1,093 ms |
| `1,000 x 25` mixed | 1,000/1,000 | 25.793 | 1,245 ms |

Guest-visible specifications for these runs: Debian GNU/Linux 13 (trixie),
kernel `6.12.95+deb13-amd64`, 4 vCPU from a 12th Gen Intel Core i5-1235U,
3.8 GiB RAM, 2.0 GiB swap, 39.5 GiB virtual disk. Laravel, Nginx/PHP-FPM,
Redis, MariaDB, and FreeSWITCH share the same VM. MariaDB reported zero
slow queries after each run and no new PHP-FPM `pm.max_children` saturation
warnings appeared. These runs recorded requests/sec and slowest latency
directly; average and fastest were not captured by the harness version used
at the time and will be filled in by the planned re-run.

### Datacenter 1 vCPU / 1 GiB (Phase 1, Stage 2)

Controlled runs, all `mixed`, zero failed responses. Every row is one run,
named by repetition:

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

All rows: WAN round-trip latency approximately 35 ms, swap stayed near
91 MiB, and PHP-FPM `pm.max_children` saturation warnings were observed on
the `500 x 25` and `1,000 x 25` default runs. Average and fastest latency
were not captured by the harness version used then.

**PHP-FPM experiment (1 vCPU / 1 GiB).** Three static pool sizes were
compared against the Debian default (`dynamic`, maximum 5). Every entry is
the median of three authenticated `mixed` runs; all runs returned zero
failures.

| PHP-FPM profile | `100 x 5` req/sec | `500 x 25` req/sec | `1,000 x 25` req/sec | `500 x 25` slowest | Result |
| --- | ---: | ---: | ---: | ---: | --- |
| Debian default: dynamic, max 5 | 18.913 | 23.519 | 22.086 | 1,259 ms | Recommended for light or moderate use |
| Static 4 | 18.974 | 22.368 | Not run | 1,232 ms | Rejected; burst result was worse |
| Static 5 | 20.403 | 23.048 | Not run | 1,225 ms | Rejected; no burst improvement |
| Static 6 | 20.285 | 24.040 | 23.034 | 1,096 ms | Optional high-load profile |

Post-test available RAM was 473–479 MiB for the default profile and 440 MiB
for static 6; swap stayed near 91 MiB and no OOM event occurred. In `static`
mode all workers are already available for XML handler bursts, so
`pm.start_servers`, `pm.min_spare_servers`, and `pm.max_spare_servers` are
ignored. Static 6 improved this validation run's throughput by about 4.3%
and its slowest response from 1,921 ms to 1,280 ms versus the default
profile. This is not a universal production recommendation: raising workers
can also increase CPU contention if each request is expensive. Record
before/after results and revert if throughput or the slowest response does
not improve. See `INSTALL.md` for small/standard/larger server sizing
guidance.

**Verification after the July 17 application update.** Three `100 x 5` runs
and three `500 x 25` runs completed with zero failures: average latency
166–214 ms (fastest 112–114 ms, slowest 273–452 ms) at `100 x 5`, and
average 650–757 ms (fastest 142–173 ms, slowest 1,078–1,545 ms) at
`500 x 25`. These averages come from stored per-request latencies.

**Public VPS revalidation (August 31, 2026).** The minimum-hardware VPS was
re-tested against the real Nginx/PHP-FPM endpoint:

| Run | Result | Completed | Throughput | Average latency |
| --- | --- | --- | --- | --- |
| `100 x 5` (small office smoke) | Thresholds passed | 100/100, 0 failures | 16.1 req/sec | 278 ms |
| `500 x 25` (moderate burst) | Completed with 0 failures; tail latency at the ceiling | 500/500, 0 failures | 14.2 req/sec | 1,014 ms |

Finding: the 1-vCPU/1-GB VPS verifies baseline production capacity. The
`500 x 25` tail latency reflects single-vCPU saturation with the default
Debian dynamic FPM pool; larger deployments should use 2+ vCPU and static
FPM worker tuning (`pm = static`, `pm.max_children = 12`). Fastest and
slowest values were not captured for this run.

### Datacenter 1 vCPU / 2 GiB (Phase 1, Stage 3)

The same VPS was resized in place to 1 vCPU and 1973 MiB RAM, rebooted, and
re-run with the same static-6 profile, seed data, and WAN path:

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

All runs returned zero failures and zero slow queries. The server had about
1.38 GiB available memory after testing, used no swap, and logged no new
PHP-FPM `pm.max_children` warnings. Despite the added memory, the burst
tiers were slower than the 1-GB static-6 baseline. That is evidence the
one-vCPU profile is CPU/scheduling-bound for these bursts; the next useful
comparison is the 2-vCPU resize. WAN round-trip latency was approximately
35 ms.

### Datacenter 2 vCPU / 2 GiB (Phase 1, Stage 4)

Same VPS after a second in-place resize to 2 vCPU and 1973 MiB RAM, same
static-6 profile, same WAN path, same WireGuard tunnel, same 2 GiB swap:

| Run | Requests/sec | Average | Fastest | Slowest |
| --- | ---: | ---: | ---: | ---: |
| `100 x 5` r1 | 16.658 | 149 ms | 109 ms | 261 ms |
| `100 x 5` r2 | 18.584 | 131 ms | 107 ms | 186 ms |
| `100 x 5` r3 | 17.864 | 133 ms | 102 ms | 181 ms |
| `500 x 25` r1 | 30.215 | 169 ms | 106 ms | 331 ms |
| `500 x 25` r2 | 29.831 | 165 ms | 103 ms | 365 ms |
| `500 x 25` r3 | 29.363 | 175 ms | 106 ms | 414 ms |
| `1,000 x 25` r1 | 29.673 | 170 ms | 108 ms | 410 ms |

All runs returned zero failures. The host used no swap, retained about
1.36 GiB available memory after testing, and logged no new PHP-FPM
saturation warnings. Doubling the CPU count raised `500 x 25` throughput
from 18.625 (1c/2g median) to 30.215 requests/sec and reduced the slowest
response from about 1,700 ms to about 400 ms. This strongly suggests CPU
scheduling and CPU count were the dominant limiters once memory headroom
was adequate. WAN round-trip latency was 36–41 ms.

**PHP-FPM experiment (2 vCPU / 2 GiB).** Before the test VPS was destroyed,
static 8, 10, and 12 were compared against the static-6 controlled baseline
using the `500 x 25` tier. All candidates returned zero failures and no swap
use:

| Run | Requests/sec | Average | Fastest | Slowest |
| --- | ---: | ---: | ---: | ---: |
| Static 8 r1 | 30.117 | 193 ms | 104 ms | 858 ms |
| Static 8 r2 | 29.087 | 161 ms | 104 ms | 308 ms |
| Static 10 r1 | 28.802 | 188 ms | 107 ms | 894 ms |
| Static 10 r2 | 29.861 | 155 ms | 106 ms | 339 ms |
| Static 12 r1 | 29.472 | 190 ms | 100 ms | 957 ms |
| Static 12 r2 | 29.626 | 156 ms | 100 ms | 340 ms |

None of the higher worker counts improved throughput meaningfully, and each
introduced occasional slowest-response spikes (858–957 ms) compared with
the static-6 baseline (331–414 ms). Static 6 remained the active and
recommended 2-GB profile.

### Planned Larger Profiles (Phase 1, Stages 5–6)

The dedicated-CPU 2 vCPU / 8 GiB and 4 vCPU / 8 GiB profiles have not been
measured yet. When a server is provisioned, repeat the same procedure:

1. Seed with the same `load-test-beta` tenant and extension count.
2. Run the `25 x 1` warm-up, then `100 x 5`, `500 x 10` (safety step for
   small profiles), `500 x 25`, and optionally `1,000 x 25`.
3. Record the median of at least three runs per tier plus the server specs,
   provider CPU class, disk type, region, and pre-run round-trip latency.
4. Stop escalating if XML handler latency spikes, responses return non-2xx,
   MariaDB shows lock/connection pressure, Laravel workers saturate, or the
   generator itself saturates.

## Results: Server-To-Server Call Tests (Phase 2)

Server-to-server runs always use two machines: the PBX under test and a SIPp
load generator on the same network path. The historical VirtualBox pair used
the WSL2 host at `192.168.1.65` as the generator. The historical datacenter
runs used the local test server through WireGuard until the planned
two-datacenter-server topology is in place. Every table in this section is a
historical reference for review; the planned campaign re-runs the same
structure with new test data.

Call-setup latency is measured from the caller's first `INVITE` to the
destination's `200 OK`. "Achieved calls/sec" compares the first and last
successful answer, so it reveals when answers fall behind the attempted
rate. Test tables below use the original column names from the runs.

### VirtualBox Pair (Phase 2, Stage 1)

**Basic correctness and media checks (July 16, 2026)**

| Test | Result | Important observation |
| --- | --- | --- |
| Register 20 users | Passed | 20 successful, 0 failed. Out-of-call NOTIFY messages were discarded but harmless. |
| Recording media to `*732` | Passed | Authenticated call answered, FreeSWITCH sent BYE, 1 successful, 0 failed. |
| MOH media to `load_test_moh` | Passed | Call answered, held through the 10-second media window, SIPp sent BYE and received `200`. |
| Announcement media to `load_test_announcement` | Passed | FreeSWITCH played the packaged prompt, SIPp echoed RTP, FreeSWITCH sent BYE, 1 successful, 0 failed. |

Run details: artifact directory
`storage/app/load-tests/sipp-e2e-20260716-133138`, PBX target
`192.168.1.76:5060`, generator WSL2 at `192.168.1.65`, media RTP echo
enabled, FreeSWITCH calls and channels back to `0` after the run.

Behaviors these tests enforce in the application and runtime:

- required FreeSWITCH module load lines persist so SIP, callcenter,
  local-stream MOH, sound playback, and XML curl survive a reboot;
- dynamic no-domain configuration requests include all enabled Sofia
  profiles and callcenter queues when FreeSWITCH loads modules;
- tenant Sofia profile params are wrapped in `<settings>`;
- callcenter queue names include the FreeSWITCH queue namespace, for
  example `load_test_moh@default`;
- feature-code destination regexes escape star codes, for example `^\*97$`;
- announcement-only IVR dialplans play and hang up without waiting for
  digit input;
- SIPp media-flow scenarios use RTP echo so playback can advance in this
  synthetic setup.

**Complete-signaling capacity ladder (July 17, 2026)**

The load generator used 100 registered synthetic extensions. Each tier
attempted new calls for 30 seconds with a concurrency ceiling above the
expected active calls:

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

The repeatable 5-CPS result is 150/150 successful calls in all three runs,
with a middle achieved rate of 4.928 calls/sec and middle setup figures of
1,484 ms average, 682 ms fastest, and 3,671 ms slowest. Five attempted
calls per second is the highest repeatably verified tier that kept pace
without failures, but it is a measured limit, not an everyday operating
target: CPU was 95–98% busy. Four calls per second is the more sensible
planning limit on this VM because it retained CPU headroom and kept average
setup below one second.

At 6 CPS every call still completed, but the achieved answer rate flattened
to 5.506 CPS and average setup exceeded three seconds. At 7 CPS calls began
to fail, and at 8 CPS 60 of 240 calls failed. This shows a real saturation
boundary near 5–6 end-to-end call setups per second rather than a SIPp
concurrency cap. The run exercised full SIP signaling setup and teardown
but did not generate continuous RTP audio; media capacity and audio quality
require a separate RTP-enabled concurrent-call test. Artifacts:
`storage/app/load-tests/capacity/virtualbox-4c-4g-cps-20260718T011540Z/`.

**Retest at sessions-per-second = 60.** The VirtualBox PBX was retested from
the WSL generator after raising FreeSWITCH to `sessions-per-second=60`.
These runs are VirtualBox comparison results, not production capacity
numbers, and the VM was carrying background load:

| Offered calls/sec | Attempted | Achieved calls/sec | Successful | Failed | Average setup | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 5 | 100 | 4.629 | 100 | 0 | 703 ms | Passed |
| 8 | 160 | 5.574 | 160 | 0 | 4,253 ms | Passed, but queued |
| 10, r1 | 200 | 5.588 | 196 | 4 | 6,276 ms | Failed |
| 10, r2 | 200 | 5.184 | 123 | 77 | 6,742 ms | Failed |
| 12 | 240 | 8.299 attempted/created | 32 | 208 | 5,587 ms | Failed heavily |

A broader sweep reached complete failure at higher offered rates: 15 CPS
completed only 164/300 calls, 20 CPS completed only 28/400, and 25 CPS or
higher completed no calls. FreeSWITCH logs showed `mod_xml_curl` timeout
errors fetching the local XML handler and `CALL_REJECTED` hangups while
network counters still showed zero packet drops or errors. That makes this
run useful for finding the local XML-handler bottleneck, not for a
production calls/sec number. Artifacts:
`virtualbox-4c-4g-sps60-20260718-sipp-cps-r2` and
`virtualbox-4c-4g-sps60-20260718-sipp-cps-low-repeat`.

**FreeSWITCH log-level experiment (VirtualBox).** The same sps60 set was
re-run with FreeSWITCH switch logging lowered from `debug` to `notice`:

| Tier | Attempted | Achieved calls/sec | Successful | Failed | Average setup | SIP INVITE retransmissions |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| 5 CPS, 100 calls | 100 | 4.634 | 100 | 0 | 713 ms | 8 |
| 8 CPS, 160 calls | 160 | 5.398 | 160 | 0 | 4,570 ms | 281 |
| 10 CPS, 200 calls r1 | 200 | 5.598 | 200 | 0 | 6,218 ms | 467 |
| 10 CPS, 200 calls r2 | 200 | 6.626 | 200 | 0 | 4,794 ms | 372 |

A separate 6-CPS tier check with logging lowered to `notice` kept all
180/180 calls completing at about 5.06 calls/sec with average setup
dropping to about 1,235 ms and slowest setup to about 2,427 ms, versus the
3,085 ms average and 6,453 ms slowest seen at the same tier with debug
logging. The conclusion: lowering the log level improves setup delay but
does not materially raise the achieved call rate. Keep DEBUG logging
enabled by default while the PBX is still being validated, and lower it
only for the measured run:

```bash
fs_cli -x 'fsctl loglevel notice'
fs_cli -x 'console loglevel notice'
```

After the comparison, restore the debug-friendly runtime level:

```bash
fs_cli -x 'fsctl loglevel debug'
fs_cli -x 'console loglevel info'
```

Use `warning` instead of `notice` only when the goal is a low-noise
capacity run and detailed call progress logs are not needed. Artifacts:
`virtualbox-4c-4g-sps60-fsnotice-20260718-sipp-cps`.

**Tenant-identity cache experiment (VirtualBox, 8 CPS).** The
tenant-identity cache shortens the repeated directory/auth XML path: when
FreeSWITCH asks which tenant/user a SIP auth request belongs to, Laravel
can reuse a short-lived Redis answer instead of repeatedly querying MariaDB
and decrypting SIP account data during a call burst.

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

The cache cut average directory/auth DB queries from 4.50 to 1.25 per
request and average directory/auth DB time from 62.76 ms to 30.53 ms. The
end-to-end result still had one failed call (an authenticated INVITE
`403 Forbidden` plus the separate SIPp UAS `NOTIFY` harness artifact).
The cache improvement stays, but the remaining VirtualBox failure is not
solved by directory-cache work alone. Artifacts:
`virtualbox-dbtime-8cps-80calls-rerun-20260718` and
`virtualbox-identitycache-8cps-80calls-retry3-20260718`.

### Datacenter Pair (Phase 2, Stage 2)

**Correctness (1 vCPU / 1 GiB, July 17, 2026).** The NATed load generator
reached the public PBX through WireGuard while the Sofia profile stayed
bound to the public IP:

| Test | Result | Important observation |
| --- | --- | --- |
| Register 20 users | Passed | Synthetic accounts used the forced PBX realm; all 20 registrations succeeded. |
| Extension calls | Passed | 10 authenticated extension calls completed through the tunnel. |
| Outbound-route calls | Passed | 5 calls reached the SIPp UAS at `10.77.0.2:5088` after PBX egress SNAT was enabled. |
| Recording media to `*732` | Failed | The dialplan ran `record_session` and immediately ran `hangup`; SIPp received `480` and FreeSWITCH discarded the empty recording. |
| MOH media to `load_test_moh` | Passed | The call answered, stayed active through the 10-second window, and ended normally. |
| Announcement media to `load_test_announcement` | Passed | The call answered and FreeSWITCH sent BYE after playback. |

The basic-run artifacts are under
`capacity/vps-1c-1g-20260717T212713Z/sipp-basic-wg-nat`; targeted MOH and
announcement artifacts are under the sibling `sipp-media-targeted`
directory. SIPp RTP echo was enabled, `tcpdump` was not installed on the
generator, and FreeSWITCH reported zero active calls after testing.

**Low-volume subsets after the resizes (1 vCPU / 2 GiB and 2 vCPU / 2 GiB,
July 18, 2026).** Both resized profiles passed the same low-volume subset
through WireGuard: 5 registrations, 2 authenticated extension calls, and
1 outbound call, with XML curl directory POSTs observed. Media checks were
not repeated because no SIP/RTP behavior changed; the goal was to confirm
the installed application and resized host still served FreeSWITCH XML curl
directory and dialplan requests during live calls. Artifact directories:
`storage/app/load-tests/sipp-e2e-20260717-170848` (1c/2g) and
`storage/app/load-tests/sipp-e2e-20260717-171946` (2c/2g).

**Capacity runs at sessions-per-second = 60 (2 vCPU / 2 GiB, July 18,
2026).** The load generator was the local test server at `192.168.1.76`,
connected to the public PBX through WireGuard:

| Offered calls/sec | Attempted | Achieved calls/sec | Successful | Failed | Average setup | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 15, r1 | 300 | 12.096 | 300 | 0 | 3,105 ms | Passed |
| 15, r2 | 300 | 13.240 | 300 | 0 | 1,282 ms | Passed |
| 20, r1 | 400 | 13.417 | 400 | 0 | 4,669 ms | Passed, but queued |
| 20, r2 | 400 | 14.491 | 400 | 0 | 3,833 ms | Passed, but queued |
| 25 | 500 | 14.158 | 385 | 115 | 6,205 ms | Failed |
| 30 | 600 | 17.181 | 377 | 223 | 6,764 ms | Failed |

The clean no-failure remote result is 20 offered calls/sec with all 400
calls completed, but the average setup time was already several seconds.
For low-latency capacity, the safer interpretation is about 13–15 completed
full calls/sec on this 2-vCPU/2-GB VPS. Above 20 offered calls/sec,
failures return even after removing the session-rate ceiling. Artifacts:
`capacity/vps-2c-2g-static6-sps60-20260718-sipp-cps`.

**Invalidated sessions-per-second = 30 runs.** The first July 18, 2026 SIPp
capacity runs used FreeSWITCH's stock `sessions-per-second=30` safety
throttle and are not valid capacity results. The reason: the limit counts
sessions, not calls, and a normal extension-to-extension call creates two
sessions, so the stock `30` limit can reject calls near 15 two-leg calls per
second even when CPU, network, and the generator still have room. The
invalidated runs showed exactly that pattern: SIPp received
`SIP/2.0 503 Maximum Calls In Progress`, `fs_cli -x status` showed
`sessions per Sec out of max 30`, SIPp reported zero UDP errors, and both
network interfaces showed zero packet errors and drops. Those artifacts
remain available for audit but must not be quoted as clean capacity.

**Tenant-identity cache optimization runs (2 vCPU / 2 GiB, July 18,
2026).** The commit that caches FreeSWITCH tenant-identity resolution was
retested on the correct remote topology: PBX at `x.x.x.236` (WireGuard
`10.77.0.1`), generator at `192.168.1.76` (WireGuard `10.77.0.2`), SIP
realm `x.x.x.236`, `sessions-per-second=60`, Redis cache stores. The first
post-cache runs still had FreeSWITCH switch logging at `debug`:

| Offered calls/sec | Attempted | Achieved calls/sec | Successful | Failed | UDP errors | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 8 | 80 | 7.610 | 80 | 0 | 0 | Passed |
| 10 | 100 | 9.657 | 100 | 0 | 0 | Passed |
| 15 | 300 | 14.735 | 300 | 0 | 0 | Passed |
| 20 | 400 | 17.833 | 400 | 0 | 0 | Passed, with SIP retransmissions |

XML timing during the clean `10 CPS / 100 calls` and `20 CPS / 400 calls`
runs:

| Run | XML section | Requests | Avg total time | Avg DB queries | Avg DB time | Max total time |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| 10 CPS | Directory/auth | 100 | 6.10 ms | 2.00 | 1.30 ms | 26.16 ms |
| 10 CPS | Dialplan | 100 | 4.14 ms | 0.34 | 0.28 ms | 34.99 ms |
| 20 CPS | Directory/auth | 400 | 12.02 ms | 1.00 | 2.35 ms | 58.10 ms |
| 20 CPS | Dialplan | 400 | 9.42 ms | 0.25 | 0.50 ms | 100.53 ms |

Directory/auth lookup time stayed low even at the higher offered rate, and
the full `20 CPS / 400 calls` run completed without failed calls or UDP
errors. SIPp reported retransmissions at 20 CPS, so this is a successful
throughput test with signaling pressure starting to appear, not proof that
much higher rates stay clean. Artifacts:
`remote-vps-identitycache-8cps-80calls-20260718`,
`-10cps-100calls-`, `-15cps-300calls-`, and `-20cps-400calls-20260718`.

**FreeSWITCH log-level experiment (datacenter).** The same 2-vCPU/2-GB VPS
was retested with switch logging lowered from `debug` to `notice` and XML
handler timing logging disabled:

| Offered calls/sec | Attempted | Achieved calls/sec | Successful | Failed | Average setup | SIP INVITE retransmissions | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 20 | 400 | 15.990 | 400 | 0 | 3,121 ms | 533 | Passed |
| 25 | 500 | 17.096 | 500 | 0 | 5,094 ms | 958 | Passed, but heavily queued |
| 30 | 600 | 17.434 | 600 | 0 | 6,611 ms | 1,418 | Passed, but heavily queued |

Zero UDP errors were reported in all three runs. Disabling debug logging
improved the failure outcome at higher offered rates: `25 CPS / 500 calls`
completed with zero failed calls, while the debug-logging baseline failed
at 25 CPS. It did not make the server complete 25 new calls per second;
the completed rate was about 17.1 CPS at 25 offered and 17.4 CPS at 30
offered, with average setup rising from about five seconds to about 6.6
seconds and many INVITE retransmissions. The practical interpretation is
that disabling debug logging reduces enough overhead to avoid outright
failures, but the 2-vCPU/2-GB VPS still queues call setup heavily above
roughly 15–18 completed calls per second. Artifacts:
`remote-vps-notice-20cps-400calls-20260718`,
`-25cps-500calls-`, and `-30cps-600calls-20260718`.

Before the remote test VPS was destroyed, final evidence bundles were
copied back to the project workspace:
`storage/app/load-tests/final-evidence-20260718/pbx-final-vps-evidence-20260718.tar.gz`
and `pbx-final-loadgen-evidence-20260718.tar.gz`. They contain sanitized
WireGuard status/config snippets, FreeSWITCH and Sofia status, PHP-FPM
configuration, Redis/cache checks, application commit/config summaries, and
the SIPp artifact directories for the post-cache remote runs.

### Planned Larger Datacenter Pairs (Phase 2, Stages 4–5)

The dedicated-CPU 2 vCPU / 8 GiB and 4 vCPU / 8 GiB pairs have not been
measured yet. When both servers are provisioned:

1. Confirm correctness first: basic runner plus `MEDIA_FLOW=1` and
   `EXTENDED=1` with the new test data.
2. Re-run the capacity ladder at increasing offered rates and record
   attempted calls, achieved calls/sec, success/failure counts, average /
   fastest / slowest setup time, peak concurrency, peak CPU, and stuck-call
   checks after teardown.
3. Repeat the FreeSWITCH log-level and PHP-FPM experiments on the pair so
   the tuning guidance reflects the new hardware.
4. Run the additional campaign tests listed in Test 2: the concurrent-call
   ladder, the RTP-enabled media capacity test, media-flow re-validation,
   and the recording regression check.

## Hardware Sizing Guidance For Administrators

Use the result tables to choose a starting server size, then verify with a
run on your own hardware before committing to production. The historical
references translate to these planning rules:

- **XML requests per second scale with CPU count, not memory.** Memory alone
  did not help: the 1 vCPU / 2 GiB profile was slower than the 1 vCPU /
  1 GiB profile under the same conditions. Adding a second vCPU roughly
  doubled XML burst throughput (about 19 req/sec to about 30 req/sec at
  `500 x 25`) and cut the slowest response from about 1.7 seconds to about
  0.4 seconds.
- **Historical XML burst expectations:** 1 vCPU ≈ 13–24 req/sec (15–24 in
  the July runs; 16.1 and 14.2 in the August 31 revalidation); 2 vCPU /
  2 GiB ≈ 29–30 req/sec; the 4 vCPU test server ≈ 19–28 req/sec on the
  older code revision, and the optimization work moved it from under
  4 req/sec to that range.
- **Do not size calls from XML numbers.** A complete call adds SIP
  signaling, a second call leg, FreeSWITCH state, and teardown work that the
  XML test never touches.
- **Historical call-rate expectations:** the 4 vCPU test server delivered a
  repeatable 5 calls/sec (4 calls/sec is the sensible planning target on
  that VM); the 2 vCPU / 2 GiB VPS delivered about 13–15 clean completed
  calls/sec, with 20 offered calls/sec still completing but queueing
  heavily.
- **Plan for headroom.** The measured ceilings were reached at 95–98% CPU
  busy. If a tier runs above roughly 90% CPU, do not plan production
  capacity at that tier.
- **Check `sessions-per-second` before blaming hardware.** The project
  default of 60 allows roughly 30 two-leg calls/sec; a stock value of 30
  rejects calls near 15 two-leg calls/sec with `503 Maximum Calls In
  Progress`.
- **Media capacity is separate.** These numbers cover call setup, answer,
  and teardown. Continuous RTP audio quality and media capacity need a
  dedicated RTP-enabled concurrent-call test.
- Re-run the hardware ladder with the new test data before quoting numbers
  to customers. The values in this document are historical references kept
  for review.

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

Findings from the recent runs:

- The VirtualBox July 17 ladder's first visible limiter was CPU: passing
  5-CPS repetitions reached 95–98% CPU busy, 6 CPS queued badly, and 7–8 CPS
  began failing. PHP-FPM showed no max-children saturation, swap stayed
  unused, and MariaDB reported no slow queries. That points to total
  per-call CPU work (FreeSWITCH SIP/bridge work plus Laravel XML handling)
  rather than a simple PHP worker-count problem.
- Lowering FreeSWITCH logging to `notice` reduced setup delay but did not
  move the call-rate ceiling (see the log-level experiments).
- In the datacenter ladder, memory alone did not help (1 vCPU / 2 GiB was
  slower than 1 vCPU / 1 GiB), while a second vCPU roughly doubled XML
  throughput and cut the slowest response. Once memory headroom was
  adequate, CPU count was the dominant limiter.
- For high-rate SIPp runs, always confirm FreeSWITCH `sessions-per-second`
  before interpreting failures.

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
issue; the SIPp scenario waits for a BYE that the dialplan no longer sends.
The July 16 tests exposed exactly this class of bug: feature codes like
`*97` were XML-safe but not regex-safe, producing FreeSWITCH regex compile
errors for the unescaped `*`. One datacenter run also showed the dialplan
running `record_session` and immediately running `hangup`, which made SIPp
receive `480` and FreeSWITCH discard the empty recording.

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

## VirtualBox CPS Lab Recovery Runbook

Use this runbook before any VirtualBox call-rate run from the generator
host. It exists so the lab does not need to be rediscovered after every
reboot, FreeSWITCH restart, test runner restart, or interrupted SIPp
process. The concrete values below are the historical example set
(`load-test-virtualbox`, extension `2000`, password `LoadTest1234!`, realm
`192.168.1.76`); substitute your new test data values where they appear.

Known-good historical lab addresses:

- PBX / FreeSWITCH / Laravel: `192.168.1.76`
- SIPp generator host: `192.168.1.65` (SSH port `2222`)
- SIPp UAS answer port: `5066`
- SIP realm used by this harness: `192.168.1.76`

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
    "realm_ok" => $account?->tenantDomain?->domain === "192.168.1.76",
    "password_ok" => $account?->auth_password === "LoadTest1234!",
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
ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'cd /root/pbx-sipp-validation && \
   head -n 2 storage/app/load-tests/sipp-users-virtualbox-sps60-auth.csv'
```

Expected output:

```text
SEQUENTIAL
2000;LoadTest1234!;192.168.1.76;2001;2000;[authentication username=2000 password=LoadTest1234!]
```

If any PBX value is false, or the CSV first line is not exactly
`SEQUENTIAL`, perform sections 4 and 5 before starting SIPp. A missing
account or malformed auth CSV causes immediate `403 Forbidden` failures,
which are setup failures and not capacity results.

### 2. Clear Stale SIPp And FreeSWITCH Runtime State

Stop any SIPp processes left behind by an interrupted run:

```bash
ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
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
[2000@192.168.1.76]`, the test tenant/SIP accounts are missing or
mismatched. Recreate them on the PBX:

```bash
cd /var/www/tallpbx

php artisan pbx:load-test:seed \
  --tenant=load-test-virtualbox \
  --domain=192.168.1.76 \
  --extensions=20 \
  --start=2000 \
  --password='LoadTest1234!' \
  --sipp-host=192.168.1.65 \
  --sipp-port=5066 \
  --output=storage/app/load-tests/sipp-users-virtualbox-sps60.csv \
  --reset \
  --no-interaction

php artisan optimize:clear
php artisan optimize
```

Expected PBX data after seeding (historical lab values):

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
scp -P 2222 -i /root/.ssh/ppx2-client.rsa \
  /var/www/tallpbx/storage/app/load-tests/sipp-users-virtualbox-sps60.csv \
  root@192.168.1.65:/root/pbx-sipp-validation/storage/app/load-tests/sipp-users-virtualbox-sps60.csv

ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'cd /root/pbx-sipp-validation && \
   awk -F";" '"'"'NR==1{print;next}{print $0 ";[authentication username=" $1 " password=" $2 "]"}'"'"' \
     storage/app/load-tests/sipp-users-virtualbox-sps60.csv \
     > storage/app/load-tests/sipp-users-virtualbox-sps60-auth.csv'
```

Expected first data row:

```text
SEQUENTIAL
2000;LoadTest1234!;192.168.1.76;2001;2000;[authentication username=2000 password=LoadTest1234!]
```

Without the final authentication column, SIPp receives `407` and then sends
a second `INVITE` or `REGISTER` without usable credentials. That produces
misleading `403 Forbidden` failures that are test setup failures, not PBX
capacity failures.

### 6. Register Users, Then Let Voicemail NOTIFY Traffic Drain

Register the 20 users from the generator host:

```bash
ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'cd /root/pbx-sipp-validation && \
   /usr/local/bin/sipp 192.168.1.76 \
     -sf tools/sipp/register.xml \
     -inf storage/app/load-tests/sipp-users-virtualbox-sps60-auth.csv \
     -i 192.168.1.65 \
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
ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'cd /root/pbx-sipp-validation && \
   OUT=storage/app/load-tests/capacity/virtualbox-one-call-proof && \
   mkdir -p "$OUT" && \
   /usr/local/bin/sipp -sf tools/sipp/uas-auto-answer-capacity.xml \
     -i 192.168.1.65 -p 5066 -m 1 \
     -trace_err -error_file "$OUT/uas_errors.log" \
     192.168.1.76 > "$OUT/uas_stdout.log" 2>&1 & \
   uas=$!; sleep 1; \
   /usr/local/bin/sipp 192.168.1.76 \
     -sf tools/sipp/uac-extension-capacity.xml \
     -inf storage/app/load-tests/sipp-users-virtualbox-sps60-auth.csv \
     -i 192.168.1.65 -p 5158 -r 1 -m 1 -l 1 \
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
ssh -p 2222 -i /root/.ssh/ppx2-client.rsa root@192.168.1.65 \
  'cd /root/pbx-sipp-validation && \
   OUT=storage/app/load-tests/capacity/virtualbox-xmltiming-8cps-80calls-clean-$(date +%Y%m%d-%H%M%S) && \
   mkdir -p "$OUT" && \
   /usr/local/bin/sipp -sf tools/sipp/uas-auto-answer-capacity.xml \
     -i 192.168.1.65 -p 5066 -m 80 \
     -trace_err -trace_msg \
     -message_file "$OUT/uas_messages.log" \
     -error_file "$OUT/uas_errors.log" \
     192.168.1.76 > "$OUT/uas_stdout.log" 2>&1 & \
   uas=$!; sleep 1; \
   /usr/local/bin/sipp 192.168.1.76 \
     -sf tools/sipp/uac-extension-capacity.xml \
     -inf storage/app/load-tests/sipp-users-virtualbox-sps60-auth.csv \
     -i 192.168.1.65 -p 5180 \
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

The July 18, 2026 clean retry completed `80/80` calls with `0` SIPp UDP
send/receive/congestion errors and an achieved rate of about `5.17`
calls/sec. Treat that as a short diagnostic proof, not a replacement for
the full staged ladder.

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

Six of eight extended parity scenarios were validated against the live PBX
on July 20, 2026. They cover every context-wide dialplan contributor module
not already exercised by the basic runner:

| Scenario | Destination | SIPp XML | Result |
| --- | --- | --- | --- |
| Ring group (simultaneous, 3 members) | `2400` | `tools/sipp/uac-ring-group.xml` | Passed |
| Voicemail mailbox access | `2003` | `tools/sipp/uac-voicemail.xml` | Passed |
| Conference bridge | `2500` | `tools/sipp/uac-conference.xml` | Passed |
| Unconditional call forward | `2001` to `2000` | `tools/sipp/uac-call-forward.xml` | Passed |
| Time condition (always-match) | `2401` | `tools/sipp/uac-time-condition.xml` | Passed |
| Follow-me forwarding | `2002` | `tools/sipp/uac-follow-me.xml` | Passed |
| Emergency (911) routing | `911` | `tools/sipp/uac-emergency.xml` | Timeout in the lab; see known limitations |
| Call block (blocked caller ID) | any | `tools/sipp/uac-call-block.xml` | Mechanism verified; see known limitations |

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
  `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL`.
- Emergency (911) routing bridges to
  `sofia/external/911@<gateway-host>:<gateway-port>` using the emergency
  gateway's host and port from the database. The bridge executes correctly
  in FreeSWITCH, but SIPp scenario success depends on the gateway UAS
  (port 5088) being alive when the emergency scenario runs.
- Call block uses `orig_caller_id_number`, a channel variable exported at
  the start of every context dialplan that captures the pre-auth
  `caller_id_number`. SIPp cannot exercise the block path because
  `auth-calls=true` on the Sofia profile rejects calls where the From
  header user does not match the authenticated user. In production,
  unauthenticated inbound calls from external gateways arrive in the public
  context where `caller_id_number` is the raw From user, the export
  captures it, and the block check fires correctly. To test via SIPp, use
  `auth-calls=false` on the test profile or send matching From/auth
  credentials.

## References

- SIPp CSV injection: https://sipp.readthedocs.io/en/latest/scenarios/inject_from_csv.html
- SIPp statistics: https://sipp.readthedocs.io/en/v3.6.1/statistics.html
- FreeSWITCH `mod_xml_curl`: https://developer.signalwire.com/freeswitch/integration/xml-curl/
- FreeSWITCH core settings: https://developer.signalwire.com/freeswitch/configuration/core-settings
- WireGuard NAT and firewall traversal guidance: https://www.wireguard.com/quickstart/#nat-and-firewall-traversal-persistence
- Source documents retained during review: `docs/call-simulation-load-testing.md` and `docs/sipp-server-to-server-validation.md`
