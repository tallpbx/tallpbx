# XML Handler Performance — Four-Way Comparison

**Date:** 2026-11-01
**Plan:** [`2026-10-31-lua-redis-proxy-v2.md`](superpowers/plans/2026-10-31-lua-redis-proxy-v2.md) — Task 8
**Purpose:** measure the two optional speed-ups independently and together, so the decision to keep or remove either one rests on a number.

> **Scope warning — read before trusting any row.** Every measurement here is a **directory lookup**. The Lua proxy serves directory only, so its column compares a feature covering a fraction of the traffic against one covering all of it. Dialplan is at least one lookup per call leg and dominates real volume. **The proxy conclusions are therefore provisional and apply to directory traffic alone**; a fair side-by-side needs the dialplan mirror (Task 9) and a repeat of these runs.

## 1. Environment

All four configurations were measured in one session, on one host, with identical request counts.

| Item | Value |
|:--|:--|
| Host | Existing datacenter test server (`x.x.x.145`; public address obfuscated per project convention) |
| OS | Debian GNU/Linux 13 (trixie), amd64 |
| CPU | 1 shared vCPU |
| RAM | 967 MB |
| Swap | 2 GB |
| Disk free | 18 GB |
| PHP | 8.5.12, FPM `pm = static`, `pm.max_children = 5` |
| FreeSWITCH | 1.11.4 (SignalWire packages) |
| Cache policy | `FS_XML_HANDLER_CACHE_TTL=60` |
| Tenant | `load-test-beta`, domain `load.test.local`, extension `2000` |

## 2. Configurations

| ID | Fast controller | Lua proxy | What it represents |
|:--|:--:|:--:|:--|
| **A** | off | off | Stock install, no optimisation |
| **B** | off | on | Lua proxy only |
| **C** | on | on | Both enabled |
| **D** | on | off | Fast controller only |

The fast controller is switched by the nginx `location` for `/api/v1/xml-handler` — `try_files … /index.php` when off, `fastcgi_param SCRIPT_FILENAME …/xml-handler-fast.php` when on. The Lua proxy is switched by the two `xml-handler-*` parameters in `lua.conf.xml` plus a FreeSWITCH restart. The `FS_LUA_PROXY_ENABLED` flag alone is **not** a kill switch: it only stops new mirror writes.

## 3. Method

Two measurements per configuration, because they answer different questions.

1. **Lookup cost as FreeSWITCH sees it.** Thirty `fs_cli -x "find_user_xml id 2000 load.test.local"` calls timed together, minus thirty `fs_cli -x status` calls on the same host. The subtraction removes the command-line client's fixed overhead, which is several milliseconds per call and otherwise dominates the result. This is the only measurement that observes a Lua **hit**, because a hit never reaches nginx or PHP.
2. **Web-app cost when PHP is asked.** `pbx:load-test:dialplan --section=directory --requests=100 --concurrency=1 --timing`, reporting app-side `request_ms` p50/p95. With the proxy on, this measures **misses plus mirror writes** — the harness always reaches PHP.

## 4. Results

### 4.1 What FreeSWITCH waits for

| ID | Fast | Lua | FS floor (ms) | 30 lookups (ms) | **Per lookup (ms)** |
|:--|:--:|:--:|--:|--:|--:|
| **A** | off | off | 124 | 1565 | **48.0** |
| **B** | off | on | 189 | 200 | **0.4** |
| **C** | on | on | 126 | 216 | **3.0** |
| **D** | on | off | 136 | 1290 | **38.5** |

### 4.2 Web-app load tests (directory section, timing headers on)

Throughput in requests per second:

| Tier | **A** stock | **B** proxy only | **C** both | **D** fast only |
|:--|--:|--:|--:|--:|
| 100 × 1 | 19.1 | 19.1 | **30.5** | 28.9 |
| 100 × 5 | 22.4 | 21.5 | **32.1** | 33.0 |
| 500 × 25 | 21.3 | 19.8 — 27.4 | 27.7 — 30.7 | **30.0** |

