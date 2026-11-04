# Status

Where the series stands right now. Updated when a phase completes, not continuously — if a number is not here, it is not measured yet.

**Last updated:** 2026-11-03
**Series:** `refresh-20261103`
**Tier under test:** `h1-1c1g` — 1 vCPU, 967 MB, 2 GB swap, shared CPU (`x.x.x.138`)
**Generator:** 1 vCPU, 967 MB, no firewall (`x.x.x.55`)
**Build:** `5.x` @ `7c0ab05`, working tree clean

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
| 6 — SIPp validation and ladder | Not started |
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

### Variant comparison at `production-60`, 3 repeats each

| Variant | rps (mean) | p50 ms | p95 ms | vs baseline |
|:--|--:|--:|--:|:--|
| `v-octane` | **175.6** | 11–26 | 19–74 | **×8.8** |
| `v-fast` | 30.4 | 151–156 | 163–183 | **+53 %** |
| `v-lua-fast` | 29.7 | 154 | 186 | +49 % |
| `v-stock-dynamic` | 19.9 | 229–241 | 249–278 | baseline |
| `v-stock-static` | 19.4 | 237–242 | 281–300 | −3 % |
| `v-lua-stock` | 19.5 | 240 | 275 | −2 % |

All cells: 100 % HTTP 200, `failures.log` empty.

**What the numbers say**

1. **The persistent worker is in a different class.** 175.6 rps against 19.9, with p50 falling from ~235 ms to 11–26 ms. This is far outside the noise band and it is the number the earlier series was reaching for. It only exists because the runner now posts Octane cells at `:8090`; the six earlier cells that posted to nginx measured PHP-FPM and were discarded.
2. **The fast controller is worth about half again** on stock pools: 30.4 against 19.9, p50 down from ~235 to ~153 ms. Also outside the noise band.
3. **Pool mode is not a lever on this tier.** Static 19.4 against dynamic 19.9 is inside the noise band, consistent with a 5-child pool serving 5 concurrent clients.
4. **The Lua proxy produces no gain even when it is genuinely enabled.** `v-lua-stock` 19.5 against 19.4; `v-lua-fast` 29.7 against 30.4. The first run of these two variants is void — see the defect below. The second run had the proxy truly on, and it still matched the stock path exactly.

### The Lua proxy: two defects, one of them in product code

**Defect 1 (harness, fixed).** `apply_lua_bindings` wrote `FS_LUA_PROXY_ENABLED` to `.env` and cleared the config cache, but never restarted the processes that hold configuration in memory. PHP-FPM re-reads `.env` on every request; the persistent worker boots once and keeps its configuration for its whole life. The proxy was therefore **off in the process that served the lookups**, the mirror was never written, and the first run of both Lua variants measured the stock path. The switch now restarts `tallpbx-xml-worker`, and enabling the proxy must prove an identity key appeared in Redis before the cell may run.

**Defect 2 (product, open).** With the worker restarted, PHP writes the mirror correctly:

```
ttl=59  len=14026  pbx:fsxml:dir:17:domain:load.test.local:2000:v98
ttl=59  len=2      pbx:fsxml:tenant:user:load.test.local:2000:e210
```

but the epoch embedded in the identity key (`e210`) does not match the shared epoch key the Lua handler reads:

```
pbx:fsxml:ver:global = 85
```

`DirectoryXmlBuilder` publishes the identity key under `max($epoch, $mirrorEpoch)`, but `ver:global` is only *seeded* (`writeGlobalVersionIfMissing`) and never updated, so it still holds the older 85. The Lua handler reads 85, builds `…:e85`, and never finds the `…:e210` PHP wrote. **Every Lua lookup misses.** The mirror keys are structurally identical, but the epoch value disagrees, so the proxy cannot serve a single hit.

The cost of that miss is visible in the probe: with the proxy off, a FreeSWITCH directory lookup costs about 6.5 ms; with it on, the same lookup costs 35–52 ms, because every lookup pays for the Lua path *and* the PHP fallback.

**Consequence for the series.** The Lua proxy cannot be classified as "optional" or "no longer considered" from these numbers, because the numbers describe a proxy that always misses. The honest statement today is: *with the epoch mismatch present, the proxy is pure overhead on this tier.* Fixing defect 2 is a product change, and it is a separate decision from measuring it.

### FreeSWITCH lookup probe — two clear regimes

| When | net_ms for 30 lookups | Per lookup |
|:--|--:|--:|
| During the first baseline sweep (cold page cache, fresh clone) | 1486–1732 | ~50 ms |
| During the variant sweep (warm cache) | 173–215 | ~6–7 ms |

With `lua_bindings=on` the probe read 202 ms and 215 ms, against 196 ms for stock static with the bindings off. **Turning the Lua proxy on did not make a directory lookup faster.** No Lua script errors were logged today, and `mod_lua` is loaded before `mod_xml_curl`, so the binding is live; the likely explanations are an unpopulated Redis mirror or a key/DB mismatch between what Laravel writes and what the handler reads. That needs the mirror-TTL attribution described in D5 before any conclusion about the proxy is drawn.

The 50 ms to 6 ms difference also answers part of the earlier open question: the first sweep was measuring a cold SQLite page cache, not a property of the lookup path.

### ESL listener and Reverb under XML-only load

Alternating runs of the same cell, warm-up discarded:

| Condition | rps | p50 ms |
|:--|:--|--:|
| Both services running | 20.4 / 20.1 | 237 / 238 |
| Both services stopped | 20.0 / 19.3 | 238 / 243 |

CPU attribution over a 20-second window: `esl-listener` **0 ticks**, `reverb` **0 ticks**, `php-fpm` 29.75 % of one core, `freeswitch` 1.40 %, `mariadb` 0.85 %, `redis` 0.80 %, `octane-worker` 1.20 %.

Conclusion: neither service consumes measurable CPU during XML-only tests. Both remain at their baseline settings for XML work; the `full` and throttle-off comparisons belong to the SIP phases.

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

## Defects found and fixed

### Product code

| Defect | Effect |
|:--|:--|
| `scripts/resources/freeswitch.sh` had an unterminated quote | The whole FreeSWITCH resource step aborted, so `mod_json_cdr` was never provisioned and no CDR ever reached the table |
| `appliance:sync-host` used a blanket `UPDATE` on `tenant_domains` | A cloned host collided with the unique key and aborted the installer; now reconciles row by row |
| `pbx:load-test:seed` prefixed every `--output` with `base_path()` | An absolute path was written into a bogus `<app>/var/www/…` tree, and the working copy was left dirty enough to block the installer |

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

---

## Next actions, in order

1. Explain why `lua:on` does not speed up a directory lookup (mirror unpopulated, or key/DB mismatch) before recording any verdict on the Lua proxy.
2. Run the `directory` section for the two surviving paths, since `find_user_xml` and `pbx:load-test:dialplan --section=directory` measure different things. Memory headroom is 340 MB with no active paging, so this is expected to be clean; the `fs-db` tmpfs ablation is the one that must run alone.
3. Run the failure ramp (fixed concurrency, doubling total requests) for `v-octane` and `v-fast` to find where each saturates.
4. Run the SIP phases for the surviving variants, including the ESL `full` and throttle-`0` ablations.
5. Decide whether app-side timing (`timing.enabled`) is needed to split worker time from queue time.
