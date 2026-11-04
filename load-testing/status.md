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
| `v-lua-fast` | 30.3 | 150–155 | 162–181 | +52 % |
| `v-stock-dynamic` | 19.9 | 229–241 | 249–278 | baseline |
| `v-stock-static` | 19.4 | 237–242 | 281–300 | −3 % |
| `v-lua-stock` | 18.9 | 241–257 | 263–331 | −5 % |

All cells: 100 % HTTP 200, `failures.log` empty.

**What the numbers say**

1. **The persistent worker is in a different class.** 175.6 rps against 19.9, with p50 falling from ~235 ms to 11–26 ms. This is far outside the noise band and it is the number the earlier series was reaching for. It only exists because the runner now posts Octane cells at `:8090`; the six earlier cells that posted to nginx measured PHP-FPM and were discarded.
2. **The fast controller is worth about half again** on stock pools: 30.4 against 19.9, p50 down from ~235 to ~153 ms. Also outside the noise band.
3. **Pool mode is not a lever on this tier.** Static 19.4 against dynamic 19.9 is inside the noise band, consistent with a 5-child pool serving 5 concurrent clients.
4. **The Lua proxy shows no gain, and that is expected from this driver.** `v-lua-stock` 18.9 against 19.4; `v-lua-fast` 30.3 against 30.4. An HTTP client posts to PHP, so a Lua cache hit is never exercised. These two rows do **not** mean the proxy is worthless — they mean this measurement cannot see it (see D5).

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
2. Run the `directory` section for the two surviving paths, since `find_user_xml` and `pbx:load-test:dialplan --section=directory` measure different things.
3. Run the failure ramp (fixed concurrency, doubling total requests) for `v-octane` and `v-fast` to find where each saturates.
4. Run the SIP phases for the surviving variants, including the ESL `full` and throttle-`0` ablations.
5. Decide whether app-side timing (`timing.enabled`) is needed to split worker time from queue time.