App-side latency, p50 / p95 in milliseconds:

| Tier | **A** stock | **B** proxy only | **C** both | **D** fast only |
|:--|:--|:--|:--|:--|
| 100 × 1 | 49.4 / 61.7 | 50.9 / 58.7 | **30.6 / 35.9** | 32.6 / 41.1 |
| 100 × 5 | 212.8 / 245.5 | 223.0 / 257.5 | 145.0 / 174.1 | **143.1 / 162.5** |
| 500 × 25 | 668.8 / 1173.6 | 707.3 / 1333.2 | 492.5 / 948.3 | **476.3 / 821.9** |

Zero failed requests in every retained run.

> **One run was discarded.** The first 500 × 25 pass for configuration C produced 30 timeouts and a 10-second tail. Two immediate re-runs on the same host produced 27.7 and 30.7 requests/s with zero failures and p95 of 948 ms and 815 ms, so the figure above is the re-runs. The timeout did not reproduce and is treated as a transient rather than a property of the configuration.

> **Ranges where a tier was repeated.** Configuration B was run twice at 500 × 25 (19.8 and 27.4 requests/s, p95 1333 and 829 ms). At this concurrency a 1 vCPU host with five workers is queueing heavily, and run-to-run spread is large enough that a single number overstates precision.

## 5. What the numbers say

**The two features act on different halves of the request.**

- **The fast controller is the only thing that moves the load-test columns.** Removing 56 of the 90 startup components cuts app-side p50 by 34–35 % at concurrency 1 (49.4 → 32.6 ms) and holds throughput near 30 requests/s at every tier. Without it, the host caps at about 21 requests/s from concurrency 5 upward — configuration A is flat from 100 × 5 to 500 × 25, which is the signature of a saturated worker pool rather than a slow handler.
- **The Lua proxy is invisible in those columns by construction**, because the harness always asks PHP. It shows up only in §4.1, where a lookup falls from 48.0 ms to under 3 ms. Adding the proxy to the load test slightly *hurts* the HTTP figure (A 19.1 vs B 19.1 at concurrency 1; 22.4 vs 21.5 at concurrency 5) because each PHP render now also writes mirror keys.
- **Together they cover both paths.** C keeps the cheap hit and the cheaper miss: 30.5 requests/s at concurrency 1 against 19.1 for stock, and a lookup cost an order of magnitude below stock.

**What this means for a real deployment:** a phone ringing at a quiet moment waits under 3 ms instead of 48 ms with the proxy on, and a burst of registrations is absorbed roughly 50 % faster with the fast controller on.

## 6. Recommendation

| Feature | Keep? | Justification |
|:--|:--|:--|
| **Lua proxy** | **Provisional — directory only** | 48.0 ms → 0.4–3.0 ms per **directory** lookup. It does nothing for dialplan, which is the busier path, so this result cannot justify the feature as a whole. The verdict waits on Task 9 and a repeat of these runs across all sections. |
| **Fast controller** | **Yes** | Applies to every section — directory, dialplan and configuration alike — because it changes the startup, not the handler. 49.4 → 32.6 ms app-side at concurrency 1 and a sustained ~30 versus ~21 requests/s under queueing, for one nginx block, no restart, and no new failure mode. |

Until the dialplan mirror exists and the same load tests are re-run across every section, **no keep-or-remove verdict on the Lua proxy is defensible**.


## 7. Caveats

- **One run per configuration.** The `fs_cli` floor varied between 98 and 189 ms across runs, so the sub-millisecond precision in the lookup column is not meaningful. Treat 0.4 ms and 3.0 ms as "under three milliseconds", not as a difference between the two.
- **The HTTP harness cannot see hits.** Its numbers describe the miss path only, and are therefore the correct measure for the fast controller and the wrong measure for the proxy.
- **Absolute values are host-specific.** A 1 shared vCPU, 967 MB host queues much sooner than a 4 vCPU appliance; the ratios transfer better than the absolutes.
- **Raw artifacts** remain on the test host under `storage/app/load-tests/cmp-*.json` and are not committed.
