# Implementation Plan Review — CDR Modernization vs. Lua Redis Proxy

**Revision:** 2.1 (re-review of updated plans; architectural opinion added)
**Date:** 2026-10-31
**Branch reviewed:** `4.x` (HEAD tags through `v4.3.0`)
**Method:** Static review of both plans against the live codebase, the installed FreeSWITCH modules and configuration, `composer.json`, `.env`, `config/cache.php`, the CHANGELOG, and the project's benchmark documents. No runtime tests, migrations, or FreeSWITCH changes were executed.

> **Revision history:** Revision 1 reviewed the original drafts of both plans. Both plans were subsequently rewritten. This revision evaluates the rewritten plans and records, per original finding ID, whether each earlier issue was fixed, partially fixed, or remains — so prior reviews stay traceable.

## Plans Under Review

| ID | Plan (current revision) | Path |
|:---|:---|:---|
| **Plan A** | CDR Modernization & Stream Ingestion (5.x Series) | [`2026-10-31-cdr-modernization-pipeline.md`](2026-10-31-cdr-modernization-pipeline.md) |
| **Plan B** | Lua Redis Proxy — Version-Aware Architecture | [`2026-10-31-lua-redis-proxy.md`](2026-10-31-lua-redis-proxy.md) |

---

## Executive Summary

1. **Plan A is now executable after a short punch list of five fixes.** The rewrite resolved the entire previously reported data-integrity cluster: explicit UUID generation, transaction-bound `XDEL`, idempotent `upsert` on `(call_uuid, leg)`, quarantine instead of silent drops, token authentication, and worker supervision. Remaining items are operational and configuration-level (token provisioning, scheduler sandbox, throttle sizing, a hardcoded test reference, legacy migration removal, branch/changelog steps).
2. **Plan B remains blocked by two verified platform facts.** The version-aware key design is conceptually correct, but (a) the installed `mod_hiredis` exposes **no database-selection parameter** — it cannot reach Laravel's cache on Redis DB 1 — and (b) Laravel applies the cache key prefix `tallpbx-cache-`, so every raw key the Lua script builds misses. The benchmark gate as written seeds unprefixed keys by hand and therefore proves nothing about the real PHP-to-Lua handoff.
3. **Recommended sequence:** apply the Plan A punch list and execute it; run a feasibility spike on Plan B's two hard blockers before any Plan B task is attempted.

---

## Verified Environment Baseline

| # | Fact | Evidence |
|:--|:-----|:---------|
| E1 | Branch is `4.x`; latest tag `v4.3.0` (2026-10-31). The `xml-cdr` module, `xml_cdr` table, and `xml-cdr.*` permissions are shipped in the 4.x series | `git branch --show-current`, `git tag`, `CHANGELOG.md` |
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

Severity scale: **Critical** (breaks data, security, or the shipped series contract), **High** (feature cannot work or corrupts behavior as written), **Moderate** (correctness or operational gap requiring plan edits), **Low** (polish/consistency).

---

## Plan A — CDR Modernization (5.x Series)

**Plan file:** [`2026-10-31-cdr-modernization-pipeline.md`](2026-10-31-cdr-modernization-pipeline.md)

### Status of Revision 1 findings

| Prior ID | Finding | Status in current revision |
|:---------|:--------|:---------------------------|
| A-C1 | `migrate:fresh` destroys the database | **Fixed** — now `tpbx migrate` (Task 1, Step 5) |
| A-C2 | Breaking 4.x rename with no migration path | **Mostly fixed** — explicitly reframed as a 5.x breaking clean break; `module:sync --only-local` + `AdminSeeder` added; permission set renamed to `cdr.*`. Residual: no branch step, no changelog, legacy migration not removed (A-R5, A-R6, A-R7) |
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

**A-R6 (Moderate) — "5.x Series" has no branch step.**
The active branch is `4.x`. The plan declares a 5.x breaking release but never creates the `5.x` branch or states where the commits land. Under the project's branching policy this step is mandatory before Task 1.

**A-R7 (Moderate) — No changelog or upgrade documentation task.**
AGENTS.md mandates `CHANGELOG.md` entries for features and explicit upgrade notes for breaking changes (migrations, new apt packages, FreeSWITCH reloads, `.env` additions such as `REDIS_CDR_DB` and `FREESWITCH_CDR_TOKEN`). No task touches `CHANGELOG.md` or `INSTALL.md`.

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

### Assessment

The architecture is sound and the previously reported integrity chain is closed. The remaining items are operational packaging (token provisioning, sandbox permissions, installer wiring, branch/changelog) and two small correctness details (recording path base, parser fixtures) — all bounded edits, no redesign.

---

## Plan B — Lua Redis Proxy (Version-Aware)

**Plan file:** [`2026-10-31-lua-redis-proxy.md`](2026-10-31-lua-redis-proxy.md)

### Status of Revision 1 findings

