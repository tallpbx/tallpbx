# Status

Where the series stands right now. Updated when a phase completes, not continuously — if a number is not here, it is not measured yet.

## Current series — `cdr-entry-20261108` (CDR fast entry)

**Question:** what does serving CDR through `public/cdr-fast.php` (five providers) buy on the 1 vCPU tier, in calls completed and in CPU by process, against the same tests run with CDR through `index.php`?

**Build:** `5.x` @ `05cbe5e` (fast CDR entry + shared minimal bootstrap + fail-open fix + idempotent harness switches), host and generator as below.

| Step | State |
|:--|:--|
| Deploy to the PBX | **Done.** Fast CDR entry live; POST 200 with no `Access-Control-Allow-Origin` header, which only the full application adds |
| XML battery, `v-fast`, 100x5, production-60 | **Done.** 32.1 / 32.6 / 31.4 req/s, p50 114 ms, 100 % HTTP 200 — against 25.5 req/s mean in `refresh-20261103` |
| CPS ladder, `v-fast`, fast CDR arm | **Done.** 5 CPS: **300/300 calls, 601 CDR rows** against 276/300 and 46/552 before the fast entry. 8 CPS 102/480, 10 CPS 62/600, 15 CPS 53/847 — all three completed with the CDR gate passing and **zero FreeSWITCH restarts**; they previously could not finish at all |
| Octane sanity (XML only) | **Complete.** Resident RoadRunner/Octane worker benchmarked on 2 vCPU (286 req/s) and 4 vCPU shared (140.5 req/s sustained, 85 ms p50) on loopback :8090 |
| Pool-split decision (UI vs v-fast+CDR) | **Settled.** Evaluated and resolved by adopting a single dynamic pool with memory ceilings (`pm = dynamic`, `max_children = 3 / 6 / 12`, `pm.max_requests = 10000`) in the installer, eliminating the need for a split socket or separate pools |

**Rate ceiling on this tier:** 5 CPS serves 300/300; every rate above it degrades sharply (8 CPS 21 %, 10 CPS 10 %, 15 CPS 6 % of offered calls completed). The degradation tracks PHP-FPM worker saturation, not CDR ingestion, and it is the reason the comparison anchor is 5 CPS.

**Traps found and fixed this series** (now in `procedure.md` → Step 10):

- The SIP smoke now runs **per variant with that variant's switches applied**; the old smoke ran under the baseline and could pass while the variant under test could not register.
- `/etc/nginx/sites-available/pbx.loadtest.bak` is captured once and never refreshed; after a template change it must be refreshed, or the baseline restore undoes the new default.
- `503 Maximum Calls In Progress` at registration is the switch's session-rate limiter (`max-sessions` 1000, `sessions-per-second` 60) being consumed by internet scanners; check `fs_cli -x "show status"` before blaming the variant.
- FreeSWITCH restarted four times under the scanner flood; a restart inside a cell rejects the call as `CALL_REJECTED` while registration still passes. The runner now waits for readiness and fails a cell whose switch PID changed.
- **The harness unloaded `mod_xml_curl` on every cell**, even when the binding file already matched the wanted state. Unloading the module frees the binding structures while a lookup can still be using one: six core dumps (08:14–08:52) all fault in `xml_strstr` with a zeroed binding. Bindings are now applied by a FreeSWITCH restart, and only when the state actually differs — never by reloading the module.
- **The CPU sampler held its SSH channel open** for the whole sampling window, so SIPp started about six minutes late and the sample covered idle time. The sampler now forks detached from the channel.
- **Apply and restore rewrote files and restarted the switch or php-fpm unconditionally.** Both now compare the current state first and do nothing when it already matches, which keeps registrations alive across consecutive cells of the same variant.

**Host hardening applied for the series** (real traffic, not test probes):

| Layer | State | Evidence |
|:--|:--|:--|
| SIP scanner auto-ban (Attack Protection) | Enabled: enforcement on, 24 h bans, 5 min escalation window | Detector renders on `tenant_<id>_public` dialplans (3 hooks) and not on internal; queue worker and ESL listener active. It has not fired yet because the observed attackers are REGISTER floods, which never enter the dialplan, and no INVITE with a curated signature arrived during the sample window |
| VoIPBL external blocklist | Enabled, `119,419` entries synced into `external_blocklist_ips` | FreeSWITCH log fell from hundreds of lines per 15 s to ~1 |
| Geo-blocking | Whitelist mode, **US + CA** only; `18,098` CIDR lines in `geo_allowed_ips` | Orchestrator `x.x.x.93` whitelisted first (the lockout guard refuses to arm otherwise); panel 200; post-change SIP smoke from the US generator passes register, call and outbound |

