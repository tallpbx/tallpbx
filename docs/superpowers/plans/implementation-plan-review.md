# Implementation Plan Review — CDR Modernization vs. Lua Redis Proxy

**Date:** 2026-10-31
**Branch reviewed:** `4.x` (HEAD tags through `v4.3.0`)
**Method:** Static review of both plans against the live codebase, shipped FreeSWITCH configuration, `composer.json`, `.env`, the CHANGELOG, and the project's own benchmark documents. No runtime tests, migrations, or FreeSWITCH changes were executed during the review.

## Plans Under Review

| ID | Plan | Path |
|:---|:---|:---|
| **Plan A** | CDR Modernization & Pulse-Style Stream Ingestion | [`2026-10-31-cdr-modernization-pipeline.md`](2026-10-31-cdr-modernization-pipeline.md) |
| **Plan B** | Lua Redis Proxy (Approach A) | [`2026-10-31-lua-redis-proxy.md`](2026-10-31-lua-redis-proxy.md) |

This document records every finding produced by that review: verified environment facts, per-plan findings with severity, contradictions between what each plan promises and what its own code samples do, a comparative assessment, and a sequenced recommendation.

---

## Executive Summary

1. **Plan A is architecturally sound but not executable as written.** Its layering (in-process Redis Stream buffer on a dedicated Redis DB → daemon digest worker → MariaDB, with 503-driven disk failover) matches the project's conventions and uses only available packages. However it contains one destructive command (`migrate:fresh`), one guaranteed-to-fail code sample (bulk insert against a UUID primary key), three internal contradictions around data integrity, no authentication for its ingestion endpoint, no worker supervision, and an unaddressed breaking change against the shipped 4.x series.
2. **Plan B should not be implemented as written.** Its premise is valid and its runtime dependencies are already installed, but its proposed static Redis keys directly conflict with the version-embedded cache invalidation system that shipped in v4.2.0 and was hardened in v4.3.0. As configured, the proxy would either never hit (Redis DB/auth mismatch with `hiredis.conf.xml`) or hit and serve stale SIP credentials and routes (invalidation sources outside `RoutingCacheObserver` do not delete its keys). The plan's performance baseline is also wrong (claims 25–35 ms; the project's own benchmarks measure 77–144 ms).
3. **Recommended sequence:** amend and execute Plan A first (higher value, additive, no architectural conflict), defer the module rename or plan it as a 5.0-class breaking change, then revisit Plan B as a redesign centered on version-aware cache keys, hiredis profile alignment, and key-derivation parity.

---

## Verified Environment Baseline

These facts were checked against the running workspace. They drive several findings below.

