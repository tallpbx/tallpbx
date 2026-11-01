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

## 9. Phase 1 end-to-end gate (reference appliance)

**Environment:** the TallPBX reference appliance (4 vCPU), FreeSWITCH 1.11.4, Redis 7, PHP 8.5 / Laravel 13. The proxy was enabled with `FS_LUA_PROXY_ENABLED=true`, the handler installed at `/etc/freeswitch/scripts/tallpbx_xml_handler.lua`, `mod_lua` moved ahead of `mod_xml_curl` in `modules.conf.xml`, and the handler bound to the `directory` section. Checks used real data: tenant 7 / `acme.192.168.1.76` / extension 2000, and tenant 2 / `192.168.1.71` / extension 2000.

| Check | Method | Result |
|:--|:--|:--|
| Cold miss warms the mirror | one `find_user_xml`, then inspect DB 0 | 1 xml-handler request; `tenant`, `ver` and `dir` keys written (1,840-byte payload) |
| Lua serves the hit | second `find_user_xml` | 0 xml-handler requests; output byte-identical to the PHP-rendered document |
| Immediate invalidation | revision bump, then two lookups | first lookup missed to PHP, second hit; payload key moved from `:v0` to `:v1` |
| Content freshness | extension edit (observer bump), lookup, revert | the edited value appeared in the mirrored document on the very next lookup; the revert propagated the same way |
| Credential freshness (SIP layer) | rotate a load-test SIP password, then SIPp REGISTER with the new and old passwords | new password: 401 challenge → **200 OK** with exactly 1 PHP request (bump → miss → rebuild); old password: 401 → **403 Forbidden** with **0 PHP requests**, so Lua served the current credential |
| Multi-tenant isolation | same extension number on two tenants | distinct tenant maps and payload keys; documents carry `tenant_id` 2 and 7 |
| Corrupt payload | overwrite a payload with `<document` | lookup served by `mod_xml_curl`, document correct, mirror re-warmed |
| Rollback | comment the two bindings, restart | every lookup returned to PHP; restoring the bindings restored hits |
| Cost, Lua vs PHP | 20 sequential `find_user_xml` per path (about 74 ms of `fs_cli` overhead per call) | **PHP 322 ms average, 20 PHP requests** vs **Lua 53 ms average, 0 PHP requests** |
| Lookup cost, repeated run | 30 `find_user_xml` lookups versus 30 bare `status` calls (both through `fs_cli`) | lookups averaged 60 ms against a 38 ms event-socket floor, i.e. about 22 ms per cached lookup, 0 PHP requests |

### App-side load run (Task 1 harness, proxy enabled)

The Task 1 harness measures the PHP miss path, not the in-process hit, so these numbers bound the fallback cost with mirroring active. Directory section, tenant `acme`, two tiers, request timing headers enabled:

| Tier | Success | Throughput | `request_ms` p50 | `request_ms` p95 | `handler_ms` p50 | `handler_ms` p95 | DB queries (avg) |
|:--|:--|:--|:--|:--|:--|:--|:--|
| 25 x 1 | 25/25 | 3.55 req/s | 265.7 ms | 425.3 ms | 19.4 ms | 53.4 ms | 0.56 |
| 100 x 5 | 100/100 | 8.77 req/s | 416.2 ms | 598.2 ms | 24.5 ms | 93.2 ms | 0 |

As in §6, most of the request time is framework boot and worker queueing, not XML generation — the surface the in-process hit removes. The mirror writes add three Redis `SET`s and two reads per lookup, which is negligible beside the boot cost.

**Decision: KEEP on the reference appliance.** The feature stays off by default for fresh installs (`FS_LUA_PROXY_ENABLED=false`); this host is enabled deliberately as the reference deployment.

> **This decision is withdrawn pending §10.** The gate below tested document freshness but not ownership freshness, and the identity map was found to be staleable across an ownership change.

Caveats:

- Each measurement shells out to `fs_cli`, so absolute values include roughly 74 ms of event-socket overhead (measured separately with `status`). The 269 ms gap is the lookup path itself.
- The PHP figure is a warm PHP cache on this appliance; it is not comparable to the 1 vCPU test host in §3.
- A live Redis-stop test was not run. The Lua miss paths are covered by the fixture harness (`-NOAUTH`, missing keys, empty and foreign values), and the PHP fallback's own Redis dependency predates this feature.
- The domain-shape document is mirrored once per requesting username, so mirror memory scales with active endpoints rather than active tenants. This remains a deferred decision.

## 10. Identity-epoch defect and fix (supersedes the KEEP decision in §9)

### What was wrong

Every cached answer on the directory path was audited after the §9 gate. Two of them answer *which tenant owns a username*:

| Cache | Key (before) | Invalidation (before) |
|:--|:--|:--|
| PHP resolver identity cache (DB 1) | `freeswitch:tenant-identity:<type>:<sha1(user\|domain)>` | 60-second TTL only |
| Lua mirror identity map (DB 0) | `pbx:fsxml:tenant:user:<domain>:<user>` | 60-second TTL only |

Neither key carried a revision, and no bump touched either one. A routing bump changes a counter inside DB 1; it reaches only keys that embed that counter. The identity answer is not a document — it is the input used to build the document keys — so a cached answer survived every panel save until its TTL expired.