**Lockout note:** enabling whitelist-mode geo without whitelisting the orchestrator address first will drop the measurement session. The rescue path is the Security Center's one-click *whitelist current IP*, or `LockoutGuardService::whitelistIp()` followed by `tpbx security:apply`.

---

**Last updated:** 2026-11-10
**Series:** `refresh-20261110`
**Tiers benchmarked:** `h1-1c1g` (1 vCPU, 967 MB, 3 CPS anchor completed), `h1-1c2g` (1 vCPU, 1,973 MB, v5.1 fast XML controller 25+ req/sec verified), `h2-2c2g` (2 vCPU, 2 GB RAM, 14-scenario SIP pass & Octane 286 req/s), and `h4-4c8g` (4 vCPU shared, 8 GB RAM, 14-scenario SIP pass, 461 MariaDB CDR rows reconciled, Octane 140.5 req/s sustained)
**Generator:** 1 vCPU, 967 MB, no firewall (`x.x.x.9`)
**Build:** `5.x`, working tree clean

---

## Progress against the plan

| Task | State |
|:--|:--|
| 0 — Preconditions | **Complete.** Install ran twice with byte-identical fingerprints (idempotent), CDR pipeline proven end to end, log `notice` and ESL `essential` verified by probe, firewall intact, generator neutral |
| 1 — Declarative matrix | Complete |
| 2 — Manifest command | Complete |
| 3 — Switch helpers | Complete; every `apply_*` has an `assert_*` |
| 4 — Matrix runner | Complete, including per-variant URL, lookup probe, `--ttl` filter and archive step |
| 5 — XML battery | **In progress.** All six variants measured at `production-60` with 3 repeats; the baseline also has the full six-policy sweep. Not yet measured: the failure ramp, the directory section, and the other load profiles |
| 6 — SIPp validation and ladder | **In progress.** The first cell passes end to end (20 registrations, 15 calls, 0 failures, CDR gate ok). The CDR gate the plan asked for now exists and is applied to every SIP cell. The other five variants and the call-rate ladder are still to run |
| 7 — Ablations | Not started (design exists: `fs-loglevel`, `fs-db`, plus the proposed ESL and throttle ablations) |
| 8 — Saturation profiling | Not started |
| 9 — CDR transport benchmarks | Not started |
| 10–14 — Gate, registers, report, doc reconciliation | Not started |

Only one of the five hardware tiers is provisioned, so at most one third of Stage A can run.

---

## Measured results

All cells: 100 requests, concurrency 5, dialplan section, 100 % HTTP 200, `failures.log` empty.

### Baseline variant `v-stock-dynamic` — full cache sweep, 3 repeats

| Cache policy | rps (r1 / r2 / r3) | p50 ms | p95 ms |
|:--|:--|--:|--:|
| `cache-hit-60` | 19.6 / 19.9 / 18.5 | 199–235 | 252–276 |
| `production-60` | 18.8 / 20.7 / 20.3 | 229–241 | 249–278 |
| `uniform-30` | 17.6 / 19.6 / 19.6 | 233–254 | 266–322 |
| `dialplan-off` | 16.9 / 17.1 / 18.3 | 258–268 | 286–344 |
| `cold` | 15.6 / 15.1 / 15.6 | 251–306 | 329–352 |
| `uncached` | 15.3 / 15.0 / 13.5 | 268–326 | 335–445 |

Caching is worth roughly 26 % end to end on this tier (20.7 best cached against 13.5 worst uncached). The order is monotonic — no inversion.

### Variant comparison — interleaved series, 3 rounds

Rounds interleave every variant, so host drift hits all six equally. Each cell is preceded by two warm-up passes and its report is verified before the number is used.

| Variant | rps (round 1 / 2 / 3) | mean | vs baseline |
|:--|:--|--:|:--|
| `v-octane` | 159.9 / 160.9 / 184.8 | **168.5** | **+970 %** |
| `v-fast` | 22.4 / 26.7 / 27.5 | 25.5 | **+62 %** |
| `v-lua-fast` *(retired historical)* | 21.6 / 26.6 / 28.1 | 25.4 | **+61.5 %** |
| `v-stock-static` | 16.2 / 18.4 / 18.0 | 17.6 | +11.5 % |
| `v-lua-stock` *(retired historical)* | 16.2 / 16.6 / 17.6 | 16.8 | +6.8 % |
| `v-stock-dynamic` | 16.5 / 16.9 / 13.8 | 15.7 | baseline |

