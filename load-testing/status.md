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
| 5 — XML battery | **In progress.** Baseline variant fully measured; two further variants at one cache policy; three variants not yet measured |
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

### Variant comparison at `production-60`, one repeat each

| Variant | rps | average ms | p50 ms | p95 ms |
|:--|--:|--:|--:|--:|
| `v-stock-dynamic` | 18.8 | 235 | 241 | 278 |
| `v-stock-static` | 17.9 | 259 | 253 | 304 |
| `v-fast` | 19.1 | — | 233 | — |

**These are single runs.** The spread between them is inside the ~10 % repeat noise, so no ranking is claimed. The baseline sweep above is the only part measured at three repeats.

### FreeSWITCH lookup probe

| Cache policy | find_user_ms | status_ms | net_ms |
|:--|--:|--:|--:|
| `cache-hit-60` | 1594 | 66 | 1528 |
| `cold` | 1594 | 99 | 1495 |
| `dialplan-off` | 1604 | 68 | 1536 |
| `uniform-30` | 1589 | 76 | 1513 |
| `production-60` | 1548 | 62 | 1486 |
| `uncached` | 1795 | 63 | 1732 |

Roughly 50 ms per directory lookup under load, flat across cache policies (correct — `find_user_xml` does not use the dialplan cache). An idle probe on the same host measured ~11 ms; see the open question below.

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

1. Repeat `v-stock-static` and `v-fast` at three repeats so their comparison is worth reading.
2. Measure `v-octane` at `:8090` — never measured yet.
3. Measure `v-lua-stock` and `v-lua-fast`, judged from the probe plus SIP, not the HTTP client.
4. Run the SIP phases for the surviving variants, including the ESL `full` and throttle-`0` ablations.
5. Resolve the two open questions that affect interpretation: the 50 ms vs 11 ms lookup gap, and whether app-side timing should be enabled.
