# SIP Call Simulation And Load Testing

Date: July 15, 2026

Updated: August 31, 2026 (public VPS Nginx/PHP-FPM capacity benchmarks)

## Status

Phase 3 has a repeatable beta harness and the first XML-handler optimization pass is in place:

- `php artisan pbx:load-test:seed` creates synthetic PBX data.
- `php artisan pbx:load-test:dialplan` load tests dynamic Laravel-generated dialplan XML.
- `tools/sipp/*.xml` contains SIPp registration, UAC, and UAS scenarios.
- `scripts/pbx-load-sample.sh` samples FreeSWITCH, XML handler, MariaDB, and host metrics during a run.
- XML handler successful request debug logging is opt-in.
- Module-state checks are cached for the current request.
- Generated dialplan XML is cached briefly by tenant/context/destination.

The primary target is the dynamic dialplan generation path. SIPp remains useful later for end-to-end call setup/teardown, but direct FreeSWITCH-only load is not the main bottleneck test.

## References

- SIPp CSV injection: https://sipp.readthedocs.io/en/latest/scenarios/inject_from_csv.html
- SIPp statistics: https://sipp.readthedocs.io/en/v3.6.1/statistics.html
- FreeSWITCH `mod_xml_curl`: https://developer.signalwire.com/freeswitch/integration/xml-curl/
- FreeSWITCH core settings: https://developer.signalwire.com/freeswitch/configuration/core-settings

## Test Lab Shape

For dialplan XML load testing, run the generator from a separate host when possible:

- PBX server: Laravel app, local MariaDB, and normal FreeSWITCH deployment.
- Load generator: HTTP client process running `php artisan pbx:load-test:dialplan`.

The PBX server should match the beta deployment shape as closely as practical. Keep FreeSWITCH XML configuration dynamic through `/api/v1/xml-handler`; do not generate static XML from the app for this harness.

The current minimum-hardware capacity lab uses the first size in a sequential in-place VPS resize series:

| Role | Host | Specification |
| --- | --- | --- |
| PBX under test | `x.x.x.218` | Debian 13 VPS, 1 Intel vCPU, 967 MiB RAM, 2 GiB swap, 35 GB disk |
| HTTP and SIPp load generator | `192.168.1.76` | Separate Debian 13 host on a LAN behind NAT |
| Bidirectional SIP/RTP path | WireGuard | PBX `10.77.0.1`, load generator `10.77.0.2`, public endpoint UDP/51820 on the PBX |

Run the HTTP capacity generator on `192.168.1.76` so its CPU and memory do not inflate usage on the minimum-hardware VPS. Run the VPS metric sampler through SSH at the same time. Use WireGuard only for the separate low-volume SIPp correctness/media checks.

After completing and archiving the 1-vCPU/1-GB results, resize this same remote datacenter server in order to 1 vCPU/2 GB, 2 vCPU/2 GB, and optionally 2 vCPU/4 GB. Keep the provider, datacenter region, public IP, disk, operating system, application commit, seed data, and external load generator constant so CPU and memory are the primary planned variables.

## Seed Data

Prepare the synthetic tenant and SIPp CSV:

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

The command is idempotent. Add `--reset` to delete and recreate only the named synthetic tenant:

```bash
php artisan pbx:load-test:seed --tenant=load-test-beta --reset
```

Generated data:

- tenant `load-test-beta`
- SIP realm `load.test.local`
- extension/SIP account range starting at `--start`
- default tenant SIP profiles, dialplans, feature codes, and music on hold
- inbound DID `15551230000` bridged to the first generated extension
- outbound `9` prefix route through the synthetic SIPp gateway
- SIPp CSV at `storage/app/load-tests/sipp-users.csv`

For extended parity scenario coverage (ring groups, voicemail, conferences, call
forwards, time conditions, follow-me, emergency, call blocks), add
`--include-extended-fixtures`. This is not needed for dialplan load testing but is
used by the extended SIPp validation scenarios in
`docs/sipp-server-to-server-validation.md`:

```bash
php artisan pbx:load-test:seed \
  --tenant=load-test-beta \
  --domain=192.168.1.76 \
  --sipp-host=192.168.1.65 \
  --sipp-port=5088 \
  --include-extended-fixtures
```

CSV columns are:

1. `field0`: SIP username
2. `field1`: SIP password
3. `field2`: SIP realm/domain
4. `field3`: destination extension
5. `field4`: caller extension

## Primary Test: Dynamic Dialplan XML

### What One Request Actually Is

In this primary test, a "request" is **not a phone call**. It is one HTTP
question sent directly to the Laravel application. The question imitates
FreeSWITCH asking the application:

> A call has arrived for this tenant, from this caller, to this destination.
> What routing instructions should I follow?

The load generator sends an HTTP `GET` request to `/api/v1/xml-handler`. Each
request identifies:

- the requested FreeSWITCH section: `dialplan`
- the tenant call context, such as `tenant_12_internal` or `tenant_12_public`
- the caller's number
- the number the caller is trying to reach

Laravel then looks up the applicable extensions, inbound routes, outbound
routes, IVRs, ring groups, time conditions, and other enabled PBX features. It
answers with a FreeSWITCH XML dialplan. That XML is a set of instructions such
as "send this call to extension 2001" or "use this outbound route."

Therefore, **100 requests means Laravel was asked to produce 100 separate sets
of XML call-routing instructions**. In the `mixed` scenario, those 100 questions
are divided as evenly as possible among these examples:

- Internal: "Extension 2000 is calling extension 2001. How should it be routed?"
- Inbound: "An outside caller dialed DID 15551230000. Where should it go?"
- Outbound: "An extension dialed 91555123000. Which outbound route should it use?"

The primary test stops after Laravel returns the XML instructions. It does
**not** register a phone, send a SIP `INVITE`, make a phone ring, answer a call,
carry audio, hang up the call, or create a complete call record. The secondary
SIPp test performs the SIP call setup and teardown checks. Keep the two results
separate: this primary test measures how quickly the application can answer
call-routing questions, while SIPp checks whether simulated calls can actually
connect and end correctly through FreeSWITCH.

Put another way, the primary test covers only this part of the call chain:

```text
Load generator -> Nginx/PHP-FPM -> Laravel -> MariaDB/Redis -> XML answer
```

It skips the rest of the phone-call path. A complete end-to-end test covers:

```text
Simulated calling phone
  -> SIP INVITE and authentication
  -> FreeSWITCH receives the call
  -> FreeSWITCH asks Laravel for XML routing instructions
  -> FreeSWITCH follows those instructions
  -> simulated called phone or carrier receives the call
  -> called side rings and answers
  -> SIP call is acknowledged
  -> audio/RTP flows for a defined time when media is enabled
  -> one side hangs up with SIP BYE
  -> the other side confirms the hangup
  -> FreeSWITCH releases both call legs
```

The XML load test is valuable for locating an application bottleneck, but its
`req/sec` result does **not** say how many real calls per second the whole PBX
can establish or how many simultaneous calls it can hold.

### Which Capacity Question Is Being Answered?

| Test/result | What it answers | What it does not answer |
| --- | --- | --- |
| XML handler `req/sec` | How many Laravel XML routing answers can be generated each second? | How many complete SIP calls can connect, carry media, and hang up each second? |
| Low-volume SIPp validation | Does the full call chain work correctly for a small number of simulated calls? | What is the PBX's maximum reliable call rate or concurrent-call capacity? |
| SIPp end-to-end capacity test | How many complete call attempts per second can the whole PBX handle at an acceptable success rate and setup time? | Which individual component caused a slowdown without additional measurements? |
| Concurrent-call test | How many calls can remain active at the same time? | How quickly new calls can be established during a burst? |

Most people asking "how many calls can this PBX handle?" want the last two
answers: successful end-to-end calls per second and simultaneous active calls.
Those numbers must come from SIPp or another SIP load generator traversing
FreeSWITCH, Laravel XML generation, the destination call leg, and teardown. They
cannot be calculated reliably from XML handler `req/sec` alone.

Run the dialplan load test against the real HTTP endpoint:

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

Use the real Nginx/PHP-FPM endpoint for performance measurements. `php artisan serve` is useful for functional checks, but it is not representative for concurrent XML handler load.

Scenarios:

- `internal`: tenant internal context, extension-to-extension destinations.
- `inbound`: tenant public context, seeded DID `15551230000`.
- `outbound`: tenant internal context, seeded `9` prefix route.
- `mixed`: round-robin internal, inbound, and outbound requests.
- `cache-hit`: repeats one exact internal dialplan lookup to isolate generated XML cache-hit behavior.

This command exercises the same app path that FreeSWITCH reaches through `mod_xml_curl`:

- tenant context parsing
- standard dialplan row loading
- dialplan detail eager loading
- module dialplan contributors
- enabled/disabled module checks
- XML escaping and rendering
- HTTP response generation

The JSON report includes:

- run ID, optional label, Git commit, and dirty-worktree status
- load-generator environment details such as hostname, OS, PHP, Laravel, cache store, session driver, memory limit, and CPU count
- target request count, concurrency, timeout, and scenario distribution
- total requests
- successful and failed responses
- success and failure rates
- elapsed time
- requests per second
- average, minimum (fastest), and maximum (slowest) latency, timing sample count, and missing timing count when transfer timings are available
- HTTP status distribution
- configured thresholds and pass/fail reasons
- sample failures

Threshold options make small/medium repeat checks fail fast when behavior changes unexpectedly:

- `--max-failure-rate=0` is the default and fails on any failed XML response.
- `--max-average-ms=1000` fails when average latency exceeds 1,000 ms.
- `--label=...` stores a human-readable run label in the report for later comparison.

Older reports and the `--max-p95-ms` and `--max-p99-ms` compatibility options
retain percentile data for historical comparison automation. New human-facing
results should lead with average, fastest, and slowest latency.

### XML Handler Auth And Cache Settings

Keep XML handler auth enabled for real-server testing and pass the configured token to the load-test command:

```bash
php artisan config:show freeswitch.xml_handler
```

Relevant `.env` settings:

```bash
FREESWITCH_XML_HANDLER_AUTH=true
FREESWITCH_XML_HANDLER_TOKEN=generated-by-installer
FREESWITCH_XML_HANDLER_LOG_REQUESTS=false
FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=5
FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=5
FREESWITCH_XML_HANDLER_DIRECTORY_CACHE_TTL=5
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_CONNECTION=cache
FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_STORE=redis
FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED=false
FREESWITCH_HIREDIS_DIALPLAN_LIMIT_MAX=100000
FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED=false
FREESWITCH_SWITCH_LOG_LEVEL=debug
```

Notes:

- `FREESWITCH_XML_HANDLER_LOG_REQUESTS=false` avoids per-request success log I/O. Warnings and errors still log.
- `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=5` caches generated dialplan XML by tenant/context/destination for short bursts.
- `FREESWITCH_XML_HANDLER_DIALPLAN_CONTRIBUTOR_CACHE_TTL=5` caches standard dialplan fragments and first-party context-wide contributor fragments by tenant/context so cold misses for different destinations avoid repeated MariaDB reads.
- `FREESWITCH_XML_HANDLER_DIRECTORY_CACHE_TTL=5` caches SIP directory XML for short bursts. This helps repeated registration/authentication lookups avoid rebuilding the same user or domain XML from the database during call setup.
- `FREESWITCH_SWITCH_LOG_LEVEL=debug` keeps detailed FreeSWITCH logs enabled by default while the PBX is still being validated.
- `FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED=false` means FreeSWITCH has `mod_hiredis` loaded, but normal local-extension calls do not use Redis-backed FreeSWITCH counters by default. Set it to `true` only when testing or enforcing Redis-backed FreeSWITCH call limits.
- `FREESWITCH_HIREDIS_DIALPLAN_LIMIT_MAX=100000` is the high safety ceiling used when the optional local-extension hiredis counter is enabled for instrumentation rather than real throttling.
- `FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED=false` keeps the deliberate `hiredis_raw` marker action out of normal calls. Set it to `true` during diagnostics when you want Redis `MONITOR` to show an obvious FreeSWITCH-written `pbx:mod_hiredis:last_call:*` key for each local-extension call.
- Set `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL=0` to disable the cache when measuring fully cold generation.
- Redis is the recommended default cache and session store for this project. The file cache/session drivers can become part of measured latency under concurrency, and database-backed cache or sessions can add DB traffic to the path being measured.
- Fresh installs should have `redis-server`, `redis-tools`, and `php8.5-redis` installed and `redis-server` enabled. Verify with `systemctl status redis-server` and `redis-cli ping`.
- Run `php artisan optimize:clear` followed by `php artisan optimize` after changing XML handler config and before performance testing.
- Avoid running `php artisan optimize:clear` while PHP-FPM is serving test traffic. Workers can briefly fail if they request bootstrap cache files while those files are being rebuilt.

### Current Optimizations In Place

The first optimization pass addressed measured hot-path costs:

- Disabled successful XML handler debug logs by default.
- Removed normal-path outbound legacy gateway INFO logging.
- Updated load-test seed data so SIPp outbound validation bridges directly to the SIPp UAS listener. This validates app-generated outbound routing without requiring a Sofia gateway reload before every test run.
- Cached module registry/schema state in `App\Services\ModuleState` for each request, reducing repeated module-state queries while preserving next-request enable/disable behavior.
- Added short-lived generated dialplan XML caching in `XmlHandlerController`.
- Added context-wide standard dialplan fragment caching in `XmlHandlerController` for base dialplans whose XML does not vary by destination.
- Added opt-in context-wide contributor fragment caching in `DialplanXmlCollector` for first-party contributors whose output does not vary by destination.
- Added composite MariaDB indexes for the standard dialplan XML queries: `dialplans(tenant_id, context, enabled, order)` and `dialplan_details(dialplan_id, order)`.
- Added composite MariaDB indexes for cold-path contributor queries that repeatedly read enabled tenant rows ordered by routing key/name/priority. These cover feature contributors such as call blocks, call flows, call forwards, conferences, feature codes, follow-me, inbound/outbound routes, IVRs, ring groups, time conditions, voicemail, and related child option/extension ordering.
- Added a `cache-hit` dialplan load-test scenario so repeated exact requests can isolate generated XML cache-hit behavior.

Cold misses still execute contributor queries, but those reads now have indexes that match their tenant/enabled/order access pattern. Cache hits skip contributor generation and return the previously generated XML.

## FreeSWITCH XML Curl

Fresh installs configure the required FreeSWITCH runtime modules automatically. The installer installs and enables `mod_sofia`, `mod_callcenter`, `mod_dptools`, `mod_hiredis`, `mod_local_stream`, `mod_sndfile`, and `mod_xml_curl`, disables the legacy `mod_redis` and `mod_memcache` module load lines, then writes `/etc/freeswitch/autoload_configs/xml_curl.conf.xml` so FreeSWITCH requests directory, dialplan, and configuration XML from the app. `mod_hiredis` being loaded does not mean every normal call uses Redis directly; the per-call Redis-backed `limit` and diagnostic `hiredis_raw` actions are opt-in through `FREESWITCH_HIREDIS_DIALPLAN_LIMIT_ENABLED` and `FREESWITCH_HIREDIS_DIALPLAN_MARKER_ENABLED`.

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

For existing installs, run `scripts/resources/freeswitch.sh --configure-only` after `.env` contains `FREESWITCH_XML_HANDLER_TOKEN`, then restart or reload FreeSWITCH. That command also reconciles `modules.conf.xml` so Sofia SIP profiles, callcenter queues, local-stream MOH, sound-file playback, and XML curl return after a reboot.

## Secondary Test: SIPp End-To-End Calls

Run this only after the dialplan XML path is healthy. SIPp validates FreeSWITCH integration and call teardown, but direct SIP load mostly stresses FreeSWITCH rather than Laravel's dynamic dialplan generation.

### What A Full End-To-End Rate Test Measures

For a full test, the useful rate is **call attempts per second** (often shortened
to calls per second), not generic requests per second. One call contains many
requests and responses: SIP authentication, one or more `INVITE` messages,
ringing and answer messages, Laravel XML HTTP requests, acknowledgements, and
hangup messages. Counting all of those messages as `requests/sec` would make the
result hard to understand and easy to misrepresent.

SIPp acts as many SIP extensions at the same time. One group registers and
places calls. Another group is reachable as the called side and answers. From
FreeSWITCH and Laravel's point of view, these are normal SIP registrations and
normal SIP calls that must authenticate, route, bridge, answer, and hang up.

When the test says **5 attempted calls per second**, it means SIPp tries to
start five new SIP calls every second. If the run lasts 30 seconds, that is
150 attempted calls. These are simulated extension-to-extension calls through
the PBX, not raw HTTP requests or individual SIP packets.

For this capacity test, one simulated call is:

1. A caller extension, such as `2000`, sends an `INVITE` for another extension,
   such as `2001`.
2. FreeSWITCH receives the call attempt and asks Laravel for the caller's SIP
   account and routing instructions.
3. Laravel answers with XML saying who the caller is and how the destination
   should be routed.
4. FreeSWITCH uses those instructions to create the called call leg.
5. The called SIPp endpoint rings and answers.
6. The call stays connected for the configured hold time.
7. One side hangs up.
8. FreeSWITCH tears down both sides of the call and returns to idle.

One successful end-to-end test call therefore means all of the following
happened:

1. SIPp started a simulated call by sending an `INVITE` to FreeSWITCH.
2. Authentication succeeded when required.
3. FreeSWITCH requested and received the applicable XML from Laravel.
4. FreeSWITCH created the destination call leg using those routing instructions.
5. The simulated destination rang and answered with `200 OK`.
6. The caller acknowledged the answer, so the call was established.
7. The call remained active for the scenario's defined time; RTP is included
   when the selected scenario enables media.
8. One side sent `BYE`, the other confirmed it, and FreeSWITCH released the call.

The stopwatch for setup time starts when the caller sends the first `INVITE`.
The stopwatch stops when the called endpoint answers with `200 OK`. That
setup time includes SIP authentication, Laravel XML lookup, FreeSWITCH routing,
the second call leg, ringing, and answer. It does not include the full time the
call remains connected after answer.

The calls-per-second score is based on completed answered calls, not just
attempts. If SIPp tries 8 calls per second but the PBX can only answer about 5
per second before calls slow down or fail, the measured result is about 5
successful calls per second, not 8.

An end-to-end capacity report should include:

- attempted calls per second: how quickly SIPp tried to start new calls
- achieved successful calls per second: fully successful calls divided by the
  elapsed test time
- successful and failed call counts and percentages
- average, fastest, and slowest call-setup time, measured from the first call
  attempt until the call is answered
- peak simultaneous active calls
- clean teardown results, including whether channels remained stuck afterward
- CPU, memory, network, FreeSWITCH, PHP-FPM, MariaDB, and Redis observations
- RTP loss or media errors when media is part of the scenario

This is the test most people mean when they ask, "How many calls per second can
the PBX handle?" It exercises the whole call setup path:

```text
SIPp caller endpoint
  -> FreeSWITCH SIP listener
  -> Laravel XML directory lookup
  -> Laravel XML dialplan lookup
  -> FreeSWITCH bridge/routing logic
  -> SIPp called endpoint
  -> answer
  -> short connected call
  -> hangup and cleanup
```

It is still not the same as a full audio-quality/media-capacity test unless RTP
media is enabled. The signaling CPS test proves the PBX can establish and tear
down calls at a given rate. A media test adds continuous audio packets and checks
whether the server and network can carry that audio cleanly while the calls are
active.

Call rate and concurrency are different. If calls remain connected for 30
seconds and the test starts 2 calls each second, it needs room for about 60
simultaneous calls once it reaches a steady state. A low concurrency limit will
force SIPp to wait, so the configured attempt rate may be higher than the rate
the test actually achieves.