*(Note: `v-lua-fast` and `v-lua-stock` are retired historical configurations per D16/D17 — benchmarking proved that the fast front controller and persistent worker eliminate framework overhead without embedded script maintenance complexities.)*

All cells: 100 % HTTP 200, every report verified, `failures.log` empty.

**Measured noise band: 25.6 %** — much wider than the 1.8 % the two-variant validation run produced. The cause is known and is a harness artefact, not measurement noise: every variant restarted the persistent worker, and the Lua variants restarted FreeSWITCH, putting a boot transient inside the cell that followed. Both now restart only when the Lua state actually changes (`c44d87e`) and two warm-up passes absorb what remains. The verdicts below survive the wide band — the two "real" results clear it by 2.4×, and Octane by 38× — but a re-run would tighten it.

### What each optimisation actually buys

The two serving paths answer two different questions, and each optimisation only shows up on its own path.

| Path | Lever | Effect | Evidence |
|:--|:--|:--|:--|
| HTTP request into PHP | **fast controller** | **+45 to +51 %** | `v-stock-static` 17.6 → `v-fast` 25.5; `v-lua-stock` 16.8 → `v-lua-fast` 25.4 |
| HTTP request into PHP | Lua proxy | **0 %** | `v-fast` 25.5 vs `v-lua-fast` 25.4; `v-stock-static` 17.6 vs `v-lua-stock` 16.8 |
| FreeSWITCH directory lookup | **Lua proxy** | **~40× faster** | probe: ~63 ms per lookup without Lua, ~1.5 ms with it |
| FreeSWITCH directory lookup | fast controller | 0 | `v-lua-stock` 1.5 ms ≈ `v-lua-fast` 1.3 ms |
| Either path | Octane worker | **+970 % on HTTP** | 168.5 rps against 15.7 |

Two consequences that were stated wrongly at first and are now settled by the data:

1. **The fast controller is the whole gain on the HTTP path.** Lua adds nothing there because a Lua hit never reaches PHP — the client posts straight to PHP, so the proxy is not in its path.
2. **The Lua proxy is the whole gain on the FreeSWITCH path.** In front of the fast controller and in front of the stock path it produces the same ~1.5 ms lookup, because it answers before either PHP path is consulted.

### The Lua proxy: three defects, all fixed

| # | Defect | Where | Effect |
|:--|:--|:--|:--|
| 1 | The switch never restarted the persistent worker | harness | The flag lived in `.env` but the process serving lookups kept `false` |
| 2 | The shared epoch key was seeded once and never updated | **product** | The handler built `…:e85` while PHP wrote `…:e210`; every lookup missed |
| 3 | `mod_lua` was never told to re-read `lua.conf.xml` | harness | The bindings stayed inert in the running module |

The product fix (`e3cd54e`) publishes the epoch through one method that keeps the shared key in step and only ever moves it upwards. The harness fixes (`62661ed`, `c44d87e`) restart FreeSWITCH when the binding state changes and prove the result behaviourally.

**Verified end to end**, by counting how many lookups reach PHP:

```
proxy off : 10 lookups -> 10 PHP requests   PHP served every one
proxy on  : 10 lookups ->  0 PHP requests   FreeSWITCH served every one
```

`apply_lua_bindings on` now refuses to pass unless the mirror is writable *and* the handler demonstrably serves, so this cannot silently regress into a measurement again.

### FreeSWITCH lookup probe — the measurement that sees Lua

Per lookup, from the interleaved rounds:

| Variant | probe, 30 lookups (r1 / r2 / r3) | per lookup |
|:--|:--|--:|
| `v-stock-static` | 2031 / 1906 / 1720 ms | **~63 ms** |
| `v-octane` | 2078 / 1592 / 1660 ms | **~59 ms** |
| `v-fast` | 132 / 1032 / 1060 ms | 4–35 ms, unstable |
| **`v-lua-stock`** | 55 / 38 / 39 ms | **~1.5 ms** |
| **`v-lua-fast`** | 38 / 40 / 13 ms | **~1.3 ms** |

The no-Lua numbers are ~60 ms because FreeSWITCH's first gateway is the Octane worker on `:8090`, so every lookup pays a worker round trip. With the proxy on, Redis answers first and that round trip disappears.