| # | Fact | Evidence |
|:--|:-----|:---------|
| E1 | Branch is `4.x`; latest tag is `v4.3.0` dated 2026-10-31 — the `xml-cdr` module, `xml_cdr` table, and `xml-cdr.*` permissions are **shipped** in the 4.x series | `git branch --show-current`, `git tag`, `CHANGELOG.md` |
| E2 | Framework is Laravel `^13.8` on PHP `^8.5` — both plans describe "Laravel 12" | `composer.json` |
| E3 | `app-modules/xml-cdr/` exists with model, Livewire components, services, factory, migration, routes, and 2 test files | module tree |
| E4 | Route names are already `panel.cdr.index` / `panel.cdr.detail`; permission prefix is `xml-cdr.*` and the provider carries an explicit warning that a mismatched prefix would silently disable action authorization | `app-modules/xml-cdr/routes/web.php`, `src/Providers/ModuleServiceProvider.php` |
| E5 | `app/Services/Cdr/` does not exist; `app/Http/Controllers/Api/` contains only `XmlHandlerController.php`; `app/Services/Xml/` contains three builders but **no** `XmlCacheService` / `XmlCacheKeyResolver` | directory listings |
| E6 | Routing cache invalidation is **version-based**: `RoutingCacheObserver` only calls `RoutingCacheVersion::bump($tenantId)`; both XML builders embed `RoutingCacheVersion::get(...)` into their cache keys | `app/Observers/RoutingCacheObserver.php`, `app/Support/RoutingCacheVersion.php`, `app/Services/Xml/DirectoryXmlBuilder.php`, `app/Services/Xml/DialplanXmlBuilder.php` |
| E7 | XML cache store is Redis DB 1 (`REDIS_CACHE_DB=1` via the `cache` connection); `.env` also sets `REDIS_PASSWORD` | `config/database.php`, `.env` |
| E8 | `hiredis.conf.xml` defines profile `default` (127.0.0.1:6379, 500 ms timeout, max-connections 3) with **no `db` and no auth parameters** | `/etc/freeswitch/autoload_configs/hiredis.conf.xml` |
| E9 | `mod_lua.so`, `mod_hiredis.so`, `mod_xml_curl.so` are installed; `mod_json_cdr.so` is **not** installed, but `freeswitch-mod-json-cdr` is available from the SignalWire repo at the exact installed version (1.11.4) | `/usr/lib/freeswitch/mod/`, `apt-cache policy freeswitch-mod-json-cdr` |
| E10 | FreeSWITCH is 1.11.4-dev; `/etc/freeswitch/autoload_configs/lua.conf.xml` has `xml-handler-script` / `xml-handler-bindings` present but commented out | `freeswitch -version`, `lua.conf.xml` |
| E11 | The `tallpbx-media` group exists and contains `www-data` and `freeswitch` | `getent group tallpbx-media` |
| E12 | The project already uses Redis DBs 0 (default), 1 (cache), 2 (session) — DB 3 for CDR is consistent with that allocation | `.env`, CHANGELOG 4.2.1 (`session:flush` guard) |
| E13 | `SafeMigrator` exists and is wired from `AppServiceProvider` to guard the primary database against destructive operations | `app/Database/SafeMigrator.php` |
| E14 | Measured XML handler latency: 144 ms mean at 30.8 req/s (2 vCPU dedicated), 77 ms at 57.3 req/s (4 vCPU), with call-setup p50/p95 of 148–180 ms / 180–472 ms | `docs/load-testing-results.md` |
| E15 | Worker supervision precedent exists (`scripts/tallpbx-queue.service`: `Restart=always`, `StartLimitInterval=60`, `ProtectSystem=strict`, `ReadWritePaths`, `EnvironmentFile=-/var/www/tallpbx/.env`) | `scripts/tallpbx-queue.service` |
| E16 | A secure media-streaming precedent exists at `app-modules/file-stores/src/Http/Controllers/MediaStreamController.php` | grep for stream responses |
| E17 | Rename blast radius in first-party code is small: only `tests/Feature/ModuleAutoloadTest.php` and `tests/Feature/PanelModuleAuthorizationSweepTest.php` reference `xml-cdr`/`XmlCdr` outside the module itself — but the **runtime** blast radius (permission rows, group pivots, module registry, table data) is not covered by that count | grep over `app/`, `app-modules/`, `tests/` |
| E18 | `laravel/pulse` is **not** a project dependency; "adapted from `pulse:work`" refers to a package that is not installed here | `composer.json` grep |
| E19 | The xml-handler API route is protected by a timing-safe token check (`bearerToken` → `X-FS-Token` → `?token=`) that fails closed when no token is configured, plus `throttle:xml-handler` and `LogXmlHandlerTiming` | `XmlHandlerController::handle`, `routes/api.php` |

Severity scale used below: **Critical** (breaks data, security, or the shipped 4.x contract), **High** (feature will not work or will corrupt behavior as written), **Moderate** (correctness or operational gap requiring plan edits), **Low** (documentation/consistency polish).

---

## Plan A — CDR Modernization & Pulse-Style Stream Ingestion