The current `scripts/pbx-sipp-validate.sh` defaults are deliberately small: 10
extension calls attempted at 2 per second with no more than 5 active at once,
followed by 5 outbound calls attempted at 1 per second with no more than 2
active at once. These defaults prove that the full chain works, but they do
**not** establish end-to-end PBX capacity. A capacity claim requires staged,
repeated SIPp runs with increasing `CALL_RATE`, `MAX_SIMULTANEOUS`, and `CALLS`,
plus retained statistics and server metrics. The safe capacity is the highest
repeatable tier that maintains the chosen success-rate and call-setup-latency
limits and leaves no stuck calls after teardown.

### How To Look For The Bottleneck

Do not stop at the final calls-per-second number. A CPS result is useful only
when the run also shows what was running out first.

For the current end-to-end CPS work, treat FreeSWITCH load as the primary
suspect until measurements prove otherwise. Redis, MySQL, and PHP-FPM tuning are
still useful, but only if they reduce the work FreeSWITCH waits on during call
setup. The goal is not to make an isolated database benchmark faster. The goal
is to help FreeSWITCH authenticate, route, bridge, answer, and tear down more
calls per second.

Check these suspects during every staged CPS run:

| Suspect | What it means in plain language | What to look for | Likely next action |
| --- | --- | --- | --- |
| FreeSWITCH SIP/session work | FreeSWITCH is spending too much CPU per call on SIP state, session setup, bridging, and teardown. | CPU busy stays near 95-100%, run queue rises, setup time gets slower before calls fail. | Reduce unnecessary FreeSWITCH work first: lower logging, avoid debug/SIP trace, keep scenarios realistic, then add CPU or split roles. |
| FreeSWITCH debug logging | FreeSWITCH is spending time writing detailed internal call logs. | `freeswitch.log` fills with DEBUG lines during the run. | Use `FREESWITCH_SWITCH_LOG_LEVEL=notice` or `warning` for capacity tests. |
| Laravel XML generation | FreeSWITCH is blocked waiting for directory or dialplan XML before it can continue the call. | XML handler latency rises at the same time setup time rises. | Use Redis XML caches and query/index fixes only where they shorten FreeSWITCH's wait during SIP setup. |
| PHP-FPM saturation | FreeSWITCH is waiting because Laravel has more XML requests queued than PHP workers available. | PHP-FPM logs `pm.max_children` warnings or XML latency jumps while CPU is not fully busy. | Tune `pm.max_children`, but avoid adding workers past available CPU/RAM. |
| MariaDB | FreeSWITCH is indirectly waiting because Laravel XML generation is blocked on database reads/writes. | Slow queries, high disk wait, or DB CPU spikes during XML lookups. | Add indexes or cache hot XML so FreeSWITCH spends less time waiting; do not treat DB speed as the main CPS target by itself. |
| Redis | Hot XML cannot be reused cheaply, so FreeSWITCH waits for repeated Laravel/database work. | Redis errors, high Redis latency, or cache store accidentally set to file/database. | Use local Redis with PhpRedis and verify `redis-cli ping`; compare CPS with cache enabled and disabled. |
| SIPp/load generator | The tester, not the PBX, cannot keep up. | SIPp CPU high, failed sends, outbound congestion, or achieved attempt rate below target while PBX has headroom. | Move SIPp to a stronger/separate host, raise file descriptors/ports, or lower local logging. |
| Network/WireGuard | Packets are delayed or dropped before reaching the PBX or SIPp listener. | Retransmissions, packet loss, high RTT, or NAT/WireGuard endpoint churn. | Test from the same datacenter or fix tunnel/UDP path before trusting CPS. |

For the VirtualBox July 17 CPS run, the first visible limiter was CPU: the
passing 5-CPS repetitions reached 95-98% CPU busy, 6 CPS queued badly, and 7-8
CPS began failing or falling behind. PHP-FPM did not show max-children
saturation, swap stayed unused, and MariaDB did not report slow queries. That
points to total per-call CPU work, especially FreeSWITCH SIP/bridge work plus
Laravel XML handling, rather than a simple PHP worker-count problem.

That means the next optimization pass should be FreeSWITCH-first. Redis caching
is still worth keeping because it may reduce the time FreeSWITCH waits for XML
answers, but it should be measured as a FreeSWITCH call-setup improvement, not
as a standalone MySQL optimization.

One important follow-up: the local FreeSWITCH package config had global
`loglevel` set to `debug`, and the historical log contains many DEBUG call-state
lines. A quick VirtualBox check with runtime logging lowered to `notice` did not
materially raise achieved CPS, but it did improve setup delay at the 6-CPS tier:
180/180 calls still completed, achieved throughput was about 5.06 calls/sec,
average setup dropped to about 1,235 ms, and slowest setup dropped to about
2,427 ms.

Because the CPS ceiling did not move much, keep DEBUG logging enabled by default
while the PBX is still being validated. For a cleaner capacity comparison, lower
logging only for the measured run:

```bash
fs_cli -x 'fsctl loglevel notice'
fs_cli -x 'console loglevel notice'
```

After the comparison, restore the normal debug-friendly runtime level:

```bash
fs_cli -x 'fsctl loglevel debug'
fs_cli -x 'console loglevel info'
```

Use `warning` instead of `notice` only when the goal is a low-noise capacity run
and detailed call progress logs are not needed.

### VirtualBox End-To-End Calls-Per-Second Result

This test was run on July 17, 2026 from WSL at `192.168.1.65` against the
VirtualBox PBX at `192.168.1.76`. It measured complete SIP signaling through
both call legs:

1. SIPp sent an unauthenticated `INVITE`.
2. FreeSWITCH challenged it and SIPp sent an authenticated `INVITE`.
3. FreeSWITCH requested Laravel-generated directory and dialplan XML.
4. FreeSWITCH called the registered SIPp destination.
5. The destination rang and answered.
6. The call remained connected for five seconds.
7. SIPp sent `BYE`, received the final confirmation, and both FreeSWITCH
   channels were released.

The load generator used 100 registered synthetic extensions. Each measured
tier attempted new calls for 30 seconds. The concurrency ceiling was set above
the number of calls expected to be active, so it did not intentionally limit
the passing tiers. Call-setup time was measured from the caller's first
`INVITE` until the destination answered with `200 OK`.
"Achieved answers/sec" is calculated from the time between the first and last
successful answer, so it reveals when answers fall behind the attempted rate.

| Attempted calls/sec | Calls | Successful | Failed | Achieved answers/sec | Average setup | Fastest setup | Slowest setup | Peak active SIPp calls | Peak PBX calls/channels | Peak CPU busy | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 1 | 30 | 30 | 0 | 1.009 | 593 ms | 470 ms | 774 ms | 6 | 7 / 12 | 29% | Passed |
| 2 | 60 | 60 | 0 | 2.012 | 680 ms | 573 ms | 892 ms | 12 | 12 / 22 | 54% | Passed |
| 4 | 120 | 120 | 0 | 4.018 | 848 ms | 480 ms | 1,312 ms | 25 | 24 / 45 | 85% | Passed |
| 5, repetition 1 | 150 | 150 | 0 | 4.928 | 1,799 ms | 749 ms | 3,760 ms | 40 | 35 / 64 | 97% | Passed |
| 5, repetition 2 | 150 | 150 | 0 | 4.994 | 1,256 ms | 647 ms | 2,132 ms | 35 | 32 / 61 | 95% | Passed |
| 5, repetition 3 | 150 | 150 | 0 | 4.886 | 1,484 ms | 682 ms | 3,671 ms | 37 | 34 / 65 | 98% | Passed |
| 6 | 180 | 180 | 0 | 5.506 | 3,085 ms | 784 ms | 6,453 ms | 55 | 43 / 72 | 98% | Passed, but overloaded |
| 7 | 210 | 209 | 1 | 5.498 | 5,679 ms | 738 ms | 9,220 ms | 70 | 53 / 83 | 98% | Failed |
| 8 | 240 | 180 | 60 | 4.641 | 6,853 ms | 918 ms | 9,998 ms | 80 | 56 / 81 | 98% | Failed |

The repeatable 5-CPS result is 150/150 successful calls in all three runs. The
middle achieved rate was **4.928 answered calls per second**. The middle setup
figures were **1,484 ms average, 682 ms fastest, and 3,671 ms slowest**.

Five attempted calls per second is therefore the highest repeatably verified
tier that kept pace without failures. It is a measured limit, not a recommended
everyday operating target: CPU was 95-98% busy. Four calls per second is the
more sensible planning limit on this VM because it retained CPU headroom and
kept average setup below one second.

At 6 CPS every call eventually completed, but the achieved answer rate flattened
to 5.506 CPS and average setup exceeded three seconds. At 7 CPS calls began to
fail. At 8 CPS, 60 of 240 calls failed and achieved throughput fell further.
This shows a real saturation boundary near 5-6 end-to-end call setups per
second, rather than a SIPp concurrency cap.

This was a full SIP signaling setup-and-teardown capacity test, but it did not
generate continuous RTP audio packets during every call. Media capacity and
audio quality require a separate RTP-enabled concurrent-call test. All raw
SIPp timing/statistics files and PBX samplers are under
`storage/app/load-tests/capacity/virtualbox-4c-4g-cps-20260718T011540Z/`.

The detailed SIPp operating guide is now separate to keep this load-testing overview focused:

- `docs/sipp-server-to-server-validation.md`

Run SIPp from WSL2 or a separate Linux VM when possible. A separate load generator keeps SIPp CPU/RTP work away from the PBX VM and avoids measuring SIPp competing with FreeSWITCH, PHP-FPM, MariaDB, and Redis on the same host.

The full runner requires FreeSWITCH to reach SIPp UAS signaling and RTP ports, not merely receive an outbound SIP request. When either host is behind NAT and those callback addresses are not directly routable, use the WireGuard topology documented in `docs/sipp-server-to-server-validation.md`. If both peers are behind NAT, one must have a reachable UDP port forward or both must meet through a public relay/mesh VPN.

```bash
PBX_HOST=192.168.1.76 \
LOAD_GENERATOR_IP=LOAD_GENERATOR_IP \
FORCE_SEED=1 \
scripts/pbx-sipp-validate.sh
```

For optional live media validation of recording, music-on-hold, and announcement paths, add `MEDIA_FLOW=1`:

```bash
PBX_HOST=192.168.1.76 \
LOAD_GENERATOR_IP=LOAD_GENERATOR_IP \
FORCE_SEED=1 \
MEDIA_FLOW=1 \
scripts/pbx-sipp-validate.sh
```

Expected SIPp result: the runner exits `0`, `summary.md` shows the requested scenarios passed, and SIPp logs show successful calls equal to the requested count with zero failed calls. The SIPp guide explains the topology, manual WSL2 commands, expected artifacts, result interpretation, and the July 16, 2026 findings. In short: registration, extension calls, outbound calls, recording, MOH, and announcement media-flow all passed from WSL2 to the PBX VM. Media-flow runs use SIPp RTP echo so FreeSWITCH playback can complete normally in this synthetic test setup.

## Metric Sampling

Run the sampler on the PBX server while the dialplan load command or SIPp is active:

```bash
INTERVAL=5 SAMPLES=120 XML_URL=http://127.0.0.1/api/v1/xml-handler scripts/pbx-load-sample.sh | tee storage/logs/pbx-load-$(date +%Y%m%d-%H%M%S).log
```

The sampler captures:

- `fs_cli -x status`
- `fs_cli -x "show channels count"`
- `fs_cli -x "show calls count"`
- `fs_cli -x "show registrations count"`
- XML handler HTTP status and latency through `curl`
- MariaDB status through `mysqladmin status`
- host CPU/run queue snapshot through `vmstat`

Also keep SIPp screen output and any SIPp CSV/stat files from the load generator.

For the current beta phase, keep metrics collection simple and repeatable:
use the JSON report from `pbx:load-test:dialplan` plus this host sampler
instead of introducing a dedicated metrics exporter. Consider Prometheus,
Grafana, or another exporter stack only when longer-running beta tests need
continuous dashboards or retention across many hosts.

## PHP-FPM Review And Tuning

Check active worker settings before and after changing PHP-FPM:

```bash
php-fpm8.5 -tt 2>&1 | grep -E 'pm\.max_children|pm\.start_servers|pm\.min_spare_servers|pm\.max_spare_servers|pm\.max_requests'
tail -n 50 /var/log/php8.5-fpm.log
```

The warning below means XML handler requests are queueing behind PHP-FPM workers:

```text
server reached pm.max_children setting
```

The standard install recommendation for a 4 GB combined PBX/application server is:

```ini
pm = static
pm.max_children = 12
```

For a lightly or moderately used 1-vCPU/1-GB server, leaving Debian's default `dynamic` pool with `pm.max_children = 5` is also a valid operating choice. Capacity tuning is optional when the default profile meets the installation's latency and call-volume needs. Test static worker settings as a separately labelled optimization instead of implying that the default configuration is unsuitable.

Keep `pm.max_requests` at the PHP-FPM default of `0` for controlled hardware comparisons. Set a nonzero recycling interval only if sustained monitoring shows that worker memory grows over time, and record that change as a separate tuning variable.

## FreeSWITCH Session-Rate Limit

FreeSWITCH has a core `sessions-per-second` limit. This is a safety throttle for
new FreeSWITCH sessions, not a bandwidth limit. An extension-to-extension call
normally creates two FreeSWITCH sessions: one caller leg and one destination leg.

The project default is now:

```xml
<param name="sessions-per-second" value="60"/>
```

In plain language, this gives a small server room to attempt roughly 30
two-leg calls per second before FreeSWITCH's own safety throttle rejects new
sessions. The real clean call rate can still be lower because CPU, call routing,
bridging, logging, SIP retransmits, and media work also matter.

If this limit is too low, overload tests can fail with:

```text
SIP/2.0 503 Maximum Calls In Progress
```

That response means FreeSWITCH deliberately refused new sessions because its
session-rate ceiling was reached. It does not by itself prove packet loss,
bandwidth saturation, or a Laravel/PHP-FPM failure.

For higher-volume servers, raise this only as a labelled capacity-tuning change
and retest with CPU, memory, network counters, and SIPp failure reasons
captured. A higher value removes the safety throttle but does not create more
CPU.

The July 17, 2026 1-vCPU/1-GB tuning pass compared three static candidates with the Debian default. Every entry below is the median of three authenticated mixed-scenario runs; all runs returned zero failures.

| PHP-FPM profile | `100 x 5` req/sec | `100 x 5` p95 | `500 x 25` req/sec | `500 x 25` p95 | Post-test available RAM | Result |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| Debian default: dynamic, maximum 5 | 18.913 | 268 ms | 23.519 | 1033 ms | 473–479 MiB | Recommended for light or moderate use |
| Static 4 | 18.974 | 267 ms | 22.368 | 1069 ms | 454 MiB | Rejected; burst result was worse |
| Static 5 | 20.403 | 279 ms | 23.048 | 1042 ms | 447 MiB | Rejected; no burst improvement |
| Static 6 | 20.285 | 287 ms | 24.040 | 1003 ms | 440 MiB | Optional high-load profile |

The selected static-6 profile also completed `1000 x 25` with 1000/1000 successful responses at 23.034 requests/sec, 1068 ms p95, 1164 ms p99, and 1280 ms maximum latency. The matching default run completed at 22.086 requests/sec, 1093 ms p95, 1420 ms p99, and 1921 ms maximum latency. Static 6 therefore improved this validation run's throughput by about 4.3%, p99 by about 18%, and maximum latency by about 33%. Swap stayed at approximately 91 MiB, no OOM event occurred, and `pm.max_requests` remained `0` throughout.

In `static` mode, all workers are already available for XML handler bursts; `pm.start_servers`, `pm.min_spare_servers`, and `pm.max_spare_servers` are ignored. This is not a universal production recommendation. Raising workers can improve moderate concurrency, but it can also increase CPU contention if each request is still expensive. Record before/after results and revert if throughput or tail latency does not improve. See `INSTALL.md` for small/standard/larger server sizing guidance.

## Observed Local Beta Results

The following results came from the project beta host at `192.168.1.76`, using the real Nginx/PHP-FPM endpoint and local MariaDB. Treat them as a baseline for this host, not a product guarantee.

Most of these older XML reports saved percentiles and maximum latency, but did
not save the raw samples, average, or minimum. They cannot be converted exactly.
For a simpler historical view, the table below uses p50 as a rough estimate of
the average and half of p50 as a rough estimate of the fastest response. The
slowest value is the measured maximum. A `~` prefix means estimated, not
measured.

| Historical VirtualBox XML run | Requests/sec | Estimated average | Estimated fastest | Measured slowest |
| --- | ---: | ---: | ---: | ---: |
| `100 x 5` mixed | 19.011 | ~195 ms | ~98 ms | 454 ms |
| `500 x 25` mixed | 28.169 | ~582 ms | ~291 ms | 1,093 ms |
| `1000 x 25` mixed | 25.793 | ~647 ms | ~324 ms | 1,245 ms |

These estimates are included because the original raw values were not retained.
They must not be treated as re-measurements. Future XML reports record the real
average, fastest, and slowest values directly.

| Test | Before XML Cache | After XML Cache | After Contributor Indexes |
| --- | ---: | ---: | ---: |
| `25 x 1` requests/sec | 0.879 | 3.381 | Not rerun |
| `25 x 1` p95 | 1319 ms | 358 ms | Not rerun |
| `100 x 5` requests/sec | 3.010 | 11.354 | Not rerun |
| `100 x 5` p95 | 1758 ms | 454 ms | Not rerun |
| `500 x 25` requests/sec | 3.704 | 14.208 | 25.636 |
| `500 x 25` p95 | 6700 ms | 1716 ms | 957 ms |
| `1000 x 25` requests/sec | Not run | Not run | 24.515 |
| `1000 x 25` p95 | Not run | Not run | 1041 ms |

PHP-FPM still reached `pm.max_children` during `500 x 25` when set back to 5 workers, but the short-lived XML cache allowed the queue to drain much faster.

The contributor-index follow-up ran with `pm.max_children = 12`, Redis XML-handler caches enabled, and the application cache cleared before each measured run. The `500 x 25` and `1000 x 25` runs both completed with zero failed XML responses and `mysqladmin status` reported zero slow queries at the end of the runs. Treat these as local beta-host observations, not universal capacity figures.

The practical small/medium repeat check on July 16, 2026 used the real
`http://192.168.1.76/api/v1/xml-handler` endpoint with XML-handler auth enabled,
Redis XML-handler caches active, and the synthetic `load-test-beta` tenant
seeded with 100 extensions. These runs are relative comparison checks for the
Windows-hosted VM, not VPS/datacenter capacity claims.

Guest-visible VM specs for this run:

- Host context: Windows 11 workstation running a local virtual machine.
- Guest OS: Debian GNU/Linux 13 (trixie), kernel `6.12.95+deb13-amd64`.
- Virtualization: guest reports VirtualBox / `oracle` virtualization.
- CPU: 4 vCPUs exposed from a 12th Gen Intel Core i5-1235U.
- Memory: 3.8 GiB RAM, 2.0 GiB swap.
- Disk: 39.5 GiB virtual disk, root filesystem on `/dev/sda1`.
- PBX shape: Laravel app, Nginx/PHP-FPM, Redis, MariaDB, and FreeSWITCH on the same VM.

| Practical Repeat Check | Success | req/sec | Estimated average | Estimated fastest | Measured slowest |
| --- | ---: | ---: | ---: | ---: | ---: |
| `100 x 5` mixed | 100/100 | 19.011 | ~195 ms | ~98 ms | 454 ms |
| `500 x 25` mixed | 500/500 | 28.169 | ~582 ms | ~291 ms | 1,093 ms |
| `1000 x 25` mixed | 1000/1000 | 25.793 | ~647 ms | ~324 ms | 1,245 ms |

MariaDB reported zero slow queries at the end of each run, and no new PHP-FPM
`pm.max_children` saturation warnings appeared during these practical tiers.

## Cross-Hardware Comparison

Use the same code commit, seed size, mixed scenario, cache settings, PHP-FPM configuration, and external load generator for every hardware profile. Run a warm-up before recording results. If a smaller profile reaches a stop condition, record the skipped tier as `Not run — <reason>` instead of forcing the test to continue.

### Hardware Profiles