An earlier note here claimed Lua gave "no gain" and later that it paid off "only in front of the fast controller". Both were artefacts: the first from measuring a proxy that never served, the second from reading an HTTP table that cannot see the proxy at all. The table above is the first valid Lua measurement in the series.

### Service monitoring during the XML runs (sanity check)

The XML handler tests produce no SIP events, so the ESL listener and Reverb should be idle throughout. That is an assumption worth checking rather than asserting, because a service quietly burning CPU on every measurement would put a floor under the whole tier and make every comparison meaningless.

The whole monitored run — `v-stock-static` and `v-octane`, three repeats each, 131 samples at two-second intervals — was sampled on the host:

| Class | CPU average | CPU peak | RAM held (start → end) | RAM attributed (peak) |
|:--|--:|--:|:--|--:|
| `php-fpm` | 7.49 % | 109 % | 334.6 → 112.4 MB | 86.1 MB |
| `freeswitch` | 2.20 % | 16 % | 58.5 → 62.9 MB | 124.3 MB |
| `octane-worker` | 1.47 % | 2 % | 85.9 → 86.0 MB | 58.2 MB |
| `redis` | 0.69 % | 2.5 % | 12.1 → 11.8 MB | 5.1 MB |
| `cdr-worker` | 0.16 % | 1 % | 60.6 → 60.6 MB | 39.1 MB |
| `scheduler` | 0.11 % | 0.5 % | 46.7 → 46.7 MB | 24.2 MB |
| `queue-worker` | 0.07 % | 1 % | 71.4 → 70.0 MB | 48.1 MB |
| **`esl-listener`** | **0.02 %** | **0.5 %** | **80.5 → 80.4 MB** | 53.1 MB |
| **`reverb`** | **0.02 %** | **0.5 %** | **84.2 → 83.7 MB** | 56.8 MB |

**Verdict: both services are idle, and their memory does not move.** One clock tick in a two-second window is 0.5 % of a core, so the peak of 0.5 % is a single 10 ms slice — the listener and Reverb did no measurable work during the entire run. Their resident memory was flat to within a megabyte across 262 seconds of continuous load, so neither leaks nor grows a cache while the XML handler serves.

The `php-fpm` peak above 100 % is expected: the figure sums all five workers, so more than one core means the pool was saturated across several workers at once. `freeswitch` peaks at 16 % while serving lookups for the Octane variant.

The raw samples and the script that produced them (`load-testing/scripts/tools/cpuwatch.sh`) are in `monitoring/` beside the tier artifacts.

### Host memory state at the end of the variant sweep

```
              total   used   free  buff/cache  available
Mem:            967    627    142         341        340
Swap:          2047    228   1819
swappiness = 60        vmstat si/so = 0/0 (no active paging)
```