**Plan file:** [`2026-10-31-cdr-modernization-pipeline.md`](2026-10-31-cdr-modernization-pipeline.md)

### Architecture summary (as proposed)

FreeSWITCH `mod_json_cdr` POSTs A-leg and B-leg JSON to `/api/v1/cdr`; the controller appends payloads to a Redis Stream (`pbx:cdr:stream`) on DB 3 and returns 200 in sub-millisecond time; a continuous daemon (`tpbx cdr:work`) digests chunks via `XRANGE`, parses with `CdrPayloadParser`, bulk-inserts into `cdrs`, and `XDEL`s processed IDs; on Redis failure the endpoint returns 503 so FreeSWITCH writes payloads to `/var/log/freeswitch/json_cdr/failed/`, drained by `tpbx cdr:drain-spool`.

### What is verified correct

| ID | Finding |
|:---|:---|
| A-OK1 | The 503 → disk failover design is real: `mod_json_cdr` writes failed posts to `err-log-dir`, and the SignalWire package at version 1.11.4 is installable (E9). |
| A-OK2 | Redis DB 3 for CDR ingestion fits the existing allocation scheme (E12); `phpredis` supports the required stream commands. |
| A-OK3 | The `tallpbx-media` group exists, so the spool `chown freeswitch:tallpbx-media` is valid (E11). |
| A-OK4 | Tenant attribution inputs (`variable_domain_name`, `variable_sip_req_host`) are consistent with the existing `TenantIdentityResolver` domain-based resolution used by the xml-handler (E19). |
| A-OK5 | Dual-leg persistence (`leg`, `b_leg_uuid`, `json_data`) is a genuine deficiency fix — the shipped `xml_cdr` table has no leg, bridge, recording, or raw-payload columns (E3). |

### Findings

#### A-C1 — `migrate:fresh` is destructive on a live appliance (Critical)

Task 1, Step 5 instructs `tpbx migrate:fresh`, which drops **every table in the database** — all tenants, extensions, dialplans, and CDR history. On this project that is doubly wrong: `SafeMigrator` exists specifically to block destructive operations against the primary database (E13), and the appliance runs with live customer data. Replace with `tpbx migrate`. If a clean rebuild is genuinely desired for development, that belongs in a separate, explicitly labelled dev-only step — never in a production task list.

#### A-C2 — The rename is a 4.x-series breaking change with no migration path (Critical)

The plan renames the module directory, root namespace (`Modules\XmlCdr` → `Modules\Cdr`), table (`xml_cdr` → `cdrs`), and — implicitly — the permission prefix (`xml-cdr.*` → `cdr.*`), while deleting the legacy migration file. Consequences on any install upgraded from v4.0.0–v4.3.0:

1. **CDR history is stranded** in the old `xml_cdr` table. No data migration exists, and deleting the old migration file leaves the orphan table behind indefinitely.
2. **Permissions break silently.** `xml-cdr.view` / `xml-cdr.delete` rows and their `group_permission` pivots keep pointing at the old names; the provider's own comment documents that a mismatched prefix disables action authorization. The plan never schedules `permissions:sync`, `db:seed --class=AdminSeeder`, or `module:sync --only-local`.
3. **Module registry ghost row.** Renaming the module leaves the old registry row behind; the project has prior remediation history for ghost-row pruning that this plan ignores.
4. **SemVer policy conflict.** The project's stated policy grants backwards compatibility within 4.x maintenance releases. A module/table/permission rename belongs in a 5.0-class change, or must be delivered as an in-place migration (rename table with row preservation, rename permissions, resync registry, publish upgrade notes in the CHANGELOG).

**Recommendation:** either (a) defer the rename entirely — the panel routes are already `panel.cdr.*` (E4), so the rename adds no user-visible benefit — or (b) write it as a proper breaking-change task with data, permission, and registry migration steps and explicit changelog wording.

#### A-C3 — `Cdr::insert($batch)` cannot work against a UUID primary key (High)