| Prior ID | Finding | Status in current revision |
|:---------|:--------|:---------------------------|
| B-C1 | Static keys conflict with `RoutingCacheVersion` | **Fixed conceptually** — version- and global-version-tagged keys, tenant map, version bump propagates automatically |
| B-C2 | Redis DB/auth mismatch | **Attempt invalid** — `password` is supported, but `database` is not a `mod_hiredis` parameter (E9); see B-R1 |
| B-C3 | Key derivation parity with PHP | **Partially addressed** — builders refactored to deterministic strings, but shared-domain resolution, dialplan tenant resolution, and dropped key inputs remain (B-R3, B-R6, B-R8) |
| B-C4 | Wrong performance baseline | **Fixed** — documents 77–144 ms; <1 ms hit gate |
| B-M1 | `luac` version caveat | **Not addressed** (Low) |
| B-M2 | Host config source of truth | **Fixed** — `scripts/freeswitch/` versioned, deployed to `/etc/freeswitch/` |
| B-M3 | hiredis concurrency headroom | **Addressed on paper** — 16 connections; moot until B-R1 is resolved |

### Remaining findings

**B-R1 (Critical) — Redis DB 1 is unreachable from `mod_hiredis`.**
The deployed module supports only `hostname`, `port`, `password`, `timeout_ms`, and `max-connections` (E9). The `database` parameter in Task 1's profile is silently ignored; the proxy operates on DB 0 while Laravel's cache lives on DB 1 (E7). Reaching DB 1 requires a design change (moving the XML cache and version keys to DB 0, an experimental `SELECT` via `hiredis_raw` that is unreliable across pooled connections, or a different datastore access path). Until resolved, Tasks 1, 4, 5, and 6 are not buildable.

**B-R2 (Critical) — The cache key prefix makes every Lua GET a miss.**
Laravel writes every cache entry — including `RoutingCacheVersion` — as `tallpbx-cache-<key>` (E18). The Lua script builds unprefixed keys (`freeswitch:routing-version:{id}`, `freeswitch:xml:dir:...`), so both the version read and the XML read miss 100% of the time. Worse, the Task 6 gate seeds unprefixed keys by hand (`redis-cli -n 1 SET "freeswitch:xml:dir:1:..."`), so the benchmark would "prove" hits while the real PHP-to-Lua handoff never functions — a vacuous verification. The plan must either pin an empty `CACHE_PREFIX` (project-wide consequence: it affects every cache entry) or have Laravel write a second, unprefixed mirror namespace through an explicit Redis connection, and the benchmark must exercise the real write path end to end.

**B-R3 (High) — The domain-to-tenant hash is lossy and permits cross-tenant directory leakage.**
`freeswitch:tenants` maps `domain → tenant_id`, but the project explicitly supports **shared domains**, where the controller resolves identity via `resolveFromSipAuth($username, $domain)` first. With two tenants on one domain, the hash holds a single entry; lookups for the other tenant compute a key under the wrong `tenant_id`, and when both tenants own the same username, Lua can serve the other tenant's cached directory XML — which contains SIP passwords. PHP fails closed in the ambiguous case; Lua would not. Key the map as `domain:user → tenant` (or disable Lua caching for shared domains) and mirror PHP's resolution order exactly.

**B-R4 (High) — Global Constraint and Task 3 disagree on the dialplan key, and the constraint is wrong.**
The constraint specifies `freeswitch:xml:dp:{tenant_id}:{domain}:{context}:v{version}:g{global_version}` — **no destination** — while Task 3's PHP sample and the Lua script both include the destination. If an implementer follows the constraint, a single cache entry serves the dialplan for every destination number in the context: cross-destination route poisoning. Reconcile on the Task 3 format.

**B-R5 (High) — `TenantRedisResolver` never states its Redis connection.**
`sync()` is described as populating "Redis DB 1", but no connection is specified; the facade default is DB 0. The hash would land in the wrong database and Lua would find nothing — a silent no-op. Require `Redis::connection('cache')` (or equivalent) and a Redis-backed test.