| Profile | Environment | CPU | RAM | Swap | Load path | Status |
| --- | --- | ---: | ---: | ---: | --- | --- |
| `virtualbox-4c-4g` | Windows 11 / VirtualBox comparison VM | 4 vCPU | 3.8 GiB | 2.0 GiB | Local/LAN | Baseline complete |
| `vps-1c-1g` | Remote datacenter VPS at `x.x.x.218`, initial size | 1 vCPU | 967 MiB | 2.0 GiB | WAN from `192.168.1.76` | XML capacity and SIPp complete |
| `vps-1c-2g` | Same remote datacenter VPS after resize | 1 vCPU | 1973 MiB | 2.0 GiB | Same WAN path | XML capacity and low-volume SIPp complete |
| `vps-2c-2g` | Same remote datacenter VPS after second resize | 2 vCPU | 1973 MiB | 2.0 GiB | Same WAN path | XML capacity and low-volume SIPp complete |
| `vps-2c-4g` | Remote datacenter VPS at optional larger size | 2 vCPU | 4 GiB | Record at run time | Same WAN path | Optional |

The VirtualBox rows are the existing LAN comparison baseline. The `vps-*` rows are the remote datacenter hardware-size series. The table and test procedure are the same whether those sizes come from in-place resizing or replacement VPS instances. Record which occurred, plus the provider CPU model/class, disk type, and datacenter region for every result.

### Sequential Resize Controls

Complete these steps at each VPS size before moving to the next size:

1. Reboot after the resize and confirm the new CPU, memory, and swap values with `lscpu`, `free -h`, and `swapon --show`.
2. Confirm the public IP, WireGuard addresses, disk, application commit, services, seed data, cache settings, and load-generator host are unchanged.
3. Record the active PHP-FPM process-manager settings. Keep them fixed for the primary hardware comparison. If a size-specific PHP-FPM tuning pass is also tested, label it as a separate run rather than replacing the controlled result.
4. Run the same warm-up and staged tiers, preserving every JSON report and sampler log before resizing again.
5. Record any provider CPU-model change, memory pressure, swap growth, PHP-FPM saturation, or tier skipped because a stop condition was reached.

If a later size uses a replacement VPS instead of an in-place resize, apply the same checklist after provisioning it. The comparison remains valid when the software and test protocol are reproduced, but the instance identity must be recorded.

### Controlled Comparison Manifest

Record this manifest for every hardware size. Values in the `Controlled value` column should remain the same across the remote datacenter series. If one changes materially, label that run as a separate comparison series.

| Variable | Controlled value |
| --- | --- |
| Application revision | Same Git commit for every hardware size |
| Datacenter environment | Same provider and region when practical; record instance identity and whether it was resized or replaced |
| Load generator | `192.168.1.76` for every remote run |
| XML endpoint | Real public Nginx/PHP-FPM `/api/v1/xml-handler` endpoint |
| Authentication | Same XML-handler authentication setting and token |
| Seed data | Same `load-test-beta` tenant, extension count, and destinations |
| Scenario | `mixed` |
| Cache state | Same Redis stores, TTLs, warm-up procedure, and cache policy |
| PHP-FPM | Same process-manager mode, worker limits, and request limit for controlled rows |
| Run order | `25 x 1` warm-up, `100 x 5`, `500 x 10`, `500 x 25`, optional `1000 x 25` |
| Repetitions | At least three measured runs per comparison tier; report the median and retain all reports |
| Metrics | Same sampler interval, fields, and start/stop timing |
| Background workload | No unrelated upgrades, backups, SIP traffic, or administrative jobs during a run |

Use labels and artifact names that begin with the hardware profile and repetition number:

```text
vps-1c-1g-100x5-r1-mixed-controlled.json
vps-1c-1g-100x5-r1-sampler.log
vps-1c-2g-500x25-r2-mixed-controlled.json
vps-2c-2g-1000x25-r3-mixed-controlled.json
```

Use a suffix such as `-php-fpm-tuned` for a configuration experiment. Never overwrite or silently replace a controlled result with a tuned result.

### Network-Latency Qualification

The historical VirtualBox baseline used a local/LAN endpoint, while the remote VPS series crosses the WAN. Record idle round-trip latency immediately before every measured run. The remote sizes remain directly comparable when they use the same path and protocol, but their raw latency values include WAN latency and are not a pure CPU/RAM comparison with the VirtualBox baseline.

Use the VirtualBox values as a practical local-deployment baseline. Compare failures, throughput, resource saturation, and latency together; do not attribute the entire latency difference to VPS hardware. If a pure compute comparison is later required, repeat the generator from a second host in the same datacenter for every VPS size and place those results in a separately labeled matrix.

### Legacy Capacity Comparison Matrix

Each result cell uses the older `requests/sec; p95; failures` format because the
raw historical samples were not saved. Use it as an audit record. New comparison
tables should use requests/sec plus average, fastest, and slowest latency.

For an easy cross-hardware view, these estimates use each retained `1000 x 25`
p50 as approximate average, half of p50 as approximate fastest, and the measured
maximum as slowest:

| Hardware profile | Requests/sec | Estimated average | Estimated fastest | Measured slowest |
| --- | ---: | ---: | ---: | ---: |
| `virtualbox-4c-4g` | 25.793 | ~647 ms | ~324 ms | 1,245 ms |
| `vps-1c-1g` Debian default | 22.086 | ~646 ms | ~323 ms | 1,921 ms |
| `vps-1c-1g` static 6 | 23.034 | ~654 ms | ~327 ms | 1,280 ms |
| `vps-1c-2g` static 6 | 16.821 | ~1,337 ms | ~669 ms | 1,966 ms |
| `vps-2c-2g` static 6 | 29.673 | ~162 ms | ~81 ms | 410 ms |

| Hardware profile | `100 x 5` mixed | `500 x 25` mixed | `1000 x 25` mixed | Capacity notes |
| --- | --- | --- | --- | --- |
| `virtualbox-4c-4g` | `19.011; 312 ms; 0` | `28.169; 863 ms; 0` | `25.793; 982 ms; 0` | Local comparison baseline; no new PHP-FPM saturation warnings |
| `vps-1c-1g` Debian default | `18.913; 268 ms; 0` | `23.519; 1033 ms; 0` | `22.086; 1093 ms; 0` | Minimum-hardware controlled baseline; default dynamic pool, maximum 5 workers |
| `vps-1c-1g` static 6 | `20.285; 287 ms; 0` | `24.040; 1003 ms; 0` | `23.034; 1068 ms; 0` | Optional tuned profile; six static workers, `pm.max_requests = 0` |
| `vps-1c-2g` static 6 | `13.567; 318 ms; 0` | `18.625; 1466 ms; 0` | `16.821; 1605 ms; 0` | Same single CPU with more memory; no swap or FPM saturation, but lower throughput under this provider state |
| `vps-2c-2g` static 6 | `18.584; 162 ms; 0` | `30.215; 246 ms; 0` | `29.673; 244 ms; 0` | Same memory as 1c/2g with a second vCPU; strongest remote result so far |
| `vps-2c-4g` | Skipped | Skipped | Skipped | Optional larger profile not run; 2c/2g already had substantial memory headroom |

The July 18, 2026 `vps-1c-2g` run was performed after an in-place resize and reboot of the same VPS. The server had about 1.38 GiB available memory after testing, used no swap, and logged no new PHP-FPM `pm.max_children` warnings. Despite the added memory, the XML-handler burst tiers were slower than the 1-GB static-6 baseline. Treat this as evidence that the one-vCPU profile is CPU/scheduling-bound for these bursts; the next useful comparison is the 2-vCPU/2-GB resize.

The July 18, 2026 `vps-2c-2g` run used the same static-6 PHP-FPM profile, same WAN path, same WireGuard tunnel, and same 2 GiB swap. The host used no swap, retained about 1.36 GiB available memory after testing, and logged no new PHP-FPM saturation warnings. Doubling CPU count reduced `500 x 25` median p95 latency from 1466 ms on `vps-1c-2g` to 246 ms and raised throughput from 18.625 to 30.215 requests/sec. This strongly suggests CPU scheduling/count was the dominant limiter once memory headroom was adequate.

Before destroying the remote test VPS, a focused 2-vCPU/2-GB PHP-FPM worker sweep compared static 8, 10, and 12 against the static-6 controlled baseline using the `500 x 25` mixed tier. All candidates returned zero failures and no swap use, but none improved throughput meaningfully. Higher worker counts introduced occasional slowest-response spikes, so static 6 remained the active and recommended 2-GB profile.

| 2-vCPU/2-GB PHP-FPM profile | `500 x 25` req/sec | Estimated average | Estimated fastest | Measured slowest | Result |
| --- | ---: | ---: | ---: | ---: | --- |
| Static 6 | 30.215 | ~159 ms | ~80 ms | 414 ms | Selected |
| Static 8 | 30.117 | ~156 ms | ~78 ms | 858 ms | Rejected; no throughput gain and worse slowest response |
| Static 10 | 28.802 | ~149 ms | ~75 ms | 894 ms | Rejected; lower median throughput and worse slowest response |
| Static 12 | 29.472 | ~152 ms | ~76 ms | 957 ms | Rejected; no throughput gain and worse slowest response |

The `500 x 10` tier remains a safety step for small VPS profiles. It does not replace `500 x 25` in the comparison matrix: run `500 x 25` only after `500 x 10` is healthy, or record why it was skipped.

### Legacy Detailed Capacity Results

Add one row per measured run. Preserve the JSON report and sampler log named by hardware profile, tier, and repetition so every table entry can be audited. Put the median of the three controlled repetitions in the compact comparison matrix and keep individual repetitions in the detailed results or linked artifacts. Record the pre-run network RTT in the result/report field for every remote row.