The `cdrs` table keeps `id` as a UUID primary (inherited from the existing schema, E3). UUID generation in the model is performed by `HasUuids` through model **events**, which bulk `insert()` bypasses. Every row would insert with a `NULL` primary key and fail, or worse, succeed under loose SQL modes with empty ids. The plan's own Task 3 test ("digest() reads stream chunks, calls bulk database insertion") would catch this only if it actually executes against the migrated schema — the plan does not say it must. Fix: generate `Str::orderedUuid()` (or equivalent) per parsed row, or use `Cdr::upsert()` with explicit ids.

#### A-C4 — The digestion contract contradicts itself in three places (High)

The plan's Global Constraints and Review Focus promise behavior its own sample code does not implement:

| Promise | Sample code reality |
|:--------|:--------------------|
| Review Focus #1: "XDEL only deletes stream IDs after MariaDB transaction commit succeeds." | `digest()` performs a bare `Cdr::insert($batch)` with **no transaction**, then unconditionally `xdel`s. A partial failure still deletes the batch. |
| Global Constraint "No Silent Record Dropping." | If `CdrPayloadParser::parse()` returns false (malformed/truncated payload), the entry is silently excluded from `$batch` and its ID is **still deleted** — the row is dropped. |
| Review Focus #5: malformed JSON must "insert summary metrics without dropping the row." | Directly contradicted by the previous row of this table. |

Additionally, deleting after insert creates a crash window that produces **duplicate rows** (at-least-once delivery) with no idempotency guard: there is no unique index on `(call_uuid, leg)`, no upsert, and no Redis consumer group (`XACK` / `XAUTOCLAIM`). For billing- and compliance-grade CDRs, silent drops and duplicates are both unacceptable. Minimum fix set: wrap the insert in a transaction; never `XDEL` entries whose parse failed (quarantine them to a dead-letter stream or the disk spool); add a unique `(call_uuid, leg)` constraint with an upsert path.

#### A-C5 — The ingestion endpoint has no authentication or rate limiting (High)

`POST /api/v1/cdr` is described with no middleware. Task 2 hardcodes a `?token=` value into the FreeSWITCH configuration, but no Laravel-side verification of that token is ever specified — unlike the xml-handler, which performs a timing-safe comparison and fails closed (E19). Anyone able to reach the endpoint could inject fabricated CDRs or fill the stream. Mirror the xml-handler pattern: shared-secret middleware (`hash_equals`, fail-closed on missing config) plus a throttle. Consider `mod_json_cdr`'s native `auth-scheme`/`cred` parameters instead of a query-string token so the secret does not appear in URLs.

#### A-C6 — The digest worker has no supervision and no backpressure story (High)

- **No systemd unit.** `tpbx cdr:work` is a forever-loop daemon. The project's own precedent is `scripts/tallpbx-queue.service` with `Restart=always`, start-limit protection, and a hardened sandbox (E15). Without an equivalent unit, a crash silently stops CDR ingestion until the next Redis/disk spill.
- **Busy-loop under load.** The loop only sleeps when `$processed === 0`; a continuously non-empty stream spins with no pause.
- **Unbounded stream growth.** The plan deliberately rejects `XTRIM` (correct for compliance), but then never mentions monitoring. A stalled or slow worker grows `pbx:cdr:stream` without limit, risking Redis memory exhaustion. Add `XLEN` monitoring/alerting and a documented recovery procedure.

#### A-M1 — Task 2 commit and config-tracking mismatch (Moderate)

Task 2 Step 5 commits `docs/`, but no docs file is modified by Task 2, and the actual artifacts live in `/etc/freeswitch/`, which is not tracked in the repository (installer-side configuration is produced from `scripts/resources/`). Decide where the `json_cdr.conf.xml` template is versioned (most likely as an installer resource) and fix the commit step accordingly.

#### A-M2 — Parser field names are unverified (Moderate)

