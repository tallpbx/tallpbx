# Implementation Plan Review — CDR Modernization vs. Lua Redis Proxy

**Revision:** 3.4 (records the AO-B7 boot profile on the 1 vCPU reference host: 90 providers, 87 % of pre-controller time)
**Date:** 2026-11-01
**Branch reviewed:** `5.x`
**Method:** Static review of both plans against the live codebase, the installed FreeSWITCH modules and configuration, `composer.json`, `.env`, `config/cache.php`, the CHANGELOG, and the project's benchmark documents, combined with live measurement report analysis (`docs/lua-proxy-measurement-results.md`) and runtime inspection of the Laravel Redis connection manager and FreeSWITCH 1.11.4 `find_user_xml`.

> **Revision history:** Revision 1 reviewed the original drafts of both plans. Revision 2.1 evaluated the initial version-aware rewrites. Revision 3.0 evaluated `2026-10-31-lua-redis-proxy-v2.md` against empirical data from the 1 shared vCPU / 1 GB RAM measurement spike (`docs/lua-proxy-measurement-results.md`), certifying the resolution of prior Critical blockers `B-R1` and `B-R2`. Revision 3.1 records the resolution of four runtime blockers discovered during live protocol inspection (`V2-C1` unprefixed `lua_proxy` Redis connection, `V2-C2` dual-mode `domain`/`user` tag lookup support, `V2-H1` cold-start version key seeding in `DirectoryXmlBuilder`, and `V2-M1` cache-flush monotonic increment in `RoutingCacheVersion`), and marks `A-R6`/`A-R7` completed on `5.x`. Revision 3.2 records the Redis topology evaluation for the mirror — one instance, with documented split triggers — and withdraws the earlier recommendation to run the mirror on a second `redis-server` before general availability. It also adds three CDR durability findings: the accepted-then-crash loss window (`A-R13`), overcommit-induced write rejection (`A-R14`), and the appliance-sync `flushall()` (`A-R15`). Revision 3.3 records the sequencing decision: a fast front controller for the XML handler precedes Plan B's Tasks 2–7, the Task 1 gate is re-derived afterwards rather than inherited, and "additive" is clarified as additive-in-behaviour rather than zero-touch. Revision 3.4 records the AO-B7 boot profile and both configuration facts it surfaced: a request loads **90** providers, not 60 (E34 correction), and PHP executes only for `/index.php` (E36), which constrains how a fast front controller can be mounted.

## Plans Under Review

| ID | Plan (current revision) | Path |
|:---|:---|:---|
| **Plan A** | CDR Modernization & Stream Ingestion (5.x Series) | [`2026-10-31-cdr-modernization-pipeline.md`](2026-10-31-cdr-modernization-pipeline.md) |
| **Plan B** | Lua Redis Mirror-Cache Proxy (v2 Phase 1) | [`2026-10-31-lua-redis-proxy-v2.md`](2026-10-31-lua-redis-proxy-v2.md) |

---

## Executive Summary

1. **Plan A remains executable after its short operational punch list.** The rewrite resolved the data-integrity cluster (explicit UUID generation, transaction-bound `XDEL`, idempotent `upsert`, quarantine on disk, worker supervision). Branching and upgrade documentation (`A-R6`, `A-R7`) have been completed on the `5.x` series branch. Remaining items are operational (token provisioning, scheduler sandbox, throttle sizing, legacy migration cleanup).
2. **Plan B v2 is now fully unblocked, validated by empirical data, hardened against live runtime edge cases, and approved as a design.** The v2 rewrite adopted the recommended DB 0 additive mirror namespace (`pbx:fsxml:*`), completely eliminating the two previous Critical platform blockers (`B-R1` DB 1 reachability and `B-R2` cache prefix mismatch). Task 1 was executed on a clean DigitalOcean 1 shared vCPU / 1 GB RAM datacenter host (`docs/lua-proxy-measurement-results.md`) and recorded an unequivocal **PROCEED** decision: directory `request_ms` p95 reached 73.9–292.4 ms (exceeding the 10 ms threshold), HTTP p95 at concurrency 25 reached 1,233 ms (confirming worker queue saturation), and directory lookups accounted for 66.7% of all XML traffic. Revision 3.3 changes the start order: Tasks 2–7 wait behind a fast front controller for the PHP miss path (AO-B7), and the Task 1 gate is re-derived afterwards rather than inherited (AO-B8).
3. **Runtime Hardening Applied (Revision 3.1):** Live inspection of Laravel's Redis manager and FreeSWITCH 1.11.4's XML search runtime identified and resolved four critical execution blockers:
   - `V2-C1`: Laravel's default Redis prefix (`tallpbx-database-`) bypassed by adding a dedicated unprefixed `'lua_proxy'` connection (`'prefix' => ''`).
   - `V2-C2`: FreeSWITCH's native `switch_xml_locate_user()` shape (`tag_name="domain"`) and synthetic benchmark shape (`tag_name="user"`) both supported in mirror writer and Lua handler.
   - `V2-H1`: `DirectoryXmlBuilder` seeds `pbx:fsxml:ver:{tenantId}` on initial warm-up, preventing 100% cold-start misses.
   - `V2-M1`: `RoutingCacheVersion::bump()` reads DB 0's mirror version to ensure monotonic increments even after cache flush (`tpbx optimize:clear`).
4. **Recommended sequence:** apply Plan A's punch list; then build the fast front controller for the XML miss path and re-measure the ceiling (AO-B7, AO-B8); then decide on Plan B Phase 1 using a hit ratio measured at realistic call inter-arrival rather than a synthetic burst.

---

## Verified Environment Baseline