| Hardware | Tier | Success | req/sec | p50 | p95 | p99 | max | Peak CPU | Peak RAM | Peak swap | PHP-FPM saturation | Result/report |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- | --- |
| `virtualbox-4c-4g` | `100 x 5` | 100/100 | 19.011 | 195 ms | 312 ms | 449 ms | 454 ms | Not captured | Not captured | Not captured | No new warnings | Passed; historical local baseline |
| `virtualbox-4c-4g` | `500 x 25` | 500/500 | 28.169 | 582 ms | 863 ms | 1003 ms | 1093 ms | Not captured | Not captured | Not captured | No new warnings | Passed; historical local baseline |
| `virtualbox-4c-4g` | `1000 x 25` | 1000/1000 | 25.793 | 647 ms | 982 ms | 1130 ms | 1245 ms | Not captured | Not captured | Not captured | No new warnings | Passed; historical local baseline |
| `vps-1c-1g` default r1 | `100 x 5` | 100/100 | 17.057 | 227 ms | 339 ms | 350 ms | 350 ms | Sampler retained | Sampler retained | 91 MiB post-series | No warning recorded | Passed; WAN RTT approximately 35 ms |
| `vps-1c-1g` default r2 | `100 x 5` | 100/100 | 19.179 | 204 ms | 255 ms | 303 ms | 304 ms | Sampler retained | Sampler retained | 91 MiB post-series | No warning recorded | Passed; WAN RTT approximately 35 ms |
| `vps-1c-1g` default r3 | `100 x 5` | 100/100 | 18.913 | 211 ms | 268 ms | 280 ms | 303 ms | Sampler retained | Sampler retained | 91 MiB post-series | No warning recorded | Passed; WAN RTT approximately 35 ms |
| `vps-1c-1g` default r1 | `500 x 25` | 500/500 | 23.519 | 604 ms | 1033 ms | 1123 ms | 1259 ms | Up to 95% busy | 473–479 MiB available post-run | 91 MiB | Saturation warnings observed | Passed; controlled default report retained |
| `vps-1c-1g` default r2 | `500 x 25` | 500/500 | 21.582 | 655 ms | 1123 ms | 1275 ms | 1352 ms | Sampler retained | 473–479 MiB available post-run | 91 MiB | Saturation warnings observed | Passed; controlled default report retained |
| `vps-1c-1g` default r3 | `500 x 25` | 500/500 | 23.565 | 615 ms | 1020 ms | 1118 ms | 1157 ms | Sampler retained | 473–479 MiB available post-run | 91 MiB | Saturation warnings observed | Passed; controlled default report retained |
| `vps-1c-1g` default r1 | `1000 x 25` | 1000/1000 | 22.086 | 646 ms | 1093 ms | 1420 ms | 1921 ms | Up to 95% busy | 456 MiB available post-run | 91 MiB | Saturation warnings observed | Passed; controlled default report retained |
| `vps-1c-1g` static-6 r1 | `100 x 5` | 100/100 | 20.285 | 210 ms | 266 ms | 329 ms | 329 ms | Sampler retained | 440 MiB available post-series | 91 MiB | No warnings | Passed; tuned report retained |
| `vps-1c-1g` static-6 r2 | `100 x 5` | 100/100 | 20.321 | 206 ms | 287 ms | 345 ms | 349 ms | Sampler retained | 440 MiB available post-series | 91 MiB | No warnings | Passed; tuned report retained |
| `vps-1c-1g` static-6 r3 | `100 x 5` | 100/100 | 19.867 | 214 ms | 305 ms | 314 ms | 316 ms | Sampler retained | 440 MiB available post-series | 91 MiB | No warnings | Passed; tuned report retained |
| `vps-1c-1g` static-6 r1 | `500 x 25` | 500/500 | 24.233 | 614 ms | 982 ms | 1081 ms | 1114 ms | Up to 97% busy | 440 MiB available post-series | 91 MiB | No warnings | Passed; tuned report retained |
| `vps-1c-1g` static-6 r2 | `500 x 25` | 500/500 | 24.040 | 634 ms | 1007 ms | 1051 ms | 1080 ms | Sampler retained | 440 MiB available post-series | 91 MiB | No warnings | Passed; tuned report retained |
| `vps-1c-1g` static-6 r3 | `500 x 25` | 500/500 | 24.024 | 643 ms | 1003 ms | 1061 ms | 1096 ms | Sampler retained | 440 MiB available post-series | 91 MiB | No warnings | Passed; tuned report retained |
| `vps-1c-1g` static-6 r1 | `1000 x 25` | 1000/1000 | 23.034 | 654 ms | 1068 ms | 1164 ms | 1280 ms | Up to 98% busy | 440 MiB available post-run | 91 MiB | No warnings | Passed; tuned validation report retained |
| `vps-1c-2g` static-6 r1 | `100 x 5` | 100/100 | 13.567 | 207 ms | 318 ms | 540 ms | 543 ms | Single CPU busy during bursts | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; WAN RTT approximately 35 ms; direct XML runner |
| `vps-1c-2g` static-6 r2 | `100 x 5` | 100/100 | 13.403 | 222 ms | 309 ms | 378 ms | 413 ms | Single CPU busy during bursts | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; WAN RTT approximately 35 ms; direct XML runner |
| `vps-1c-2g` static-6 r3 | `100 x 5` | 100/100 | 12.582 | 240 ms | 379 ms | 434 ms | 447 ms | Single CPU busy during bursts | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; WAN RTT approximately 35 ms; direct XML runner |
| `vps-1c-2g` static-6 r1 | `500 x 25` | 500/500 | 19.770 | 1088 ms | 1337 ms | 1446 ms | 1633 ms | Single CPU saturated | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; direct XML runner |
| `vps-1c-2g` static-6 r2 | `500 x 25` | 500/500 | 18.625 | 1189 ms | 1466 ms | 1590 ms | 1708 ms | Single CPU saturated | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; direct XML runner |
| `vps-1c-2g` static-6 r3 | `500 x 25` | 500/500 | 15.882 | 1426 ms | 1784 ms | 1888 ms | 1992 ms | Single CPU saturated | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; direct XML runner |
| `vps-1c-2g` static-6 warm r4 | `500 x 25` | 500/500 | 18.264 | 1204 ms | 1485 ms | 1569 ms | 1609 ms | Single CPU saturated | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; warm confirmation run; direct XML runner |
| `vps-1c-2g` static-6 r1 | `1000 x 25` | 1000/1000 | 16.821 | 1337 ms | 1605 ms | 1730 ms | 1966 ms | Single CPU saturated | 1.36 GiB available post-run | 0 MiB | No warnings | Passed; direct XML runner |
| `vps-2c-2g` static-6 r1 | `100 x 5` | 100/100 | 16.658 | 145 ms | 206 ms | 245 ms | 261 ms | Two CPU profile, no saturation warnings | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; WAN RTT approximately 36-41 ms; direct XML runner |
| `vps-2c-2g` static-6 r2 | `100 x 5` | 100/100 | 18.584 | 128 ms | 162 ms | 177 ms | 186 ms | Two CPU profile, no saturation warnings | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; WAN RTT approximately 36-41 ms; direct XML runner |
| `vps-2c-2g` static-6 r3 | `100 x 5` | 100/100 | 17.864 | 130 ms | 157 ms | 175 ms | 181 ms | Two CPU profile, no saturation warnings | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; WAN RTT approximately 36-41 ms; direct XML runner |
| `vps-2c-2g` static-6 r1 | `500 x 25` | 500/500 | 30.215 | 159 ms | 246 ms | 299 ms | 331 ms | Two CPU profile, no saturation warnings | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; direct XML runner |
| `vps-2c-2g` static-6 r2 | `500 x 25` | 500/500 | 29.831 | 157 ms | 224 ms | 315 ms | 365 ms | Two CPU profile, no saturation warnings | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; direct XML runner |
| `vps-2c-2g` static-6 r3 | `500 x 25` | 500/500 | 29.363 | 162 ms | 275 ms | 327 ms | 414 ms | Two CPU profile, no saturation warnings | 1.36 GiB available post-run series | 0 MiB | No warnings | Passed; direct XML runner |
| `vps-2c-2g` static-6 r1 | `1000 x 25` | 1000/1000 | 29.673 | 162 ms | 244 ms | 308 ms | 410 ms | Two CPU profile, no saturation warnings | 1.36 GiB available post-run | 0 MiB | No warnings | Passed; direct XML runner |
| `vps-2c-4g` | `100 x 5` | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Optional profile not run |
| `vps-2c-4g` | `500 x 25` | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Optional profile not run |
| `vps-2c-4g` | `1000 x 25` | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Skipped | Optional profile not run |

### SIPp Correctness Comparison

Keep SIPp results separate from XML-handler capacity. SIPp is a low-volume functional check and should not be presented as simultaneous-call capacity.

| Hardware profile | Registration/basic calls | Recording media | Music on hold | Announcement | Notes |
| --- | --- | --- | --- | --- | --- |
| `virtualbox-4c-4g` | Passed | Passed | Passed | Passed | July 16 local server-to-server validation |
| `vps-1c-1g` | Passed | Failed | Passed | Passed | WireGuard from `192.168.1.76`; recording dialplan immediately hung up and discarded the empty file |
| `vps-1c-2g` | Passed | Not rerun | Not rerun | Not rerun | Low-volume WireGuard subset passed: 5 registrations, 2 extension calls, 1 outbound call; XML curl POSTs observed |
| `vps-2c-2g` | Passed | Not rerun | Not rerun | Not rerun | Low-volume WireGuard subset passed: 5 registrations, 2 extension calls, 1 outbound call; XML curl POSTs observed |
| `vps-2c-4g` | Skipped | Skipped | Skipped | Skipped | Optional profile not run |

### SIPp End-To-End Calls Per Second

This is the test most people mean when they ask, "How many calls per second can
the PBX handle?" It is different from the XML `req/sec` test above.

For this test, SIPp acts like phones on both sides of the call:

1. SIPp registers test extensions with FreeSWITCH.
2. SIPp sends real SIP `INVITE` messages to FreeSWITCH at a chosen offered call
   rate, such as 5, 10, or 15 new calls per second.
3. FreeSWITCH processes the call, asks Laravel for XML directory/dialplan data
   through `mod_xml_curl` when needed, and bridges the call to the SIPp
   auto-answer side.
4. The destination SIPp side answers.
5. SIPp holds the call briefly, then sends `BYE` so FreeSWITCH tears the call
   down.