**Impact:** if a username or a realm moves to another tenant (an account re-homed, a domain reassigned, an account disabled and recreated elsewhere), lookups keep resolving to the previous tenant for up to 60 seconds. That is a cross-tenant boundary, not a caching inefficiency, and it was present in the PHP path as well as the Lua path.

**Why the gate missed it:** the gate tested *document* freshness (edit a field, see it on the next lookup). It never tested *ownership* freshness (move a record between tenants, see the new tenant). The review's risk analysis treated the identity map as the thing that *prevents* collisions, and never asked whether the map itself could be wrong.

### What changed

- The **identity epoch** is the existing global routing revision (`freeswitch:routing-version:global`, mirrored as `pbx:fsxml:ver:global`). One token, no new counter.
- `RoutingCacheObserver` bumps it on every `SipAccount` and `TenantDomain` write (create, update, delete, restore). `TenantDomain` is now observed. These are the only writes that can move an identity.
- Both identity keys now embed it: PHP `…:<sha1>:e<epoch>` (appended inside the hash input) and Lua `pbx:fsxml:tenant:user:<domain>:<user>:e<epoch>`.
- Lua reads the epoch **first**. A missing or non-numeric value is a miss, never a default. The lookup is now four `GET`s, still with no XML logic.
- The mirror write path seeds the global epoch with `SETNX` on a cold start, publishes under the higher of store/mirror epochs after a cache flush, and refuses to publish at all when the epoch moved while the document was rendering.

**Cost:** the global revision also salts the dialplan cache keys, so a SIP account or domain edit invalidates each tenant's dialplan once on its next request. That is the accepted trade for one provable invalidation token.

### Tests that now guard it

| Test | Proves |
|:--|:--|
| `TenantIdentityResolverTest` → “drops a cached username identity when the account moves to another tenant” | The PHP resolver returns the new tenant immediately after a re-home |
| `TenantIdentityResolverTest` → “drops a cached domain identity when the domain moves to another tenant” | Same for the domain-only lookup |
| `RoutingCacheObserverTest` → two “global identity epoch” tests | Account and domain writes move the epoch; unrelated writes do not |
| `DirectoryXmlMirrorTest` → “keys the identity map by the global epoch…” | The mirror seeds the epoch and republishes under the new one |
| `DirectoryXmlMirrorTest` → “skips the mirror when the global epoch changes while the document renders” | No map entry is published for a superseded identity |
| `lua_key_fixtures.json` cases “identity epoch moved…”, “identity epoch missing…”, “noauth on identity epoch lookup”, “new identity epoch answers…” | Lua reads the epoch, treats staleness and errors as a miss, and follows a moved epoch |

### Live re-verification (reference appliance)

Run after deploying the epoch-aware handler, restoring the bindings, and restarting FreeSWITCH. Test identity: tenant 6, extension `1000`, domain `192.168.1.76`. PHP requests are counted from `/var/log/nginx/access.log` (`xml-handler` lines) across each `fs_cli find_user_xml`.

| Step | Action | Result |
|:--|:--|:--|
| Cold start | first lookup after restart | 1 PHP request; `pbx:fsxml:ver:global` seeded as `0`; map written at `…:1000:e0` = `6` |
| Warm hit | second lookup | 0 PHP requests |
| Ownership change (revoke) | disable the SIP account through Eloquent (as a panel save would) | epoch `0 → 1`; **very next lookup went to PHP (+1) and returned no `<user id="1000">`** — the cached identity did not survive |
| Fail-closed | second lookup while disabled | still PHP (+1), because the account was the only discriminator for a domain shared by several tenants; the tenant resolver now reports the domain as ambiguous and resolves nothing |
| Ownership change (restore) | re-enable the account | epoch `1 → 2`; next lookup +1 PHP, user present again, map written under `…:e2` = `6` |
| Warm hit after restore | next lookup | 0 PHP requests |
| Stale entry isolation | scan the identity map keys | only `…:e2` remained; the superseded `:e0` entry expired on its own TTL and was never readable under the new epoch |

**Decision: KEEP, reinstated.** The epoch path is proven live: an ownership change moves the epoch, the next lookup cannot use the cached answer, and the mirror re-warms under the new epoch. The feature still ships off by default for fresh installs; this host is enabled deliberately as the reference deployment.

### Cost of the epoch reads (post-fix regression check)

The fix adds two cache reads and two Redis reads to every directory render. Re-running the same Task 1 harness tiers on the same appliance, proxy enabled and timing headers on:

| Tier | Metric | Before the fix | After the fix |
|:--|:--|:--|:--|
| 25 x 1 | `request_ms` p50 / p95 | 265.7 / 425.3 | **236.5 / 338.0** |
| 25 x 1 | `handler_ms` p50 | 19.4 | 24.6 |
| 100 x 5 | `request_ms` p50 / p95 | 416.2 / 598.2 | **341.3 / 418.0** |
| 100 x 5 | `handler_ms` p50 | 24.5 | 27.6 |
| both | DB queries per request | 0.56 / 0 | 0.56 / 0 |

End-to-end request latency did not regress. The handler itself moved by roughly 3–5 ms at p50, which is the expected cost of the extra reads and is small against the ~240 ms request; the sample spread (`std_dev` 21–26 ms) means this is directional rather than a controlled A/B, and one run per tier was taken.