`variables.bridge_uuid` / `variables.signal_bond` for B-leg correlation are plausible but unconfirmed against mod_json_cdr 1.11.4 output, and `signal_bond` is not guaranteed for all bridge types. The parser tests must use captured real payload fixtures for both legs, including transfer and bridged scenarios, or leg correlation (Review Focus #4) will silently degrade to null.

#### A-M3 — UI conventions diverge from project standards (Moderate)

The UI spec uses `badge-primary` / `badge-secondary` for leg badges. Project convention is `badge-xs font-mono` in table cells and semantic DaisyUI tokens with a documented 3-tier status model. For secure recording playback, a precedent already exists (`MediaStreamController` in `file-stores`, E16) — reuse its path-containment and authorization approach rather than designing from scratch. Leg filtering should also follow the established list/pagination pattern (see the v4.2.0 pagination migration of 10 unbounded lists).

#### A-L1 — Documentation inaccuracies (Low)

- Tech Stack says "Laravel 12"; the project is on Laravel `^13.8` (E2).
- Task 3 lists `app/Http/Controllers/Api/CdrIngestionController.php` as *Modify*; the file does not exist (E5) — it is a *create*.
- "Adapted from `pulse:work`" references a package that is not installed (E18); describe it as "Pulse-inspired" without implying an installed donor command.

### Requirements and effort

`freeswitch-mod-json-cdr` install + module load · Redis DB 3 connection · `CdrRedisIngest`, `CdrPayloadParser`, `CdrIngestionController`, token middleware · `cdr:work` command + systemd unit · `cdr:drain-spool` + scheduler entry · migration + model changes · Livewire/Blade updates · ~5 test files. Seven tasks is realistic **after** the Critical/High amendments above; without them, the plan's own acceptance gate (Task 7) would either fail or, worse, pass while corrupting data.

---

## Plan B — Lua Redis Proxy (Approach A)

**Plan file:** [`2026-10-31-lua-redis-proxy.md`](2026-10-31-lua-redis-proxy.md)

### Architecture summary (as proposed)

`mod_lua` registers as an XML search binding ahead of `mod_xml_curl`. On each directory/dialplan/configuration lookup, the Lua script computes a static Redis key, issues `hiredis_raw default GET <key>`, and on a valid XML hit assigns `XML_STRING` for an in-process answer; on a miss it leaves `XML_STRING` unset so FreeSWITCH falls through to the PHP-backed `mod_xml_curl`. Laravel populates the keys and invalidates them from `RoutingCacheObserver`.

### What is verified correct

| ID | Finding |
|:---|:---|
| B-OK1 | All runtime dependencies are already installed: `mod_lua` and `mod_hiredis` are present, and `lua.conf.xml` merely requires uncommenting `xml-handler-script` / `xml-handler-bindings` (E9, E10). |
| B-OK2 | The Lua binding API usage is correct: reading the `XML_REQUEST` global and `params:getHeader(...)`, and emitting `XML_STRING`, is the documented mod_lua XML-handler contract. |
| B-OK3 | Miss-fallthrough semantics are standard: leaving the result unset lets FreeSWITCH continue to the next binding (`mod_xml_curl`). |
| B-OK4 | The reply guard is defensively sound: rejecting replies that lack `<document` neutralizes both `-ERR` and `-NOAUTH` error strings, even though the `-ERR` prefix check alone would miss `-NOAUTH`. |
| B-OK5 | A `default` hiredis profile already exists (E8), so the API call shape `hiredis_raw default GET ...` is valid — see B-C2 for why its payload is wrong. |
| B-OK6 | TDD structure (failing test first, targeted Pest, `--parallel`) matches project conventions. |

### Findings

#### B-C1 — Static keys conflict with the shipped versioned-cache invalidation (Critical)

Since v4.2.0, routing XML is cached under **version-embedded keys**: both builders incorporate `RoutingCacheVersion::get($tenantId)` and `RoutingCacheVersion::getGlobal()`, and `RoutingCacheObserver` invalidates by **bumping** those versions rather than deleting keys (E6, E1). Plan B proposes an orthogonal static namespace (`pbx:cache:xml:directory:{domain}:{user}`, etc.) purged only by explicit `DEL`s from `RoutingCacheObserver`.

That wiring is incomplete by construction. Multiple invalidation sources never pass through `RoutingCacheObserver`:

- `NumberTranslationService` and `TenantLimitService` version bumps;
- `SipScannerSignatures::bumpGlobal()` (shipped in v4.3.0 for scanner-signature dialplan invalidation);
- the `ExtensionSetting` observer path (invalidates directory/voicemail PIN changes);
- gateway changes routed through the observer's tenant resolution of related models.

None of these would delete the static Lua keys, so a cache **hit** would serve stale directory credentials and stale dialplans in-process — exactly the failure the plan's own Review Focus #4 warns about ("the phone must not continue using stale credentials"). Making the design correct forces a choice between two options the plan's Global Constraints forbid dividing:

1. **Lua reads the version key** (`freeswitch:routing-version:{tenantId}`) and embeds it in the cache key — but for directory lookups Lua has no tenant id (only domain/user), so it would need a domain→tenant map in Redis and tenant-resolution logic inside Lua, violating "zero business logic in Lua"; and the version key lives in the application cache store (DB 1), not in the hiredis profile's database.
2. **All invalidation paths also DEL static keys** — duplicating invalidation logic across every service that bumps a version, violating the same constraint and creating a permanent drift surface.

Until this is redesigned (or a deliberate decision is made to replace versioned keys with static keys project-wide), Plan B should not ship.

#### B-C2 — As configured, the proxy cannot ever hit (Critical)

Two independent configuration gaps guarantee a permanent cache miss:

1. **Database mismatch.** The XML cache is written by Laravel on the `cache` Redis connection, database index `REDIS_CACHE_DB=1` (E7). The hiredis `default` profile declares no `db` parameter, so it operates on DB 0 (E8). The Lua script queries DB 0; the data lives in DB 1.
2. **Auth mismatch.** `.env` sets `REDIS_PASSWORD`, but the hiredis profile specifies no authentication parameters (E8). If the production Redis enforces `requirepass`, `hiredis_raw` returns `-NOAUTH ...`, the script treats it as a miss, and every lookup falls through to PHP.

Either gap alone makes the feature a no-op; together they would also mask each other during testing. The plan must add a dedicated hiredis profile (host/port, `password`/`username`, explicit `db 1` or a dedicated DB), align it with the Laravel cache connection, and verify with an end-to-end `redis-cli` seed + `find_user_xml` test that the hit path is actually exercised.

#### B-C3 — Key derivation does not reproduce the controller's tenant resolution (High)

The xml-handler resolves directory requests in this order: `resolveFromSipAuth($username, $domain)`, then `resolveFromDomain($domain)`, then fail closed — because **domains can be shared across tenants** (E19, `XmlHandlerController::handleDirectory`). Plan B's key is `pbx:cache:xml:directory:{domain}:{user}`, built from `params` headers whose semantics (`user` vs `sip_auth_username`) may not match the authorization identity the PHP path considered. On a shared domain, two tenants could collide on the same key — a cross-tenant cache leak (the plan's own Review Focus #3). The dialplan key has the same class of risk if the Lua-side context derivation (`Caller-Context`, `Hunt-Context`) diverges from the controller's `Caller-Context` input handling.

Additionally, even with correct keys, the Lua static namespace differs from the versioned namespace PHP writes, so the system would maintain **two caches of the same XML** with two invalidation stories — double memory and double divergence risk. Key derivation must be proven identical by tests that compare Lua-computed keys against the actual keys the builders write.

#### B-C4 — The performance baseline is factually wrong (High)

Plan B claims current lookups take "~25–35 ms (PHP-FPM round-trip)". The project's published benchmarks measure 144 ms mean at 30.8 req/s (2 vCPU dedicated) and 77 ms mean at 57.3 req/s (4 vCPU), with call setup at 148–180 ms p50 (E14). The real gap is **larger** than claimed, which strengthens the proposal — but a SIPp acceptance gate calibrated against a fictional baseline cannot prove improvement. Task 5 must state the documented before-figures and measure the after-state on the same hardware tier.

#### B-M1 — Script validation command may produce false results (Moderate)

`luac -p` validates against the host's Lua toolchain, which may differ from the Lua embedded in this FreeSWITCH build. Validate by loading the script through FreeSWITCH itself (e.g. executing it once via `fs_cli`) in addition to the syntax check, and keep the `hiredis` connection failure path (`ignore-connect-fail`, `ignore-error` are already set in the profile) explicitly asserted as a miss.

#### B-M2 — Host configuration tracking is unresolved (Moderate)

Task 3's commit step references `/etc/freeswitch/scripts/tallpbx_xml_handler.lua`, which is outside the repository, with a parenthetical "or tracking script in `scripts/freeswitch/`". Decide the source of truth before execution: version the script under `scripts/freeswitch/` (or an installer resource) and have the install/reload documented as a host-ops step, consistent with how `lua.conf.xml` activation is described.

#### B-M3 — Concurrency headroom of the hiredis profile is unquantified (Moderate)

The `default` profile allows `max-connections=3` with a 500 ms timeout (E8). At sub-millisecond replies this is ample, but the proposal's purpose is sustained registration sweeps; the acceptance gate should include a concurrent lookup test (not only a single `find_user_xml`) to prove the 3-connection ceiling does not serialize the hot path.

#### B-L1 — Minor consistency items (Low)

- Review Focus #5 asks to "verify that loading `mod_lua` before or alongside `mod_xml_curl` preserves the fallback chain" — correct concern, but the plan never states the intended modules.conf ordering, so the verification step has no expected result to assert.
- The Lua script's `string.sub(reply, 1, 4) ~= "-ERR"` misses non-`-ERR` error prefixes (e.g. `-NOAUTH`); the subsequent `<document` guard saves it, but the comment should say so to avoid a future "simplification" removing the guard.

### Requirements and effort

One Lua script (~80 lines) · `lua.conf.xml` activation · hiredis profile alignment (db + auth, missing today) · `XmlCacheKeyResolver` · **invalidation redesign (the dominant, currently unsolved work)** · SIPp benchmark gate. Code volume is low; the invalidation redesign and the DB/auth alignment are the real cost and are not in the plan.

---

## Comparative Assessment

| Dimension | Plan A — CDR Modernization | Plan B — Lua Redis Proxy |
|:----------|:---------------------------|:-------------------------|
| Architectural fit with shipped systems | Additive; no conflicts (DB 3 is free, package available) | **Conflicts** with v4.2+ versioned cache invalidation |
| Dependency risk | One new apt package (version-matched, available) | All dependencies already installed |
| Data-integrity risk as written | **Critical**: `migrate:fresh`, UUID bulk-insert failure, silent drops, duplicate window | High: stale credentials/routes on hit; key collisions across shared domains |
| Security surface | Unauthenticated ingestion endpoint | Local-only reads; no new external surface |
| Series/BC impact | **Critical**: rename breaks 4.x contract and strands data | None |
| Operational readiness | No worker supervision; unbounded stream growth | One-line module activation; but hiredis profile must be reconciled |
| Performance case | Eventual consistency for dashboards; not latency-bound | Strong case (77–144 ms → <1 ms on hits) once correctness is solved |
| Effort to a safe deliverable | 7 tasks + amendments (moderate, mostly mechanical) | 5 tasks, but Task 2 becomes a redesign (open-ended) |
| Self-consistency | 3 contradictions between stated constraints and sample code | 1 major integration gap (invalidation) + 1 configuration gap (DB/auth) |
| Verdict | **Proceed after amendments** | **Do not implement as written; redesign first** |

### Note on whether Plan B is even needed now

The Lua proxy accelerates only cache-hit lookups. The published benchmarks attribute the current XML ceiling primarily to PHP-FPM pool sizing (static pools lifted sustained throughput from ~30 to ~65 req/s, E14), and each call triggers only 2–3 XML lookups. The proxy's upside is therefore real but concentrated in high-CPS tenants; Plan A's value (billing-compliant dual-leg CDRs, recording playback, diagnostics) applies to every deployment. Sequencing A first costs nothing to B.

---

## Recommendations

1. **Amend Plan A before execution:**
   - Replace `tpbx migrate:fresh` with `tpbx migrate` (A-C1).
   - Decide the rename up front: defer it (recommended — routes are already `panel.cdr.*`) or execute it as a 5.0-class breaking change with table-data preservation, permission rename + `permissions:sync`/AdminSeeder, registry resync, and CHANGELOG upgrade notes (A-C2).
   - Generate UUIDs explicitly for every bulk-inserted row (A-C3).
   - Wrap digestion in a transaction, quarantine parse-failed entries instead of deleting them, add a unique `(call_uuid, leg)` idempotency constraint (A-C4).
   - Add timing-safe token authentication and throttling to the ingestion endpoint (A-C5).
   - Add a systemd unit for `cdr:work`, sleep on busy loops, and add `XLEN` monitoring (A-C6).
2. **Do not implement Plan B as written.** Redesign it around version-aware cache keys (or a deliberate project-wide migration to static keys), add the hiredis profile `db`/auth alignment, prove key-derivation parity against all three builders, and re-baseline the acceptance gate with the documented 77–144 ms figures.
3. **Revisit Plan B after Plan A lands**, when the CDR pipeline's Redis conventions (DB 3, stream keys, worker service) are established and a versioned-key-aware Lua design can be evaluated against them.
4. **Track both plans with the SDD ledger convention** (`.superpowers/sdd/`) once execution starts, as with the previously shipped implementation plans.

---

## Evidence Index

| Evidence | Location |
|:---------|:---------|
| Plan A text | `docs/superpowers/plans/2026-10-31-cdr-modernization-pipeline.md` |
| Plan B text | `docs/superpowers/plans/2026-10-31-lua-redis-proxy.md` |
| Module manifest, model, migration, provider, routes | `app-modules/xml-cdr/` |
| Versioned cache invalidation | `app/Observers/RoutingCacheObserver.php`, `app/Support/RoutingCacheVersion.php`, `app/Services/Xml/DirectoryXmlBuilder.php`, `app/Services/Xml/DialplanXmlBuilder.php` |
| XML handler authentication and sections | `app/Http/Controllers/Api/XmlHandlerController.php`, `routes/api.php` |
| Redis connections and env | `config/database.php`, `.env` (values redacted here) |
| FreeSWITCH profile configuration | `/etc/freeswitch/autoload_configs/hiredis.conf.xml`, `/etc/freeswitch/autoload_configs/lua.conf.xml` |
| Installed modules / package availability | `/usr/lib/freeswitch/mod/`, `apt-cache policy freeswitch-mod-json-cdr` |
| Benchmarks | `docs/load-testing-results.md` |
| Worker supervision precedent | `scripts/tallpbx-queue.service` |
| Secure streaming precedent | `app-modules/file-stores/src/Http/Controllers/MediaStreamController.php` |
| Framework versions | `composer.json` |
| Release history / invalidation guarantees | `CHANGELOG.md` (`4.2.0`, `4.3.0`), `git tag` |

*No code, configuration, or data was modified in producing this review. Only this report file was created, at the user's explicit request.*