In plain language, one counted end-to-end call is a complete simulated phone
call setup and teardown through FreeSWITCH, not just one Laravel web request.
The PBX has to receive the caller's request, authenticate it, find the route,
create the other call leg, receive the answer, keep call state, and cleanly end
the call.

#### Invalidated `sessions-per-second=30` Runs

The first July 18, 2026 SIPp CPS runs used FreeSWITCH's stock
`sessions-per-second=30` safety throttle. Those results are no longer valid
capacity results and should not be quoted as clean calls-per-second capacity.

The reason is simple: `sessions-per-second` limits new FreeSWITCH sessions, not
whole calls. A normal extension-to-extension call creates two FreeSWITCH
sessions: one caller leg and one destination leg. That means the stock `30`
sessions/sec limit can reject calls near 15 two-leg calls/sec even if the
network, SIPp generator, Laravel, and FreeSWITCH CPU still have room.

The invalidated runs showed exactly that pattern:

- SIPp received `SIP/2.0 503 Maximum Calls In Progress`.
- `fs_cli -x status` showed `sessions per Sec out of max 30`.
- SIPp reported `0 UDP errors`.
- Both `eth0` and `wg0` network counters showed `0` packet errors and `0`
  packet drops.

Those artifacts remain available for audit, but the tables have been removed
from the valid capacity narrative.

#### Valid Remote Datacenter Result With `sessions-per-second=60`

The 2-vCPU/2-GB datacenter VPS was retested after raising FreeSWITCH to
`sessions-per-second=60`. This is the first valid remote end-to-end SIPp CPS
result from this series. The load generator was `192.168.1.76`, connected to
the public PBX through WireGuard. Artifacts are under
`storage/app/load-tests/capacity/vps-2c-2g-static6-sps60-20260718-sipp-cps`.

| Offered new calls/sec | Attempted calls | Completed calls/sec | Successful calls | Failed calls | Average setup time | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 15 r1 | 300 | 12.096 | 300 | 0 | 3,105 ms | Passed |
| 15 r2 | 300 | 13.240 | 300 | 0 | 1,282 ms | Passed |
| 20 r1 | 400 | 13.417 | 400 | 0 | 4,669 ms | Passed, but queued |
| 20 r2 | 400 | 14.491 | 400 | 0 | 3,833 ms | Passed, but queued |
| 25 | 500 | 14.158 | 385 | 115 | 6,205 ms | Failed |
| 30 | 600 | 17.181 | 377 | 223 | 6,764 ms | Failed |

The clean no-failure remote result is **20 offered calls/sec** with all 400
calls completed, but the average setup time was already several seconds. For
low-latency capacity, the safer interpretation is about **13-15 completed full
calls/sec** on this 2-vCPU/2-GB VPS. Above 20 offered calls/sec, failures return
even after removing the session-rate ceiling.

#### Post Tenant-Identity Cache Optimization Remote Result

On July 18, 2026, the 2-vCPU/2-GB datacenter VPS at `x.x.x.236` was
retested after further cache optimization in commit `30c8dea`. The optimization
caches FreeSWITCH tenant-identity resolution for repeated directory/auth XML
lookups. In plain language: when FreeSWITCH asks Laravel "which tenant/user is
this SIP auth request for?", Laravel can reuse a short-lived Redis answer
instead of repeatedly querying MariaDB and decrypting SIP account data during a
call burst. The generated directory XML itself was already cacheable; this
change also reduces the tenant/user lookup work done before that XML cache is
used.

The test used the correct remote datacenter topology:

- PBX under test: datacenter VPS `x.x.x.236`, WireGuard `10.77.0.1`
- SIPp load generator: `192.168.1.76`, WireGuard `10.77.0.2`
- SIP realm: `x.x.x.236`
- FreeSWITCH `sessions-per-second`: `60`
- XML handler cache stores: Redis

The first post-cache runs below still had FreeSWITCH's persistent switch log
level set to `debug`.

Artifacts:

- `storage/app/load-tests/capacity/remote-vps-identitycache-8cps-80calls-20260718`
- `storage/app/load-tests/capacity/remote-vps-identitycache-10cps-100calls-20260718`
- `storage/app/load-tests/capacity/remote-vps-identitycache-15cps-300calls-20260718`
- `storage/app/load-tests/capacity/remote-vps-identitycache-20cps-400calls-20260718`

| Offered new calls/sec | Attempted calls | Completed calls/sec | Successful calls | Failed calls | UDP errors | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 8 | 80 | 7.610 | 80 | 0 | 0 | Passed |
| 10 | 100 | 9.657 | 100 | 0 | 0 | Passed |
| 15 | 300 | 14.735 | 300 | 0 | 0 | Passed |
| 20 | 400 | 17.833 | 400 | 0 | 0 | Passed, with SIP retransmissions |

Laravel XML timing during the clean `10 cps / 100 calls` and
`20 cps / 400 calls` runs showed the cache optimization working on the
FreeSWITCH XML-curl path:

| Run | XML section | Requests | Avg total time | Avg DB queries | Avg DB time | Max total time |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| 10 cps | Directory/auth | 100 | 6.10 ms | 2.00 | 1.30 ms | 26.16 ms |
| 10 cps | Dialplan | 100 | 4.14 ms | 0.34 | 0.28 ms | 34.99 ms |
| 20 cps | Directory/auth | 400 | 12.02 ms | 1.00 | 2.35 ms | 58.10 ms |
| 20 cps | Dialplan | 400 | 9.42 ms | 0.25 | 0.50 ms | 100.53 ms |

This is a meaningful improvement in the Laravel XML-curl portion of call setup:
directory/auth lookup time stayed low even at the higher offered rate, and the
full `20 cps / 400 calls` SIPp run completed without failed calls or network
UDP errors. SIPp did report retransmissions at 20 cps, so the clean result
should be read as a successful throughput test with signaling pressure starting
to appear, not as proof that much higher rates will remain clean.

##### Same Remote VPS With FreeSWITCH Debug Logging Disabled

The same 2-vCPU/2-GB VPS was then retested with FreeSWITCH switch logging
lowered from `debug` to `notice`. XML handler timing was disabled for these
runs so the call path was measured without extra Laravel timing logs.

Artifacts:

- `storage/app/load-tests/capacity/remote-vps-notice-20cps-400calls-20260718`
- `storage/app/load-tests/capacity/remote-vps-notice-25cps-500calls-20260718`
- `storage/app/load-tests/capacity/remote-vps-notice-30cps-600calls-20260718`

| Offered new calls/sec | Attempted calls | Completed calls/sec | Successful calls | Failed calls | Average setup time | SIP retransmissions | UDP errors | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 20 | 400 | 15.990 | 400 | 0 | 3,121 ms | 533 INVITE, 18 provisional | 0 | Passed |
| 25 | 500 | 17.096 | 500 | 0 | 5,094 ms | 958 INVITE, 26 provisional | 0 | Passed, but heavily queued |
| 30 | 600 | 17.434 | 600 | 0 | 6,611 ms | 1,418 INVITE, 10 provisional | 0 | Passed, but heavily queued |

Disabling debug logging improved the failure outcome at the higher offered
rates: `25 cps / 500 calls` completed with zero failed calls, whereas the older
debug-logging baseline failed at 25 cps. However, it did not make the server
actually complete 25 new calls per second. The completed rate was about
`17.1 cps` at 25 offered cps and about `17.4 cps` at 30 offered cps. Average
setup time rose from about five seconds at 25 offered cps to about 6.6 seconds
at 30 offered cps, with many SIP INVITE retransmissions. The practical
interpretation is that disabling debug logging helps reduce enough overhead to
avoid outright failures, but the 2-vCPU / 2-GB VPS is still queuing call setup
heavily above roughly 15-18 completed calls/sec.

Before the remote VPS was marked safe to delete, final evidence bundles were
created and copied back to the project workspace:

- `storage/app/load-tests/final-evidence-20260718/pbx-final-vps-evidence-20260718.tar.gz`
- `storage/app/load-tests/final-evidence-20260718/pbx-final-loadgen-evidence-20260718.tar.gz`

Those archives contain sanitized WireGuard status/config snippets, FreeSWITCH
status, Sofia profile status, PHP-FPM configuration, Redis/cache checks, app
commit/config summaries, and SIPp artifact directories for the post-cache
remote runs. The remote datacenter VPS can be deleted after confirming those
archives have been copied to durable storage.

#### Valid VirtualBox Result With `sessions-per-second=60`

The VirtualBox PBX at `192.168.1.76` was also retested from the WSL SIPp load
generator at `192.168.1.65` after raising FreeSWITCH to
`sessions-per-second=60`. These runs should be treated as VirtualBox comparison
results, not production-capacity results. The VM was carrying background load
and failures came from local `mod_xml_curl` timeouts to the Laravel XML handler,
not from the FreeSWITCH session-rate ceiling or network packet loss.

Before repeating these VirtualBox SIPp CPS tests after a reboot, FreeSWITCH
restart, database refresh, or interrupted SIPp run, follow the recovery
runbook in `docs/sipp-server-to-server-validation.md` under
“VirtualBox CPS Lab Recovery After Reboot Or Interrupted Runs”. In short: run
the mandatory preflight gate first. It must confirm the PBX still has enabled
SIP account `2000`, the SIP realm is still `192.168.1.76`, the test password
still decrypts correctly, and the WSL auth CSV starts with an unchanged
`SEQUENTIAL` header followed by the authenticated `2000` row. If any preflight
item fails, reseed the PBX load-test tenant and regenerate the WSL auth CSV
before starting SIPp. Then clear stale SIPp processes, confirm Sofia `internal`
is running and UDP `5060` is listening, register users, wait for voicemail
`NOTIFY` packets to drain, and prove one complete call before recording a CPS
result.

Artifacts:

- `storage/app/load-tests/capacity/virtualbox-4c-4g-sps60-20260718-sipp-cps-r2`
- `storage/app/load-tests/capacity/virtualbox-4c-4g-sps60-20260718-sipp-cps-low-repeat`