**B-R6 (Moderate-High) — Dialplan lookups never resolve a tenant.**
Contexts are `tenant_{id}_internal|public` (E20), which never match the domain hash; the script also reads `domain`, `domain_name`, `variable_domain_name` but not `Caller-Domain`, which the controller uses for dialplan requests. The dialplan path — the hot path — misses 100% of the time (correct via fallback, but the feature's primary benefit is lost). Parse `tenant_([%w-]+)_` in Lua or map contexts in the hash.

**B-R7 (Moderate) — Unvalidated command concatenation.**
`hiredis_raw` argument strings are built by concatenating raw SIP-supplied `domain`/`user` with no validation or quoting. A crafted value can smuggle extra Redis command tokens. Validate against a strict charset (`[%w%.%-_]`) before any query.

**B-R8 (Moderate) — The builder key refactor is coarser than today's keys.**
The proposed deterministic keys drop `tag_name`, `key_value`, and the hiredis limit/marker config flags that the current SHA-1 keys include (E19), so distinct lookups can coalesce. Add parity tests asserting Lua-computed keys equal the keys the builders actually write (the plan only asserts the existing suite still passes; the "68 tests" count is accurate per E23).

**B-R9 (Low).**
FreeSWITCH restart on a live PBX needs a maintenance-window note; the Global Constraint still defines a Sofia configuration key that no binding, builder, or Lua branch uses (dead spec); `TenantsSyncCommand` referenced in Task 2 does not exist in `app/Console/Commands`; the `luac -p` version caveat is unaddressed.

### Assessment

The version-aware concept is now correct in principle, and the tenant-resolution discussion shows the right instincts. However, the two Critical findings invalidate the mechanism as written: the datastore cannot be addressed (B-R1) and the namespace cannot match (B-R2). A feasibility spike — proving DB reachability and prefix handling end to end with one seeded lookup through the real PHP write path — must precede any implementation task.

---

## Comparative Assessment

| Dimension | Plan A — CDR Modernization | Plan B — Lua Redis Proxy |
|:----------|:---------------------------|:-------------------------|
| Architectural fit | Additive; DB 3 free; package available at matching version | Blocked: DB reachability + cache namespace mismatch |
| Data-integrity risk (current revision) | Low — transaction + upsert idempotency + quarantine + post-commit XDEL | N/A (mechanism not yet functional) |
| Security surface | Token provisioning contradictory (A-R1); Basic-auth forwarding unverified (A-R8) | Cross-tenant directory risk (B-R3); command concatenation (B-R7) |
| Series/BC impact | Explicit 5.x breaking change; branch + changelog steps still missing (A-R6, A-R7) | None |
| Operational readiness | Worker unit exists; scheduler sandbox and installer wiring missing (A-R2, A-R10) | Depends on unresolved DB/prefix work |
| Verification quality | Task 7 gate runs real tests; parser fixtures still needed (A-R11) | Benchmark gate is vacuous until B-R2 is fixed |
| Effort to safe deliverable | 7 tasks + bounded punch list | Feasibility spike, then redesign, then 6 tasks |
| Verdict | **Execute after five fixes** | **Do not execute; spike first** |

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

### Verdict summary

| Decision | Opinion |
|:---------|:--------|
| Redis Stream + micro-batch + disk spool for CDR ingestion | **Endorsed** — correct consistency model and the right isolation from the XML hot path; add consumer-group readiness (AO-A1), stream alerts (AO-A2), and a retention policy (AO-A3) |
| Lua Redis proxy for XML | **Goal endorsed, sequencing and shape rejected** — revisit after Plan A, scope to directory first (AO-B3), and require a one-`GET` mirror design with no Lua-side resolution (AO-B2) |

---

## Recommendations

1. **Plan A — apply the punch list before Task 1:**
   - Installer-generated token; no literal defaults in `config/freeswitch.php`, `.env.example`, or the FreeSWITCH template (A-R1).
   - Add `/var/log/freeswitch/json_cdr` to the scheduler unit's `ReadWritePaths` (A-R2).
   - Raise or externalize the ingestion throttle (A-R3).
   - Update `ModuleAutoloadTest` and remove the legacy migration (A-R4, A-R5).
   - Create the `5.x` branch and add `CHANGELOG.md` / upgrade notes tasks (A-R6, A-R7).
   - While in Task 6, resolve the recordings base directory via the `MediaStreamController` precedent (A-R9) and add parser fixture tests (A-R11).
2. **Plan B — run a feasibility spike, not a task list:**
   - Prove DB reachability from `mod_hiredis` (B-R1) and resolve cache-prefix handling with a real PHP write followed by a Lua read (B-R2).
   - Fix the dialplan key contradiction (B-R4) and the resolver connection (B-R5) regardless of spike outcome.
   - If the spike fails, prefer the mirror-namespace design (explicit `Redis::connection('cache')` writes of unprefixed keys) or shelve Plan B behind Plan A.
3. **Track execution in the SDD ledger** (`.superpowers/sdd/`) once either plan starts, per the project's plan lifecycle convention.

---

## Evidence Index

| Evidence | Location |
|:---------|:---------|
| Plan A text | `docs/superpowers/plans/2026-10-31-cdr-modernization-pipeline.md` |
| Plan B text | `docs/superpowers/plans/2026-10-31-lua-redis-proxy.md` |
| Module manifest, model, migration, provider, routes | `app-modules/xml-cdr/` |
| Versioned cache invalidation | `app/Observers/RoutingCacheObserver.php`, `app/Support/RoutingCacheVersion.php` |
| Cache key composition | `app/Services/Xml/DirectoryXmlBuilder.php`, `app/Services/Xml/DialplanXmlBuilder.php` |
| Cache prefix | `config/cache.php`, `.env` (`APP_NAME=TallPBX`) |
| XML handler authentication and sections | `app/Http/Controllers/Api/XmlHandlerController.php`, `routes/api.php` |
| Redis connections | `config/database.php` |
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

*This report is documentation only. No code, configuration, or data was modified in producing either revision.*
