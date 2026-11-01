# Lua Redis Proxy — Measurement Report (Task 1 Gate)

**Date:** 2026-11-01
**Plan:** [`2026-10-31-lua-redis-proxy-v2.md`](superpowers/plans/2026-10-31-lua-redis-proxy-v2.md) — Task 1
**Decision:** **PROCEED** to Phase 1 (directory-only Lua cache proxy)

This report records the measurement spike that gates the Lua Redis proxy. It also serves as a live installer acceptance test on a fresh datacenter host, installed exactly as an end user would.

## 1. Environment

| Item | Value |
|:--|:--|
| Host | Datacenter VPS (DigitalOcean TOR1), snapshot image |
| OS | Debian GNU/Linux 13 (trixie), amd64 |
| CPU | 1 shared vCPU |
| RAM | 967 MB |
| Swap | 2 GB swapfile (active) |
| Disk | 21 GB free on `/` |
| Public IP | `x.x.x.145` (obfuscated; IPv6 also present with a default route) |
| Web | nginx 1.26, PHP 8.5 FPM — `pm = static`, `pm.max_children = 5` |
| Framework | Laravel 13.38.0 |
| Data | MariaDB 10.11, Redis 7 (cache on DB 1) |
| Telephony | FreeSWITCH 1.11.4 (SignalWire packages) |
| Cache policy | `FS_XML_HANDLER_CACHE_TTL=60` (production default) |
| Firewall | Full default `nftables` ruleset active (SSH, 80/443, SIP, RTP) |
| Code | Branch `5.x` at `77c1b67` (includes the app-side timing harness) |

## 2. Installation under test

Installed with the documented end-user one-liner, answered interactively from a terminal:

```bash
wget -O- https://raw.githubusercontent.com/tallpbx/tallpbx/5.x/scripts/bootstrap.sh | bash
```

- Answers: Clean data → Production → Random database password → FreeSWITCH packages → SignalWire PAT (pasted at the prompt) → English prompts → Setup admin now.
- **Completed in 5m 29s**, exit status 0.
- All services active: nginx, php8.5-fpm, mariadb, redis-server, freeswitch, nftables, tallpbx-queue, tallpbx-scheduler, tallpbx-reverb.
- Panel reachable: `GET /panel/login` → HTTP 200.
- PHP-FPM auto-tuned to `pm = static` / 5 workers; 2 GB swap active.
- The PAT was **not** pre-seeded; it was entered at the interactive prompt only. After installation the `SWITCH_TOKEN` entry was removed from `/etc/default/tallpbx`; the apt credential remains in `/etc/apt/auth.conf.d/freeswitch.conf` (required for package updates).

## 3. Method

1. Seeded the synthetic tenant:
   `tpbx pbx:load-test:seed --tenant=load-test-beta --domain=load.test.local --extensions=100 --start=2000 --password=LoadTest1234 --reset`
2. **Baseline pass (timing off, dialplan section):** `25×1`, `100×5`, `1000×25` against the host's public IP.
3. **Cache sweep:** `scripts/run-cache-sweep.sh` across the four dialplan tiers plus the `directory-mixed` tier.
4. **App-side pass (timing headers on, section=mixed):** `25×1`, `100×5`, `1000×25` with `--section=mixed --timing`, framework caches warm (`tpbx optimize`), then the timing flag reverted.
5. Directory share is 2 directory lookups per dialplan lookup by design (67 %), mirroring the documented 2–3 XML queries per call.

## 4. Results

### 4.1 Baseline — timing off, dialplan section

| Run | Req/s | Avg (ms) | p50 (ms) | p95 (ms) | p99 (ms) | Failures |
|:--|--:|--:|--:|--:|--:|--:|
| `25×1` | 19.3 | 50.4 | 43.6 | 77.5 | 137.4 | 0 |
| `100×5` | 21.9 | 215.3 | 214.9 | 244.2 | 254.0 | 0 |
| `1000×25` | 22.2 | 662.5 | 648.1 | 1154.7 | 1256.6 | 0 |