Resident services, largest first. The two columns are **RAM held** (the kernel's `RSS`: what the process has in memory) and **RAM attributed** (`PSS`: the same figure with pages shared between processes split among them, so this is the column to add up). Both terms are defined in [`reference.md` → Glossary](reference.md).

| Process | RAM held (RSS) | RAM attributed (PSS) |
|:--|--:|--:|
| `reverb:start` | 87.5 MB | 60.5 MB |
| `freeswitch:listen` (ESL) | 85.9 MB | 58.8 MB |
| `queue:work` | 65.2 MB | 43.2 MB |
| `cdr:work` | 60.6 MB | 38.6 MB |
| `roadrunner-worker` (Octane) | 59.8 MB | 35.9 MB |
| `php-fpm` worker (busiest of five) | 61.1 MB | 17.9 MB |
| `php-fpm` worker (typical of the other four) | ~57 MB | ~14.6 MB |
| `schedule:work` | 47.9 MB | 25.1 MB |
| `php-fpm` master | 44.7 MB | 9.8 MB |
| `rr` (RoadRunner supervisor) | 26.4 MB | 26.4 MB |

Summing RSS gives ~768 MB, which exceeds the 627 MB the kernel reports as used: shared pages — the PHP binary and the vendor tree every Laravel process maps in — are counted in full by each process. Summing PSS gives **~375 MB**, the real footprint of these processes.

**How to read this.** No paging is happening (`si/so` are zero), so the numbers above were measured with every page resident — the earlier ~50 ms lookup was a cold page cache, not sustained swap thrashing. The 228 MB in swap is residue from the clone, not active pressure.

The constraint is headroom, not current pressure: the PHP daemons account for roughly 375 MB of 967 MB, leaving about 340 MB available. A useful side observation: each of the five `php-fpm` workers *looks* like 57 MB of RSS but is really about 15 MB of its own, because three quarters of it is shared code. Two consequences for the work still queued:

1. The `directory` section (next action) runs on PHP-FPM and is not expected to be distorted by memory, provided nothing else is added alongside it.
2. The `fs-db` ablation (FreeSWITCH runtime database on tmpfs) would take its space from that same 340 MB. On this host it must be measured on its own, not alongside anything else, or it will create the swap pressure it is meant to test for.

Every cell also records `available_ram_mb` and `swap_used_mb` in its JSON, so this can be re-checked per run rather than trusted from a snapshot.

---

### Task 6 — SIP phase: the first cell

The XML phase measures document lookups. The SIP phase measures calls: endpoints register, calls are placed, and every completed call has to leave a record behind.

| Step | Result |
|:--|:--|
| `register` | 20 registrations, **20 successful**, 0 failed |
| `extension-calls` | 10 calls, **10 successful**, 0 failed |
| `outbound-calls` | 5 calls through the gateway, **5 successful**, 0 failed |
| CDR gate | **ok** — 25 new `cdrs` rows for the cell |

The 25 rows are 20 from the bridged extension calls (two legs each) and 5 from the outbound calls, which are recorded on the originating leg only. The gate compares the rows against the calls SIPp completed and fails the cell when a call leaves nothing behind.

This is the first cell of the SIP phase. One cell is a working measurement, not a comparison: the other five variants have to run through the same three steps before the phase says anything about the serving paths.

### The SIP realm: why a tenant could not register at all

Every endpoint of every tenant failed to register with 401/403, which reads like a credentials problem and is not one.

```
[WARNING] sofia_reg.c: Can't find user [2019@x.x.x.138] from x.x.x.55
```

FreeSWITCH resolves a registering user under the realm its profile names, and the tenant profiles carried the appliance-wide `$${domain}` — the box's own address. The directory has no users under that realm, so the lookup missed every time.

Two fixes, one in the product and one in the harness:

- **Product:** a tenant's SIP profile now names the tenant's own realm, and omits the keys entirely when the tenant has none, so the client's own domain survives (`203cfef`).
- **Harness:** the realm now comes from the host's own `FS_DEFAULT_SIP_REALM` rather than a hard-coded default, because a tenant seeded on a realm the PBX does not serve cannot register (`42899ed`).

The same burst that failed for every user now completes with zero failures.

### A cold directory lookup costs more than a client's retransmit timer

| Lookup | Cost |
|:--|--:|
| First lookup for an extension (cold) | **1131 ms** |
| The same lookup warm | **72 ms** |

SIPp retransmits a REGISTER after 500 ms. A cold lookup outlasts that, the request is sent twice, and FreeSWITCH answers the retransmission with a challenge of its own — which the scenario cannot place, so the step failed for a reason unrelated to what it measures. The scenario now sends the REGISTER once, and the runner warms the directory before registering.

### The outbound step: a false pass, and what it took to find it

The first cell that reported `outbound-calls: passed` was wrong, and the CDR rows are what prove it:

```
leg=a dest=91555123000 caller=2000 dur=40s bill=40s cause=NORMAL_CLEARING
```

One row per call, no second leg, and the call lasted exactly as long as the scenario's pause. The calls were answered — 180, then 200 — but by a component that never bridged them out. Across the whole session FreeSWITCH created **164 outbound legs and answered none of them**, while the generator's UAS log for that one working cell shows `Total-calls 5`.

The chain of causes, each one hiding behind the previous:

| # | Cause | How it presented |
|:--|:--|:--|
| 1 | The tenant was re-seeded with `--reset` on every invocation, so its id — which the dialplan context carries — changed every time | A registration made against the previous context routed into a context that no longer existed |
| 2 | The seeded outbound gateway pointed at `127.0.0.1` on the PBX | Every outbound call answered 503, cause 41 — which reads as "no route" |
| 3 | The dial pattern was stored over-escaped, so the route never matched | The call fell through to another extension that answered it locally — a *false pass* |
| 4 | The previous cell's background UAS still held ports 5066/5088 (`timeout 300`) | The next cell's UAS could not bind; the calls reached nobody, and the 503 looked identical to cause 2 |

The dialplan itself was never wrong. Proven directly:

```
Regex (PASS) [outbound_SIPp outbound 9-prefix] destination_number(91555123000) =~ /^9(\d{10})/
Action bridge(sofia/external/1555123000@x.x.x.55:5088)
```

and the bridge leaves the box and arrives:

```
PBX       Out IP x.x.x.138.5080 > x.x.x.55.5088: UDP, length 1082
Generator In  IP x.x.x.138.5080 > x.x.x.55.5088: UDP, length 1082
```

**What this changed in the harness:** the generator's SIPp ports are now cleared before every cell, and the CDR gate is to compare *legs* rather than rows — one row per call looks healthy even when nothing was bridged.

## Defects found and fixed

### Product code

| Defect | Effect |
|:--|:--|
| `scripts/resources/freeswitch.sh` had an unterminated quote | The whole FreeSWITCH resource step aborted, so `mod_json_cdr` was never provisioned and no CDR ever reached the table |
| `appliance:sync-host` used a blanket `UPDATE` on `tenant_domains` | A cloned host collided with the unique key and aborted the installer; now reconciles row by row |
| `pbx:load-test:seed` prefixed every `--output` with `base_path()` | An absolute path was written into a bogus `<app>/var/www/…` tree, and the working copy was left dirty enough to block the installer |
| Tenant SIP profiles forced the appliance-wide `$${domain}` | Every endpoint of every tenant answered 401/403 on registration, because the directory has no users under the appliance realm |

### Harness

| Defect | Effect |
|:--|:--|
| Log-level assertion parsed `fsctl loglevel` | Reports `DISABLE` on FreeSWITCH 1.11.4 even when logging works; replaced with a marker-pair probe |
| `apply_lua_bindings` used a pipe as `sed` delimiter | Every call died with "unknown option to `s`" and the Lua switch never applied |
| Octane cells posted to nginx | Six cells were labelled Octane but measured PHP-FPM; discarded |
| No FreeSWITCH-side measurement | A Lua hit was unobservable, so Lua variants could only ever look neutral |
| Restore left `FS_LUA_PROXY_ENABLED=true` with bindings commented | A half-applied pair that fills a mirror nothing reads; now reconciled on every restore |
| Matrix cache written into the artifact root | Every invocation, including `--list`, added an untracked file to a committed directory |
| Test runs archived into the real archive root | Pest output appeared beside measured data; the archive default now requires an unoverridden artifact root |
| SIPp ran against the local CSV instead of the credential file | A registration without an `Authorization` header draws a second challenge; the diagnostic trail pointed at credentials instead of at the missing column |
| The seeded outbound gateway pointed at `127.0.0.1` | Every outbound call answered 503 before a packet reached the generator |
| The CDR wait looked for a stable row count | The pipeline had not started writing, so three identical samples read as settled; the wait is now driven by the rows the completed calls require |
| The SIP realm was hard-coded to `load.test.local` | A tenant seeded on a realm the PBX does not serve cannot register at all |
| The REGISTER was retransmitted after 500 ms | A cold lookup (1131 ms) drew a second challenge the scenario could not place |

---

## Next actions, in order

> **2026-11-08:** the CDR comparison is closed on its anchor rate. What follows replaces the
> 2026-11-04 handoff, whose items were completed or superseded during this series.

1. **Pool-split decision (settled).** Evaluated and resolved: the single dynamic pool with memory ceilings (`pm = dynamic`, `max_children = 3 / 6 / 12`, `pm.max_requests = 10000`) was adopted across tiers in the installer, eliminating the need for a split socket or separate pools.
2. **Second anchor (Complete).** Confirmed on minimal tier (`h1-1c1g` / `refresh-20261110`): 3 CPS 60-second cell completed 180/180 calls (100 %), 0 failed, 361 CDR rows recorded, call setup p50 124 ms, p95 300 ms, max 464 ms, mean CPU PHP 10.3 % / FS 10.1 %, verifying linear sub-boundary performance.
3. **Octane sanity against the new bootstrap (optional).** One XML battery plus one SIP cell with
   the worker enabled, to prove the opt-in path still serves after the shared minimal bootstrap.
4. **Broader refresh work** — the extended scenarios, the ESL and throttle ablations, and the
   app-side timing decision — is tracked in `load-testing/decisions.md` and `docs/load-testing-results.md`.

Two things are deliberately *not* on this list: a special re-run to re-validate the Lua proxy
(the behavioural gate now proves it on every enable), and any further cache-policy sweep
(settled by D6).