| Offered new calls/sec | Attempted calls | Completed calls/sec | Successful calls | Failed calls | Average setup time | Result |
| ---: | ---: | ---: | ---: | ---: | ---: | --- |
| 5 | 100 | 4.629 | 100 | 0 | 703 ms | Passed |
| 8 | 160 | 5.574 | 160 | 0 | 4,253 ms | Passed, but queued |
| 10 r1 | 200 | 5.588 | 196 | 4 | 6,276 ms | Failed |
| 10 r2 | 200 | 5.184 | 123 | 77 | 6,742 ms | Failed |
| 12 | 240 | 8.299 attempted/created | 32 | 208 | 5,587 ms | Failed heavily |

The broader VirtualBox sweep reached complete failure at higher offered rates:
15 cps completed only 164/300 calls, 20 cps completed only 28/400, and 25 cps
or higher completed no calls. FreeSWITCH logs showed `mod_xml_curl` timeout
errors fetching the local XML handler and `CALL_REJECTED` hangups while network
counters still showed zero packet drops/errors. That means this VirtualBox run
is useful for finding the local XML-handler bottleneck, but it should not be
used as a production calls/sec capacity number.

#### Post Tenant-Identity Cache Optimization VirtualBox Result

The VirtualBox comparison PBX was also retested after the tenant-identity cache
optimization. This test should not be treated as production capacity because
VirtualBox was still carrying local/background load and the SIPp UAS also saw
the known voicemail `NOTIFY` harness artifact. It is still useful as a
before/after comparison for the Laravel XML-curl hot path.

Artifacts:

- `storage/app/load-tests/capacity/virtualbox-dbtime-8cps-80calls-rerun-20260718`
- `storage/app/load-tests/capacity/virtualbox-identitycache-8cps-80calls-retry3-20260718`

| VirtualBox run | Offered new calls/sec | Attempted calls | Completed calls/sec | Successful calls | Failed calls | UDP errors | Result |
| --- | ---: | ---: | ---: | ---: | ---: | ---: | --- |
| Before tenant-identity cache | 8 | 80 | 1.813 | 79 | 1 | 0 | Failed by one call |
| After tenant-identity cache | 8 | 80 | 4.912 | 79 | 1 | 0 | Failed by one call |

The cache change clearly reduced database work in the directory/auth XML path,
but did not produce a clean VirtualBox pass:

| VirtualBox XML section | Run | Requests | Avg total time | Avg DB queries | Avg DB time | Max total time |
| --- | --- | ---: | ---: | ---: | ---: | ---: |
| Directory/auth | Before cache | 80 | 129.75 ms | 4.50 | 62.76 ms | 420.97 ms |
| Directory/auth | After cache | 80 | 117.50 ms | 1.25 | 30.53 ms | 834.32 ms |
| Dialplan | Before cache | 80 | 50.91 ms | 0.35 | 2.07 ms | 284.25 ms |
| Dialplan | After cache | 79 | 75.31 ms | 0.39 | 2.36 ms | 377.26 ms |

Interpretation: the cache optimization helped the expected thing — repeated
tenant/user identity work before FreeSWITCH directory/auth XML generation. It
cut average directory/auth DB queries from `4.50` to `1.25` per request and
average directory/auth DB time from `62.76 ms` to `30.53 ms`. However, the
VirtualBox end-to-end SIPp result still had one failed call, including an
authenticated INVITE `403 Forbidden` and the separate SIPp UAS `NOTIFY`
artifact. Therefore the cache improvement should stay, but the remaining
VirtualBox failure is not solved by MariaDB/directory-cache work alone.

### Reading Load-Test Metrics

These numbers answer two simple questions:

1. How much work did the PBX complete?
2. How long did people have to wait for that work?

`req/sec` means **XML routing requests completed per second**. Each request is
one HTTP question asking Laravel to generate the routing instructions for one
possible call. It is not one completed SIP call. If a test reports `30 req/sec`,
Laravel produced about 30 XML routing answers every second during the test.
Higher is generally better, provided the answers are correct and the server
remains stable.

Do not relabel this number as `30 calls/sec`. A complete call creates additional
SIP signaling, a second call leg, optional RTP/media work, state inside
FreeSWITCH, and teardown work that this HTTP-only measurement does not include.

Average, minimum, and maximum describe **latency**, or how long individual
requests or call setups waited for an answer. These values are shown in
milliseconds (`ms`). There are 1,000 milliseconds in one second, so `250 ms`
is one quarter of a second and `1,000 ms` is one second. Lower is better.

- **Average** means add every wait time together and divide by the number of
  requests or calls. It answers, "About how long did one normally take across
  this whole test?"
- **Minimum**, also labelled **fastest**, is the single quickest answer in the
  test. It shows the best observed result.
- **Maximum**, also labelled **slowest**, is the single longest wait in the
  test. It shows the worst observed result.

For example, consider this result:

```text
30 req/sec | average 220 ms | fastest 110 ms | slowest 900 ms
```

In plain language, Laravel produced about 30 XML routing answers each second. A
request took `220 ms` on average. The quickest one took `110 ms`, and the
slowest one took `900 ms`. The large gap between the average and slowest result
shows that at least one request waited much longer than usual.

For end-to-end calls, the timing starts when the simulated caller first tries
to place the call and stops when the destination answers. A result of
`average 1,484 ms; fastest 682 ms; slowest 3,671 ms` means calls answered in
about one and a half seconds on average, the best call answered in under one
second, and the worst call took more than three and a half seconds.

Test names such as `500 x 25` mean **500 total requests with up to 25 being sent
at the same time**. The `25` is concurrency, not `25 req/sec`. The achieved
`req/sec` value tells us how quickly the server actually completed the work.

When comparing two successful runs:

- First check that neither run had failed responses. Speed does not matter if
  the PBX returns incorrect answers or errors.
- Prefer higher `req/sec` because more work was completed each second.
- Prefer a lower average because the overall wait was shorter.
- Check the fastest result to understand the best case when little or no work
  was queued.
- Treat the slowest result as a warning sign, especially if it happens again,
  rather than as the normal caller experience.

These measurements cover the XML routing response, not the entire time required
for a phone to ring or a person to answer. They are one important part of call
setup performance.

## Staged Run Tiers

Start small and increase only after the prior tier is stable. On the current
`192.168.1.76` beta host, which is a virtual machine running on Windows 11,
use these tests for repeat checks and relative before/after comparisons only.
Do not treat the results as VPS/datacenter capacity numbers.

| Tier | Dialplan Requests | Concurrency | Goal |
| --- | ---: | ---: | --- |
| Baseline | 25 | 1 | Confirm XML handler health and report output. |
| Small office burst | 100 | 5 | Confirm correct contexts, auth, XML shape, and no failed responses. |
| Moderate office burst | 500 | 10-25 | Catch PHP-FPM, MariaDB, Redis, and contributor problems that may return under practical VM load. |
| Optional medium stability | 1,000 | 25 | Confirm a longer small/medium burst stays stable after meaningful code or config changes. |

Avoid heavier tiers such as `10,000 x 100` on the Windows-hosted VM unless
the goal is deliberately destructive stress testing. For real capacity
claims, repeat the harness on a representative VPS or datacenter server and
record the server shape in the per-run report.

Stop escalation when any of these appear:

- XML handler latency spikes or returns non-2xx.
- MariaDB shows lock/connection pressure.
- Laravel CPU, memory, queue, or PHP-FPM workers saturate.
- Load generator CPU/network saturation appears.

## Per-Run Report

Record this after each run:

- date/time and git commit
- PBX server hardware/VM size
- load generator hardware/VM size
- hardware profile, repetition number, and controlled comparison series
- provider/region, instance identity, and whether the VPS was resized or replaced
- network path and pre-run round-trip latency
- Laravel/PHP-FPM/Nginx or web server versions
- seed command options
- dialplan load command and scenario
- target requests, achieved requests/sec, failed responses
- average/fastest/slowest XML handler latency
- Laravel app and PHP worker observations
- MariaDB observations
- suspected bottleneck
- next recommended tier or fix

## August 31, 2026 Public VPS Validation Benchmarks

Conducted on a minimum-hardware Debian 13 VPS (1 vCPU, 1 GB RAM, 2 GB swap) testing the real Nginx/PHP-FPM endpoint (`storage/app/load-tests/`):

| Metric / Run | 100 x 5 (Small Office Smoke) | 500 x 25 (Moderate Burst) |
|---|---|---|
| **Result** | ✅ **Thresholds Passed** | ⚠️ **Completed (0 Failures, Tail Latency Ceilings)** |
| **Completed Requests** | 100 / 100 (0 failures) | 500 / 500 (0 failures) |
| **Throughput** | 16.1 req/sec | 14.2 req/sec |
| **Average Latency** | 278 ms | 1,014 ms |
| **p50 (Median)** | ~250 ms | ~920 ms |
| **p95 Latency** | 418 ms (Threshold < 1,000 ms) | 1,716 ms |
| **p99 Latency** | 551 ms | 2,354 ms |
| **Finding** | Baseline production capacity verified on minimal 1-vCPU/1-GB VPS. | Tail latencies on 500x25 reflect single-vCPU CPU saturation with default Debian dynamic FPM pool; larger deployments recommend 2+ vCPU and static FPM worker tuning (`pm = static`, `pm.max_children = 12`). |

## Current Limitations

- The primary harness intentionally isolates Laravel dialplan XML generation from FreeSWITCH SIP/media handling.
- SIPp remains secondary for end-to-end setup/teardown after XML generation performance is understood.
- Feature-specific XML validation now covers call recording start/stop dialplan actions, local-stream music-on-hold configuration, and IVR announcement playback. Optional `MEDIA_FLOW=1` SIPp validation now covers low-volume live RTP checks for those paths. Default prompt and MOH assets come from FreeSWITCH sound packages, while app-managed recording and voicemail media remains file-backed with database paths and metadata only.
- CI now includes a tiny seeded XML handler smoke check through `PbxXmlHandlerSmokeTest`. It validates internal, inbound, outbound, and generated-cache dialplan responses without SIPp or high-volume traffic.
- The dialplan XML cache introduces a short propagation delay for control-panel changes, controlled by `FREESWITCH_XML_HANDLER_DIALPLAN_CACHE_TTL`.