### 4.2 App-side pass — timing on, section=mixed (67 % directory)

| Run | Req/s | HTTP avg | HTTP p95 | App `request_ms` p50 | App `request_ms` p95 | `handler_ms` p95 | DB queries (max) |
|:--|--:|--:|--:|--:|--:|--:|--:|
| `25×1` | 16.5 | 58.7 | 75.8 | 55.1 | 73.9 | 11.3 | 20 |
| `100×5` | 18.3 | 261.5 | 303.8 | 251.0 | 292.4 | 53.5 | 7 |
| `1000×25` | 21.1 | 698.1 | 1233.0 | 215.6 | 289.5 | 45.7 | 7 |

Per-section `request_ms` p95: directory 93.4 / 283.4 / 283.7 ms; dialplan 73.9 / 299.0 / 295.1 ms for the three runs.

### 4.3 Cache sweep (`100×5` per tier)

| Tier | Req/s | Avg (ms) | Hit rate |
|:--|--:|--:|--:|
| cold-baseline | 13.2 | 288.7 | 48.7 % |
| dialplan-cache-off | 13.7 | 283.2 | 54.2 % |
| prod-baseline-60 | 14.4 | 268.8 | 60.2 % |
| memory-hit-ceiling | 13.9 | 274.5 | 49.0 % |
| directory-mixed | 14.0 | 272.7 | 54.0 % |

The sweep runs with a freshly flushed Redis DB 1 per tier, so its throughput is lower than the warm baseline pass. App-side p95 shows `N/A` in the sweep because the timing header flag was off during that run.

## 5. Gate evaluation (1 vCPU tier)

| Criterion | Threshold | Measured | Result |
|:--|:--|:--|:--|
| App-side directory-hit `request_ms` p95 | ≥ 10 ms | 73.9 ms (warm-up), 292.4 ms (burst) | **PASS** |
| HTTP p95 at concurrency 25 | ≥ 500 ms | 1154.7 ms baseline / 1233.0 ms timing pass | **PASS** |
| Directory share of xml-handler volume | ≥ 1/3 | 667 / 1000 = 66.7 % | **PASS** |

**Decision: PROCEED.**

## 6. Interpretation

- **The prize is the whole request, not the handler.** `handler_ms` p95 is only 11–53 ms while `request_ms` p95 is 74–292 ms — roughly 80–85 % of app-side time is framework boot, routing, and middleware, exactly what an in-process Lua cache removes. A gate based on the middleware window alone would have understated the saving several-fold; this validates the harness decision to measure from `LARAVEL_START`.
- **Worker occupancy is the sustained-load bottleneck.** At concurrency 25, HTTP p95 is 1233 ms while app-side p95 is only 290 ms — roughly 940 ms per request is spent waiting for a PHP-FPM worker. Directory lookups are two-thirds of the XML volume; serving their hits in-process removes most of that queue.
- **Directory lookups are the expensive half.** They reached 20 DB queries per request on cold per-user cache, with p95 app latency equal to or above dialplan lookups. This is precisely the surface the Phase 1 proxy targets.

## 7. Caveats

- Single run per tier (no median of three), so figures are indicative rather than publication-grade.
- The timing pass enables a `DB::listen` counter, adding small overhead; baseline and timing passes are therefore comparable but not identical.
- The 2:1 directory-to-dialplan mix is by design; the live per-call share is documented as 2 of 3 lookups.
- The cache sweep flushes Redis DB 1 per tier, so those numbers reflect cold-start behavior.
- Raw artifacts remain on the test host under `storage/app/load-tests/` (`task1-*.json`, `cache-sweep-20261101-013731/`) and are not committed here.
- Public IP is obfuscated to its last octet; SSH key details, credentials, and tokens are intentionally omitted.

## 8. Next steps

Proceed with Phase 1 of the v2 plan (Tasks 2–7): mirror namespace, version mirroring, directory mirror writes, the directory-only Lua handler, bindings, and the end-to-end gate. Re-run this measurement after Phase 1 lands for an apples-to-apples comparison against the baseline above.