| # | Fact | Evidence |
|:--|:-----|:---------|
| E1 | Branch is `5.x` (active development series; tracking `origin/5.x`); previous tag `v4.3.0` (2026-10-31). The `xml-cdr` module, `xml_cdr` table, and `xml-cdr.*` permissions are shipped in the 4.x series; 5.x introduces breaking modernization requiring fresh install | `git branch --show-current`, `git tag`, `CHANGELOG.md` |
| E2 | Laravel `^13.8` / PHP `^8.5` | `composer.json` |
| E3 | `app-modules/xml-cdr/` contains model (`xml_cdr` table, `HasUuids`, `BelongsToTenant`), Livewire list/detail, services, factory, migration, routes, 2 tests | module tree |
| E4 | Route names already `panel.cdr.index` / `panel.cdr.detail`; permission prefix `xml-cdr.*`; provider documents that a mismatched prefix disables action authorization | `app-modules/xml-cdr/routes/web.php`, `src/Providers/ModuleServiceProvider.php` |
| E5 | `app/Services/Cdr/` and `app/Http/Controllers/Api/CdrIngestionController.php` do not exist; `tests/Feature/Http/` exists | directory listings |
| E6 | Invalidation is version-based: `RoutingCacheObserver` calls `RoutingCacheVersion::bump()`; both builders embed `RoutingCacheVersion::get()` / `getGlobal()` in their cache keys | `app/Observers/RoutingCacheObserver.php`, `app/Support/RoutingCacheVersion.php` |
| E7 | Cache store is Redis DB 1 (`REDIS_CACHE_DB=1`); `.env` also sets `REDIS_PASSWORD` | `config/database.php`, `.env` |
| E8 | `hiredis.conf.xml` profile `default` = 127.0.0.1:6379, 500 ms timeout, `max-connections=3`, **no auth, no db index** | `/etc/freeswitch/autoload_configs/hiredis.conf.xml` |
| **E9** | **`mod_hiredis.so` supports only the connection params `hostname`, `port`, `password`, `timeout_ms`, `max-connections`** — no `database`/`db`, no `username` | `strings /usr/lib/freeswitch/mod/mod_hiredis.so` |
| E10 | `mod_lua.so`, `mod_hiredis.so`, `mod_xml_curl.so` installed; `mod_json_cdr.so` not installed but `freeswitch-mod-json-cdr` is available at the matching 1.11.4 version | `/usr/lib/freeswitch/mod/`, `apt-cache policy` |
| E11 | `lua.conf.xml` has `xml-handler-script` / `xml-handler-bindings` present but commented out | `lua.conf.xml` |
| E12 | `tallpbx-media` group exists (`www-data`, `freeswitch`) | `getent group tallpbx-media` |
| E13 | Redis DBs in use: 0 default, 1 cache, 2 session — DB 3 for CDR fits the allocation | `.env` |
| E14 | `SafeMigrator` guards the primary database against destructive operations | `app/Database/SafeMigrator.php` |
| E15 | Measured XML latency: 144 ms @30.8 req/s (2 vCPU dedicated), 77 ms @57.3 req/s (4 vCPU); call setup p50 148–180 ms | `docs/load-testing-results.md` |
| E16 | Worker supervision precedent: `scripts/tallpbx-queue.service` (`Restart=always`, start limits, `ProtectSystem=strict`, `ReadWritePaths`, `EnvironmentFile`) | `scripts/tallpbx-queue.service` |
| E17 | **Scheduler unit runs `schedule:work` under `ProtectSystem=strict` with `ReadWritePaths=/var/www/tallpbx/storage /var/lib/tallpbx/media /var/www/tallpbx/bootstrap/cache /etc/tallpbx` — `/var/log/freeswitch/...` is NOT writable** | `scripts/tallpbx-scheduler.service` |
| E18 | **Laravel cache prefix defaults to `{slug(APP_NAME)}-cache-`; `APP_NAME=TallPBX`, `CACHE_PREFIX` unset → every cache key is written as `tallpbx-cache-<key>`** | `config/cache.php`, `.env` |
| E19 | Current builder cache keys are SHA-1 hashes that include `tag_name`, `key_value`, the routing version(s), and the hiredis limit/marker flags; directory and dialplan use separate key builders | `DirectoryXmlBuilder::directoryCacheKey()`, `DialplanXmlBuilder::dialplanCacheKey()`, `standardDialplanCacheKey()` |
| E20 | Tenant dialplan contexts are `tenant_{id}_internal` / `tenant_{id}_public` | `app/Services/DialplanContext.php` |
| E21 | `tests/Feature/ModuleAutoloadTest.php:87` hardcodes `['xml-cdr', 'Modules\XmlCdr\']` | test file |
| E22 | The xml-handler API performs a timing-safe, fail-closed token check; the API route also uses `LogXmlHandlerTiming` and a named throttle | `XmlHandlerController::handle`, `routes/api.php` |
| E23 | `tests/Feature/Http/XmlHandlerControllerTest.php` declares 68 tests (matches Plan B's "68 tests" claim; assertion count not verified) | `grep -c` on the test file |
| E24 | `scripts/resources/freeswitch/` does not exist yet (plans introduce it) | `ls scripts/resources/` |
| E25 | `laravel/pulse` is not a dependency; no `pulse:*` commands exist in this project | `composer.json`, `app/Console/Commands/` |
| E26 | Installed Redis runs `maxmemory 256mb`, `maxmemory-policy volatile-lru`, save points `3600 1 300 100 60 10000`, `appendonly no`, `stop-writes-on-bgsave-error yes`; `used_memory` is 1.07 MB and DB 0 is empty | `redis-cli CONFIG GET` / `INFO` on the installed host; `scripts/resources/php.sh:79-91` |
| E27 | `vm.overcommit_memory` is `0` and the installer never changes it — it sets only `maxmemory` and the eviction policy. Redis documents `1` as required for `BGSAVE` | `sysctl vm.overcommit_memory`; search over `scripts/` |
| E28 | Rendered document sizes: directory per user 799 B; directory per domain 67,866 B (100 extensions, ≈679 B per extension); dialplan per context 8,955 B | live `curl` against the installed xml-handler |
| E29 | Mirror payload and tenant-lookup keys are written with `SET … EX directory_cache_ttl` (60 s); only `pbx:fsxml:ver:*` keys persist, so the mirror is TTL-bounded and evictable under `volatile-lru` | Plan B v2 line 239; `config/freeswitch.php:371` |
| E30 | FreeSWITCH cannot compute SHA-1: `md5` is a working API command, `sha1` is not, and `mod_hash` exposes only `hash` / `hash_dump` / `hash_remote` | `fs_cli -x "show api"`, `fs_cli -x "sha1 abc"` |
| E31 | `ApplianceSyncHostCommand` calls `Redis::flushall()` across all databases; `run-cache-sweep.sh` flushes DB 1 only | `app/Console/Commands/ApplianceSyncHostCommand.php:114`, `scripts/run-cache-sweep.sh:85` |
| E32 | `Illuminate\Cache\RedisStore::serialize()` stores every non-numeric value as a PHP `serialize()` string (`s:799:"<document…";`); only finite numbers bypass serialization | `vendor/laravel/framework/src/Illuminate/Cache/RedisStore.php:505-527` |
| E33 | Measured command costs on the installed host: `GET` 2.43 µs/call over 46,678 calls, `SETEX` 10.6 µs, `DEL` 3.8 µs, `FLUSHALL` 6,589 µs across a single call. `SLOWLOG LEN` is 0 at a 10,000 µs threshold, `LATENCY LATEST` is empty, and `evicted_keys` / `rejected_connections` are 0 | `redis-cli INFO commandstats`, `SLOWLOG LEN`, `LATENCY LATEST` on the installed host |
| E34 | Per-request boot composition: **90 providers** are registered and booted on every request — 60 module providers plus roughly 30 framework and package providers; `config.php`, `services.php`, and `packages.php` are cached; opcache is enabled with no per-pool overrides | `ls app-modules/*/src/Providers/ModuleServiceProvider.php`, `bootstrap/cache/`, and the boot profile in E35 |
| E35 | Boot profile on the 1 vCPU reference host. CLI (`opcache.enable_cli=Off`, so absolutes are inflated): composer autoload 15.5 ms, load config 0.9 ms, register providers 35 ms, boot providers 130 ms, total 191–209 ms over three runs — **providers are 87 % of pre-controller time**. Under FPM the same path costs ~44 ms (request\_ms 55 − handler\_ms 11), so providers account for roughly 38 ms of it | `boot-profile.php` run as `www-data` three times consecutively; `docs/lua-proxy-measurement-results.md` |
| E36 | **PHP executes only for `/index.php`.** nginx has `location ~ ^/index\.php(/|$)` and no generic `\.php$` handler, so any other `.php` file under `public/` is served as static source rather than executed. `/api/v1/xml-handler` has its own `location` with `access_log off`, so the token in its query string is not written to the access log | `/etc/nginx/sites-available/pbx` |

Severity scale: **Critical** (breaks data, security, or the shipped series contract), **High** (feature cannot work or corrupts behavior as written), **Moderate** (correctness or operational gap requiring plan edits), **Low** (polish/consistency).

---

## Plan A — CDR Modernization (5.x Series)

**Plan file:** [`2026-10-31-cdr-modernization-pipeline.md`](2026-10-31-cdr-modernization-pipeline.md)

### Status of Revision 1 findings

| Prior ID | Finding | Status in current revision |
|:---------|:--------|:---------------------------|
| A-C1 | `migrate:fresh` destroys the database | **Fixed** — now `tpbx migrate` (Task 1, Step 5) |
| A-C2 | Breaking 4.x rename with no migration path | **Fixed** — explicitly reframed as a 5.x breaking clean break requiring fresh install; `5.x` series branch created; `module:sync --only-local` + `AdminSeeder` added; permission set renamed to `cdr.*`. Remaining punch list: legacy migration removal (A-R5) |
| A-C3 | Bulk insert breaks UUID primaries | **Fixed** — `Str::orderedUuid()` assigned per row (Review Focus #1, parser contract) |
| A-C4 | No transaction; silent drops; duplicate window | **Fixed** — `DB::transaction` around `upsert`, `XDEL` only after commit, malformed payloads quarantined to disk, idempotency via unique `(call_uuid, leg)` |
| A-C5 | Unauthenticated ingestion endpoint | **Partially fixed** — `hash_equals`, fail-closed, Basic auth, `throttle:1200,1`. Residual: token provisioning trio is contradictory and ships a public secret (A-R1); throttle too low for reference CPS (A-R3) |
| A-C6 | Unsupervised worker; unbounded stream | **Partially fixed** — systemd unit added with restart limits, memory ceiling, busy-loop yield, `getPendingCount()`. Residual: scheduler sandbox blocks spool drain (A-R2); unit not installed/enabled by any task (A-R10); no alerting on stream length (A-R12) |
| A-M1 | Config tracking / wrong commit paths | **Fixed** — templates now versioned under `scripts/resources/freeswitch/` |
| A-M2 | Parser field names unverified | **Not addressed** — A-R11 |
| A-M3 | UI conventions diverge | **Fixed** — `badge-xs font-mono`; recording controller enforces permission, tenant, and `realpath()` containment |
| A-L1 | Doc inaccuracies (Laravel 12, "Modify" controller) | **Fixed** — Laravel 13; controller listed as Create; test path matches the existing `tests/Feature/Http/` |

### Remaining findings

**A-R1 (High) — The token has three disagreeing sources and ships a published secret.**
`config/freeswitch.php` defaults `cdr_token` to a hardcoded literal; `.env.example` ships `FREESWITCH_CDR_TOKEN=` (empty — which overrides the default and trips the fail-closed 401 path); the FreeSWITCH template hardcodes the same literal in `cred`. In a public Apache-2.0 repository, a literal default is a published ingestion credential. Fresh installs would 401 every post and silently degrade to the 5-minute spool drain. Fix: generate a random token during install, write it to `.env`, inject it into the deployed `json_cdr.conf.xml` (mirroring the xml-handler token pattern), and ship no literal in `config/` or the template.

**A-R2 (High) — The scheduler sandbox cannot drain the spool.**
`cdr:drain-spool` is scheduled through `schedule:work`, whose unit (`scripts/tallpbx-scheduler.service`) is `ProtectSystem=strict` and does **not** include `/var/log/freeswitch/json_cdr` in `ReadWritePaths` (E17). Under that sandbox, `unlink()` of drained files and quarantine writes fail; the spool grows indefinitely while the command appears healthy. Add the spool root to the scheduler unit (or run the drain as its own unit).

**A-R3 (Moderate) — `throttle:1200,1` is below reference peak load.**
Documented ceiling is 15–20 calls/sec on 4 vCPU; two legs per call means 1,800–2,400 posts/min. Posts beyond 1,200/min receive 429 and spill to disk, adding five-minute ingestion lag at exactly the traffic level the pipeline is meant to survive. Align with the xml-handler limit (6,000/min) or make it env-configurable.

**A-R4 (Moderate) — The rename breaks a test the plan never updates.**
`tests/Feature/ModuleAutoloadTest.php:87` hardcodes `['xml-cdr', 'Modules\XmlCdr\']` (E21). It is absent from every task's file list and from the Task 7 gate, so the rename fails the full suite. (`PanelModuleAuthorizationSweepTest` only mentions xml-cdr in a docblock — benign.)

**A-R5 (Moderate) — Legacy migration is never removed.**
The Files list only creates the new `cdrs` migration. The old `2026_07_06_000012_create_xml_cdr_table.php` travels with the directory `mv`, so fresh 5.x installs create both `xml_cdr` and `cdrs`. Add an explicit removal step and verify registry ghost-row pruning after `module:sync`.

**A-R6 (Resolved on 5.x) — "5.x Series" branch step completed.**
The `5.x` series branch has been created, designated as the active development branch, and published to `origin/5.x` (`git branch --show-current` returns `5.x`).

**A-R7 (Resolved on 5.x) — Changelog and upgrade documentation completed.**
`CHANGELOG.md`, `INSTALL.md`, `README.md`, and `AGENTS.md` have been updated with explicit notices that the 5.x major series release represents a breaking modernization requiring a fresh install with no in-place migrations from 4.x.

**A-R8 (Moderate) — Basic auth depends on nginx forwarding `Authorization`.**
The controller verifies Basic credentials, but PHP-FPM only receives `HTTP_AUTHORIZATION` when nginx passes it. The existing xml-handler's `?token=` fallback suggests this area has bitten before. Add an explicit verification step against the installed nginx configuration.

**A-R9 (Moderate) — `realpath($cdr->record_path)` is naive.**
FreeSWITCH record paths are typically relative to the recordings root; `realpath()` would resolve against PHP's working directory. Join with the configured recordings base first (the `MediaStreamController` precedent already implements containment), then `realpath()` and prefix-check.

**A-R10 (Moderate) — Installer/deployment wiring is missing.**
Templates land in a new `scripts/resources/freeswitch/` tree (E24) and the worker unit in `scripts/`, but no task installs, enables, or deploys them (`install.sh`, `scripts/resources/freeswitch.sh` untouched). Fresh appliance installs would lack CDR ingestion.

**A-R11 (Moderate) — Parser field names remain unverified.**
`variables.bridge_uuid` / `variables.signal_bond` for B-leg correlation are plausible but unconfirmed for mod_json_cdr 1.11.4, and `signal_bond` is not guaranteed for all bridge types. Require captured A-leg/B-leg payload fixtures (including transfer/bridge scenarios) in the parser tests.

**A-R12 (Low).**
`cdr.export` permission declared with no export feature; `direction` column exists but is omitted from the parser mapping; `getPendingCount()` has no health/monitoring consumer (unbounded stream growth unobserved); the systemd unit omits `NoNewPrivileges=yes` present in the queue-service precedent.

**A-R13 (High) — A 200 response is unrecoverable; the spool only ever sees failures.**
`mod_json_cdr` writes to `err-log-dir` only when the POST fails. Once the controller returns 200, the record exists solely in the Redis stream. With `appendonly no` and the installed save points (E26), a restart or hard reset discards every entry written since the last snapshot that has not yet been digested. The window is small while the worker is healthy (`--sleep=250000`, `XDEL` only after the MariaDB commit) but unbounded while `cdr:work` is down — the stream accumulates, and a reboot then loses up to the save-point interval of accumulated records (about five minutes of call volume under the `300 100` rule), with no spool copy because every one of those POSTs succeeded. Fix: enable AOF with `appendfsync everysec` on the instance holding DB 3 — the only mechanism that preserves undigested entries across a reboot — and keep `XLEN`/stream-age alerting (AO-A2) so a dead worker is found before any reboot. Do not relax `stop-writes-on-bgsave-error yes`: a refused write becomes a 503 and therefore a spool file, whereas a falsely accepted write is simply lost.

**A-R14 (High) — `vm.overcommit_memory=0` converts a failed snapshot into a refused-write outage.**
The installer configures `maxmemory` and the eviction policy but never sets `vm.overcommit_memory`, and the installed host reports `0` (E27). Under the kernel's heuristic, `fork()` for `BGSAVE` can fail even when free memory exists. Combined with `stop-writes-on-bgsave-error yes` (correct for CDR integrity) that failure becomes `MISCONF`, and Redis rejects **every** write until a save succeeds: panel sessions break, cache writes throw, and CDR posts return 503 and spill to disk. Set `vm.overcommit_memory=1` in the installer and assert it in the post-install verification.

**A-R15 (Moderate) — `appliance:sync-host` flushes all sixteen databases.**
`ApplianceSyncHostCommand.php:114` runs `Redis::flushall()` after a snapshot restoration or IP change (E31). That silently discards undigested CDR stream entries — no spool, no warning — and also wipes the cache, session, and mirror databases. Scope the flush to the cache databases, or commit and drain the stream before flushing. For the mirror a wipe is desirable after a realm change; for the stream it is data loss.

### Assessment

The architecture is sound and the previously reported integrity chain is closed. The remaining items are operational packaging (token provisioning, sandbox permissions, installer wiring, branch/changelog) and two small correctness details (recording path base, parser fixtures) — all bounded edits, no redesign.

---

## Plan B — Lua Redis Mirror-Cache Proxy (v2 Phase 1)

**Plan file:** [`2026-10-31-lua-redis-proxy-v2.md`](2026-10-31-lua-redis-proxy-v2.md) (Supersedes [`2026-10-31-lua-redis-proxy.md`](2026-10-31-lua-redis-proxy.md))

### Status of Prior Findings in Plan B v2

| Prior ID | Finding | Status in v2 Plan | Verification Detail |
|:---------|:--------|:------------------|:--------------------|
| B-C1 | Static keys conflict with `RoutingCacheVersion` | **Fixed** | Version embedded in payload key (`:v{version}`); `RoutingCacheVersion::bump()` updates `pbx:fsxml:ver:{id}`. |
| B-C2 | Redis DB/auth mismatch | **Fixed** | Mirror moved to DB 0 via `connection: 'default'`; Task 6 tunes `hiredis.conf.xml` with 16 connections and password support. |
| B-C3 | Key derivation parity with PHP | **Fixed** | Directory-only scope supporting both native FreeSWITCH `domain` lookups (`tag_name=domain`, `key_value=domain`) and synthetic `user` lookups (`tag_name=user`, `key_value=user`); shared domains keyed by `(domain, user)` from resolved PHP identity; JSON fixture parity harness in Task 5 (`V2-C2`). |
| B-C4 | Wrong performance baseline | **Fixed** | Validated against 74–292 ms app-side p95 measured on remote 1 vCPU host; <1 ms hit gate. |
| B-M1 | `luac` version caveat | **Fixed** | Shell harness detects installed Lua interpreter dynamically (`lua`, `lua5.4`, `luajit`). |
| B-M2 | Host config source of truth | **Fixed** | `scripts/freeswitch/lua.conf.xml` and `hiredis.conf.xml` versioned as sources of truth. |
| B-M3 | hiredis concurrency headroom | **Fixed** | Task 6 tunes `hiredis.conf.xml` to `max-connections="16"`. |
| **B-R1** | **DB 1 unreachable from `mod_hiredis`** | **RESOLVED** | Uses DB 0 raw mirror namespace via `Redis::connection('lua_proxy')`. |
| **B-R2** | **Cache key prefix (`tallpbx-cache-` & `tallpbx-database-`) mismatch** | **RESOLVED** | Bypasses `Cache` facade AND configures dedicated `'lua_proxy'` connection with explicit `'prefix' => ''` to eliminate phpredis's default `tallpbx-database-` prefix (`V2-C1`). |
| **B-R3** | **Shared domain directory leakage** | **RESOLVED** | Keyed by `(domain, user)` only after PHP resolves identity and matching tag scope. |
| **B-R4** | **Dialplan key format conflict** | **RESOLVED** | Dialplan caching de-scoped from Phase 1. |
| **B-R5** | **`TenantRedisResolver` connection unstated** | **RESOLVED** | Resolver eliminated; replaced by `FsXmlMirrorWriter` using explicit `'lua_proxy'` connection. |
| **B-R6** | **Dialplan tenant resolution failure** | **RESOLVED** | Dialplan caching de-scoped from Phase 1. |
| **B-R7** | **Unvalidated command concatenation** | **RESOLVED** | Lua validates strict charset `^[%w%.%-_]+$` before query. |
| **B-R8** | **Coarse builder keys** | **RESOLVED** | Directory builder retains existing SHA-1 keys in `remember()`; mirror write is purely additive. |
| **B-R9** | **Maintenance window / dead specs** | **RESOLVED** | Maintenance window documented; dead Sofia specs and phantom commands purged. |

### Runtime Verification Findings & Plan B v2 Hardening (Revision 3.1)

Live inspection of the running Laravel Redis connection manager and FreeSWITCH 1.11.4 `find_user_xml` identified four runtime blockers that would have resulted in 100% cache misses or stale data. All four have been resolved in the amended v2 plan:

| Finding ID | Severity | Description | Status | Verification & Resolution Detail |
|:---|:---|:---|:---|:---|
| **V2-C1** | **Critical** | `Redis::connection('default')` retains global prefix `tallpbx-database-`, causing 100% Lua miss | **Fixed** | `config/database.php#L152` sets default option `'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME')).'-database-')`. Bypassing the `Cache` facade removed `tallpbx-cache-`, but `phpredis` still prepended `tallpbx-database-` to all keys on `'default'`. Resolved in Task 2 by adding dedicated connection `'lua_proxy'` with `'database' => env('REDIS_DB', '0')` and explicit `'prefix' => ''`. Asserted `getOption(\Redis::OPT_PREFIX) === null` in `FsXmlMirrorWriterTest`. |
| **V2-C2** | **Critical** | FreeSWITCH `switch_xml_locate_user()` sends `tag_name="domain"` and `key_value="<domain>"`, failing the v2 plan's `tag_name == "user"` guard | **Fixed** | FreeSWITCH directory lookups send `tag_name="domain"`, `key_value="<domain>"`, and username in params (`user` or `sip_auth_username`), and require the `<domain name="...">` XML wrapper emitted by `DirectoryXmlBuilder.php#L61-L103`. The v2 plan's `tag_name == "user"` guard only matched synthetic benchmark commands. Resolved in Tasks 2, 4, and 5 by supporting both `domain` and `user` tag lookups in `shouldMirror()`, Lua script, and embedding `{tagName}` in the directory key (`pbx:fsxml:dir:{tenantId}:{tagName}:{domain}:{user}:v{version}`). |
| **V2-H1** | **High** | Version key `pbx:fsxml:ver:{tenantId}` unseeded on cold start, causing Lua to miss all lookups until an administrative save/bump | **Fixed** | On fresh installs or cold boots, `RoutingCacheVersion::get($tenantId)` returns 0, but no key was written to DB 0 until `RoutingCacheVersion::bump()` ran. Resolved in Task 4 by adding `writeVersion($tenantIdInt, $version)` inside `DirectoryXmlBuilder::buildCachedDirectoryXml()` during initial mirror warming so the version key is populated on the first lookup. |
| **V2-M1** | **Moderate** | Cache clear (`tpbx optimize:clear`) flushes DB 1, which could cause version reset while DB 0 mirror keys persist | **Fixed** | `tpbx optimize:clear` flushes DB 1, resetting `RoutingCacheVersion` to 0. A subsequent bump could set version to 1 while pre-existing `:v1` mirror keys remain in DB 0. Resolved in Task 3 by checking if DB 1 key is missing and seeding from `max(storeVer, mirrorVer) + 1` to guarantee monotonic progression across cache flushes. |

### Assessment

Plan B v2 successfully resolves every theoretical and architectural blocker identified in Revision 2.1. The Task 1 measurement gate on the 1 vCPU remote datacenter host (`docs/lua-proxy-measurement-results.md`) passed all criteria with an unequivocal **PROCEED** decision ($73.9\text{ to }292.4\text{ ms}$ app-side p95, $1,233\text{ ms}$ HTTP p95 at concurrency 25, and $66.7\%$ directory query volume). With the Task 6 amendments (tuning `mod_hiredis` to 16 connections and providing password support, plus enforcing the `pbx:fsxml` namespace invariant), Plan B v2 is design-ready; its start order is set by the Revision 3.3 sequencing decision (AO-B7, AO-B8).

---

## Comparative Assessment

| Dimension | Plan A — CDR Modernization | Plan B v2 — Lua Redis Mirror-Cache Proxy |
|:----------|:---------------------------|:-----------------------------------------|
| Architectural fit | Additive; DB 3 free; package available at matching version | Additive DB 0 mirror; directory-only Phase 1; zero change to PHP cache |
| Data-integrity risk | Low — transaction + upsert idempotency + quarantine + post-commit XDEL | Very Low — fail-to-miss fallthrough to `mod_xml_curl`; 60s TTL; envelope validation |
| Security surface | Token provisioning punch list (A-R1); Basic-auth forwarding (A-R8) | Low — strict charset validation; `-NOAUTH` / error replies fail soft |
| Series/BC impact | Explicit 5.x breaking change (clean break) | None — 100% additive; default off; one-step rollback |
| Operational readiness | Worker unit exists; scheduler sandbox & installer wiring needed | Task 1 measurement gate **PROCEED**; `hiredis.conf.xml` pool/auth wired in Task 6 |
| Verification quality | Task 7 gate runs real tests; parser fixtures needed (A-R11) | High — empirical 1 vCPU VPS baseline; byte-for-byte JSON key-parity harness |
| Effort to safe deliverable | 7 tasks + bounded punch list | 6 remaining tasks (Tasks 2–7; Task 1 complete) |
| Verdict | **Execute after operational punch list** | **Execute Phase 1 (Tasks 2–7)** |

---

## Architectural Opinion

This section records judgment about the *direction* of each decision, separately from the severity-rated findings above. The findings state what must be fixed for each plan to work; this section states whether the chosen approach is worth building. These are opinions, not defect statuses, and no finding above changes as a result.

### Plan A — Redis Stream with micro-batch digestion: decision endorsed

The chosen approach is sound, and I would build it this way:

- **The problem is genuinely greenfield.** `XmlCdrService` and `CdrService` are both empty `CrudService` stubs, and nothing in the installer or FreeSWITCH resources references a CDR module — no legacy pipeline is being abandoned.
- **Decoupling the ingest endpoint from MariaDB is the correct call.** CDR posts share PHP-FPM workers with the xml-handler hot path; a synchronous insert would occupy workers and database connections exactly during the registration and call-setup peaks the benchmarks show saturating at 30–65 req/s.
- **Redis Streams fit this shape better than per-row queue jobs:** ordered IDs and batch-level crash recovery at O(1) write cost, without adding volume to the queue that already carries ESL events.
- **At-least-once delivery plus an idempotent upsert on `(call_uuid, leg)` is the right consistency model** for billing data; the unique index enforces it in the database rather than in application logic.
- **The disk-spool failover is the strongest idea in the plan.** Delegating durability to `mod_json_cdr`'s `err-log-dir` lets PHP fail cheaply and lose nothing, instead of building application-level retry machinery.

Decision-level notes, which complement the defect findings rather than duplicate them:

| ID | Note | Rationale |
|:---|:------|:----------|
| AO-A1 | Plan for consumer groups before parallelizing digestion (`XREADGROUP` / `XACK` / `XAUTOCLAIM`) | `XRANGE` + `XDEL` is a single-consumer design. It is adequate for one worker per host, but the upgrade path should be stated now so a second worker is not bolted on later without visibility-timeout semantics |
| AO-A2 | Treat `XLEN` alerting as mandatory | Rejecting `XTRIM` is correct for compliance data, which makes unbounded stream growth a designed possibility. `getPendingCount()` with no consumer is not a monitoring story |
| AO-A3 | Decide a CDR retention policy in this plan | `cdrs` grows without bound and `json_data` duplicates every payload. Retention is a data-lifecycle decision, not a later follow-up |
| AO-A4 | Collapse the duplicate CRUD services during the rename | `XmlCdrService` and `CdrService` are identical stubs. Carrying both into `Modules\Cdr` preserves pointless legacy |

### Plan B — Lua Redis proxy: goal endorsed, sequencing and shape not

What I agree with: eliminating two to three HTTP round trips per call setup is a legitimate scaling lever; `mod_lua` as an XML search binding is the correct FreeSWITCH mechanism; and falling through to `mod_xml_curl` on any doubt is the right failure mode.

Where I disagree:

| ID | Position | Rationale |
|:---|:---------|:----------|
| AO-B1 | The decision is premature | The documented XML bottleneck was PHP-FPM pool sizing (addressed in v4.2.0), and current throughput is 30–65 req/s with sub-second tails. The remaining benefit is CPS headroom and registration-storm tail latency — real at enterprise scale, but not the current binding constraint. Plan A's value applies to every deployment; Plan B's applies to the top of the hardware ladder |
| AO-B2 | The mechanism contradicts its own "zero business logic in Lua" constraint | The script performs an `HGET` tenant lookup, a version `GET`, a global-version `GET`, then the payload `GET` — up to four round trips plus tenant-resolution logic in a language with no test harness in this project. PHP should write an unprefixed mirror key with the version already baked in, so Lua performs exactly one `GET` and no resolution |
| AO-B3 | Scope directory first; decide dialplan later with data | Directory lookups are the most static and the most cacheable surface. Dialplan depends on gateways, time conditions, ring groups, IVRs, scanner signatures, limiters, and markers — every one of which must be threaded into the Lua key forever |
| AO-B4 | The permanent maintenance tax is the real cost | Three key-derivation sites (Lua plus both builders) must stay in lockstep across every future routing feature. This project just spent two releases hardening immediate invalidation, and Plan B adds a second invalidation story on the hot path |

#### Redis topology for the mirror: one instance, with measured triggers

An earlier draft of this opinion recommended moving the mirror to a second `redis-server` before general availability and treating that as a Phase 2 precondition. **That recommendation is withdrawn.** It was risk-aversion stated as a requirement, and the measurements do not support it.

The mirror is TTL-bounded and evictable (E29), so its footprint follows "documents touched in the last 60 seconds", not total extension count; the instance currently uses 1.07 MB of a 256 MB cap (E26). The largest mirrored document is the directory *domain* document at 67,866 B for 100 extensions (E28), and the Phase 2 dialplan document is 8,955 B per context — smaller than what Phase 1 already stores. At the measured 26.4 req/s ceiling each mirror `GET` costs tens of microseconds, so head-of-line blocking, `BGSAVE` forks over a 1 MB dataset, and memory competition are all immaterial at this scale. The one argument that survives is availability decoupling during a Laravel-Redis incident, and its cost is a degraded mode the design already handles: Lua misses and FreeSWITCH falls through to `mod_xml_curl`, so calls continue. A second instance would add a unit file, installer wiring, monitoring, and about 8 MB on a 1 GB host for a benefit that has not been measured.

| ID | Note | Rationale |
|:---|:------|:----------|
| AO-B5 | Keep the mirror in DB 0 of the main instance | No measured capacity reason to split, and every mirror failure path is fail-soft, so co-location costs availability of the optimisation rather than availability of the PBX |
| AO-B6 | Keep the split cheap rather than exercise it | `lua_proxy` should read `FS_REDIS_HOST` / `FS_REDIS_PORT` / `FS_REDIS_DB` so a later move is a configuration edit; skip mirroring documents above ~256 KB to bound value size for large tenants; flush the mirror keyspace in `run-cache-sweep.sh`, which flushes DB 1 only (E31), or the cold-baseline tier is contaminated once the proxy is enabled; expose `used_memory` and `evicted_keys` in the panel health surface |

**When single-threaded execution would become a factor.** At 2.43 µs per `GET` (E33), Redis spends roughly 0.02 % of one core at the measured 26 req/s ceiling. The constraint appears only at rates this project does not reach: about a third of a core at roughly 50,000 XML req/s, which corresponds to about 20,000 call setups per second. Concurrent channels are nearly irrelevant here, because Redis traffic follows call *setup* rate rather than live-call count — 5,000 bridged calls generate almost no Redis operations.

| Sustained XML req/s | ≈ Call setups/s | Redis CPU (one core) | Observable effect |
|---|---|---|---|
| 26 (measured 1 vCPU ceiling) | ~10 | 0.02 % | none |
| 500 | ~200 | 0.4 % | none; Redis is flat on any chart |
| 2,000 | ~800 | 1.5 % | visible on a graph, no latency effect |
| 50,000 | ~20,000 | ~35 % | tail latency begins to bend |
| 150,000 | ~60,000 | ~100 % | the single thread is the constraint |

Two qualifications belong with that table. The only burst that produces an observable spike is a registration storm against large documents — 1,000 endpoints re-registering against a 3.4 MB domain document costs roughly 400 ms of queueing, which the ~256 KB document cap in AO-B6 removes. And memory reaches its limit far sooner than CPU does: a 256 MB cap holding only documents touched inside the 60 s TTL accommodates on the order of 10,000–15,000 concurrently active tenants.

Re-evaluate the topology — without changing it — if sustained XML throughput reaches roughly 500 req/s, if `SLOWLOG LEN` becomes non-zero, or if `LATENCY LATEST` returns any event. Split into a second instance if `used_memory` exceeds roughly 70 % of `maxmemory`, if `evicted_keys` grows, if mirror keyspace exceeds roughly 25 % of the cap, if `SLOWLOG` or `LATENCY` entries correlate with large-value `GET`s, if a Redis restart, flush, or `MISCONF` visibly affects the panel, or if mirrored documents consistently exceed ~256 KB.

#### Sequencing: make the PHP miss path fast before building the Lua hit path

The Lua proxy's value is measured against a **55 ms** miss path. That number is not the cost of generating XML — it is the cost of booting the full application. E34 records what that boot contains: **60 module service providers** registered and booted on every request, plus a **274 KB** cached route table, none of which a directory lookup needs.

That makes a cheaper first step available, and it changes the order Plan B assumes.

| ID | Note | Rationale |
|:---|:------|:----------|
| AO-B7 | Build a fast front controller for the XML handler **before** Tasks 2–7 | A second entry point that boots the container with roughly four providers instead of sixty, leaves `mod_xml_curl` → nginx → PHP-FPM unchanged, and resolves the existing `XmlHandlerController` rather than copying it. Estimated 12–20 ms per lookup instead of ~55, so about 3.5× the ceiling on the same hardware, reversible by deleting one nginx location block — which must be explicit, because PHP executes only for `/index.php` (E36) |
| AO-B8 | Re-derive the Task 1 gate after the front controller lands | The PROCEED decision was computed against a 55 ms miss path. At 15 ms the economics of a 1 ms in-process hit change, so the gate is re-run rather than inherited. Keep the mirror's design work (keys, writer, version mirroring) — only its justification is provisional |
| AO-B9 | Read "additive" as additive-in-behaviour, not zero-touch | The plan edits six existing source and configuration files (`DirectoryXmlBuilder`, `RoutingCacheVersion`, `config/database.php`, `config/freeswitch.php`, `.env.example`, FreeSWITCH `modules.conf.xml`) plus two documentation files. Every write is gated by `FS_LUA_PROXY_ENABLED=false`, so backing out is safe, but the fast bootstrap must register `FsXmlMirrorWriter`'s Redis connection once the flag is on. A byte-equality test proves a correct document, not a written mirror, so assert the mirror key exists as well |

Order of work: profile the boot with `microtime` marks around each bootstrapper (abandon the approach if the providers are not the dominant cost) → build the front controller → verify against a byte-equality corpus covering directory, dialplan, configuration, and phrases across single-tenant, shared-domain, and unresolved-tenant cases → re-measure the ceiling → decide on Lua.

The front controller's acceptance criterion is the byte-equality corpus, not code review: if a provider the handler transitively needs is omitted, the result is a **wrong document**, not a slow one.

### Verdict summary

| Decision | Opinion |
|:---------|:--------|
| Redis Stream + micro-batch + disk spool for CDR ingestion | **Endorsed** — correct consistency model and the right isolation from the XML hot path; add consumer-group readiness (AO-A1), stream alerts (AO-A2), and a retention policy (AO-A3) |
| Lua Redis proxy for XML | **Goal endorsed, sequencing and shape rejected** — revisit after Plan A, scope to directory first (AO-B3), and require a one-`GET` mirror design with no Lua-side resolution (AO-B2) |
| Redis topology for the mirror | **Single instance (DB 0 on 6379)** — no measured bottleneck; keep host, port, and database configurable and act only on a documented trigger (AO-B5, AO-B6) |
| Sequencing of Plan B | **Front controller first, Lua second** — the proxy's justification rests on a 55 ms miss path that the front controller reduces to roughly 15 ms, so the gate is re-derived afterwards (AO-B7, AO-B8) |

---

## Recommendations

1. **Plan A — apply the remaining punch list before Task 1:**
   - Installer-generated token; no literal defaults in `config/freeswitch.php`, `.env.example`, or the FreeSWITCH template (A-R1).
   - Add `/var/log/freeswitch/json_cdr` to the scheduler unit's `ReadWritePaths` (A-R2).
   - Raise or externalize the ingestion throttle (A-R3).
   - Update `ModuleAutoloadTest` and remove the legacy migration (A-R4, A-R5).
   - *Completed on 5.x:* `5.x` series branch created (A-R6) and `CHANGELOG.md` / `INSTALL.md` upgrade notes completed (A-R7).
   - While in Task 6, resolve the recordings base directory via the `MediaStreamController` precedent (A-R9) and add parser fixture tests (A-R11).
   - Add A-R13 to A-R15 to the punch list: choose the CDR durability mechanism (AOF `everysec` or explicit `XLEN` alerting), set `vm.overcommit_memory=1` in the installer, and scope `appliance:sync-host`'s flush instead of calling `flushall()`.
2. **Before Plan B Tasks 2–7 — make the PHP miss path fast (AO-B7):**
   - Profile the boot with `microtime` marks around each bootstrapper and confirm the 60 module providers are the dominant cost (E34). If they are not, stop and re-evaluate before writing code.
   - Add a fast front controller: new `public/xml-handler.php`, one nginx `location = /api/v1/xml-handler`, roughly four providers registered instead of sixty, and the existing `XmlHandlerController` resolved from the container rather than reimplemented. Timing, throttle, and token middleware still apply.
   - Keep the tenant-resolution logic in one place. Do not build a raw-PHP path that duplicates it — returning another tenant's SIP credentials is worse than returning a slow answer.
   - Set `opcache.validate_timestamps=0` and reload PHP-FPM from the updater so deploys still take effect.
   - Accept only on a byte-equality corpus: directory (`user` and `domain`), dialplan, configuration, and phrases, across single-tenant, shared-domain, and unresolved-tenant requests.
   - Re-measure the ceiling and re-derive the Task 1 gate before starting Lua (AO-B8).
3. **Plan B v2 — proceed with Tasks 2–7 (Phase 1):**
   - Task 1 measurement spike completed on the remote 1 vCPU test host with an unequivocal PROCEED decision (app-side p95 $73.9\text{ to }292.4\text{ ms}$, HTTP p95 $1,233\text{ ms}$, directory share $66.7\%$).
   - Execute Tasks 2–4 incorporating the Revision 3.1 hardening:
     - Unprefixed `'lua_proxy'` connection (`'prefix' => ''`) in `config/database.php` (resolves `V2-C1`).
     - Dual-mode `domain` and `user` tag lookup support in `shouldMirror()`, Lua script, and `{tagName}` key builder (resolves `V2-C2`).
     - Version key seeding on initial warm-up in `DirectoryXmlBuilder` (resolves `V2-H1`).
     - Monotonic version progression across cache flushes in `RoutingCacheVersion::bump()` (resolves `V2-M1`).
   - In Task 6, deploy `scripts/freeswitch/hiredis.conf.xml` tuned to `max-connections="16"` with password support, and bind `tallpbx_xml_handler.lua`.
   - Run the key parity harness (Task 5) and the end-to-end laboratory gate (Task 7).
   - Keep the mirror in DB 0 of the main instance (AO-B5). Make the `lua_proxy` connection env-driven (`FS_REDIS_HOST` / `FS_REDIS_PORT` / `FS_REDIS_DB`), cap mirrored document size, and flush the mirror keyspace in `run-cache-sweep.sh` before the cold tier (AO-B6).
4. **Track execution in the SDD ledger** (`.superpowers/sdd/`) once either plan starts, per the project's plan lifecycle convention.

---

## Evidence Index

| Evidence | Location |
|:---------|:---------|
| Plan A text | `docs/superpowers/plans/2026-10-31-cdr-modernization-pipeline.md` |
| Plan B v2 text | `docs/superpowers/plans/2026-10-31-lua-redis-proxy-v2.md` (supersedes `2026-10-31-lua-redis-proxy.md`) |
| Task 1 Gate Measurement Report | `docs/lua-proxy-measurement-results.md` |
| Module manifest, model, migration, provider, routes | `app-modules/xml-cdr/` |
| Versioned cache invalidation | `app/Observers/RoutingCacheObserver.php`, `app/Support/RoutingCacheVersion.php` |
| Cache key composition | `app/Services/Xml/DirectoryXmlBuilder.php`, `app/Services/Xml/DialplanXmlBuilder.php` |
| Cache prefix | `config/cache.php`, `.env` (`APP_NAME=TallPBX`) |
| XML handler authentication and sections | `app/Http/Controllers/Api/XmlHandlerController.php`, `routes/api.php` |
| Redis connections | `config/database.php` |
| Redis runtime configuration, persistence, and document sizes | `redis-cli CONFIG GET` / `INFO` on the installed host; live `curl` against the installed xml-handler |
| hiredis capability and profile | `strings /usr/lib/freeswitch/mod/mod_hiredis.so`, `/etc/freeswitch/autoload_configs/hiredis.conf.xml` |
| Lua handler configuration | `/etc/freeswitch/autoload_configs/lua.conf.xml` |
| Installed modules / package availability | `/usr/lib/freeswitch/mod/`, `apt-cache policy freeswitch-mod-json-cdr` |
| Benchmarks | `docs/load-testing-results.md` |
| Worker supervision precedent | `scripts/tallpbx-queue.service` |
| Scheduler sandbox | `scripts/tallpbx-scheduler.service` |
| Secure streaming precedent | `app-modules/file-stores/src/Http/Controllers/MediaStreamController.php` |
| Dialplan context format | `app/Services/DialplanContext.php` |
| Rename blast radius | `tests/Feature/ModuleAutoloadTest.php`, `tests/Feature/PanelModuleAuthorizationSweepTest.php` |
| Release history | `CHANGELOG.md` (`4.2.0`, `4.3.0`), `git tag` |

*This report is documentation only. No code, configuration, or data was modified in producing Revision 3.4.*
