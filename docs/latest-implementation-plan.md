# Consolidated v4.3.0 Implementation Plan — Documentation Alignment, Post-v4.2.0 Remediation & Release Readiness

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development` to execute this plan (explicitly selected by user — do NOT use inline `superpowers:executing-plans`). Dispatch a fresh implementer subagent per task followed by a fresh task reviewer (spec compliance + code quality), then run one final whole-branch review at the end. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Supersedes:** `docs/post-v420-remediation-implementation-plan.md` (2026-10-30). That plan is fully incorporated here (its Tasks 1–6 are Phase B tasks R1–R6, its Deferred Item D-1 is carried verbatim). The previous plan file was retired/deleted per user instruction. SDD ledger references below point to **this** file.

**Goal:** In strict order — (Phase A) finish the documentation/tooling alignment for the v4.2.0 60-second `FS_XML_HANDLER_CACHE_TTL` default and `RoutingCacheVersion` immediate-invalidation semantics: remaining value corrections, a canonical XML-handler layered-cache section in `docs/architecture.md`, periphery de-duplication with cross-links, unit normalization (GiB/MiB → GB/MB), and residual doc sweeps; (Phase B) eliminate the remaining 60-second `mod_xml_curl` dialplan cache-invalidation gaps (`Gateway` and custom SIP scanner signatures), align `FS_XML_HANDLER_CACHE_TTL=60` across `.env.example` and the installer, harden `session:flush` against blind flushes and Redis DB collisions, and synchronize `INSTALL.md` and `CHANGELOG.md`; (Phase C) run the consolidated verification pass (targeted tests, full 60-second re-benchmark across all four reference tiers with firewall disclosure, and the full pre-release gate) and cut `v4.3.0` on `4.x`.

**Architecture:** Phase B extends TallPBX's cache-only `RoutingCacheVersion` revision stamp in two places: attach `RoutingCacheObserver` to `Modules\Gateways\Models\Gateway` for per-tenant outbound/E911 invalidation, and add a zero-DB-query global counter (`RoutingCacheVersion::getGlobal()` / `bumpGlobal()`) bumped by `SipScannerSignatures::saveCustom()` and embedded in dialplan cache keys. Harden `SessionFlushCommand` by resolving the exact storage target before prompting and refusing unforced `flushdb()` calls when the session Redis `(host, port, database)` tuple collides with `default` (`DB 0`) or `cache` (`DB 1`). Phase A adds `### 7.5` to `docs/architecture.md` (hot-path rationale, five-layer cache table, key composition, TTL-vs-versioning roles) and de-duplicates the periphery by cross-linking instead of repeating. Phase C regenerates the empirical tables in `docs/load-testing-results.md` at the 60-second default under the full default `nftables` ruleset.

**Tech Stack:** PHP `^8.5`, Laravel 13, Pest 4 (parallel unit/feature + Playwright browser suite), Redis (`Redis::connection`), FreeSWITCH 1.11 `mod_xml_curl`, SIPp + `scripts/pbx-sipp-validate.sh` + `scripts/run-cache-sweep.sh` (Phase C), Bash (`scripts/resources/tall.sh`), Markdown documentation set.

**Spec:** Self-contained in this plan — consolidates (a) the validated post-`v4.2.0` (`f455fdab..b7cd1837`) audit findings and (b) every task, decision, and deferred item from the 2026-10-30 documentation-alignment session (T-tasks, R-tasks, D1–D8 decisions).

**Execution Mode:** Subagent-Driven Development (`superpowers:subagent-driven-development`) — fresh implementer subagent per task + per-task spec/quality reviewer + final whole-branch code review (`v4.2.1..HEAD`).

---

## Decision Register (D1–D8) — Final Resolutions (2026-10-30, user-answered)

| # | Decision | Resolution | Effect |
| :--- | :--- | :--- | :--- |
| D1 | Units normalization in Phase A | **(a) Yes** | GiB/MiB → GB/MB normalized in Phase A (task T8), TOC anchors updated in the same edit; literal `free -m`-style quotes exempt |
| D2 | Benchmark validation at 60s | **(b) Full re-benchmark** | All four VPS tiers re-benchmarked at the 60s default in Phase C (task C2); every published table must disclose **tests ran with the full default `nftables` firewall enabled** |
| D3 | Task 3 failing parity test | Written inside R3's TDD cycle | Per the plan's own red-green steps; not earlier |
| D4 | Phase A commit split | **(a) Three granular commits** | `fix(load-testing)` docs corrections → `docs(architecture)` section + de-dup → `fix(scripts)` sweep script; defined at the Phase A commit gate |
| D5 | `GET /panel/impersonation/stop` CSRF posture | **(c) Leave as is — never remove** | Deliberate anti-lockout escape hatch: an impersonating admin hitting a 403 must always be able to stop impersonation. Hardening (e.g. rate limiting) is permitted in future; removal is forbidden. Recorded under "Accepted Design Decisions" |
| D6 | Duplicated impersonation session key constant | **(b) Defer as tracked written entry** | Deferred Item D-1 below; not fixed in this cycle |
| D7 | v4.3.0 release-notes additions | **(c) Neither** | Do NOT add a custom-module fail-closed breaking-change callout and do NOT add v4.2.0→v4.2.1 upgrade guidance to the v4.3.0 notes (binding on R5) |
| D8 | Diagram in architecture §7.5 | **(a) Yes** | T6 includes a small Mermaid sequence diagram of the `mod_xml_curl` request/cache flow |

**Task-numbering note:** session working numbers map to this plan as follows — conversation T1–T7 = T1–T7; conversation "T9 (units)" = **T8**; conversation "T11 (doc sweep)" = **T9**; conversation "T10 (consolidated tests)" = **C1**; conversation "T12 (final gate)" = **C3**. R1–R6 = superseded plan's Tasks 1–6.

---

## Global Constraints

- Active development branch is `4.x` (`git branch --show-current` MUST be `4.x`; there is no `main` or `master` branch).
- **Execution order is fixed:** Phase A → Phase A commit gate → Phase B (R1→R6 strictly sequentially) → Phase C (C1→C3). Within Phase B, never dispatch parallel implementers — R1 and R2 share `tests/Feature/Observers/RoutingCacheObserverTest.php`, and implementer subagents must never spawn nested subagents.
- **Consolidated verification sequencing (user directive 2026-10-30):** targeted Pest files run inside each task's TDD cycle; the consolidated test pass (C1) and the full suite (C3 Step 3) run only AFTER Phase B completes. Do not run `tpbx test --compact --parallel` at any other point.
- **Approval gates:** never `git commit` or `git push` without explicit user approval. The Phase A gate uses the D4(a) three-commit split. No git tags without explicit user instruction.
- **Subagent-Driven Execution & SDD Ledger Hygiene** (`tests/Feature/Architecture/SddLedgerHygieneTest.php`): Immediately initialize `<workspace>/progress.md` with `# SDD ledger — plan: docs/latest-implementation-plan.md` upon creating the SDD workspace so `.superpowers/sdd/` never contains an empty subdirectory, and delete `<workspace>` (or annotate `> **Archived:**` and index it in `.superpowers/sdd/progress.md`) when retiring this plan file. Any workspace created for the superseded plan is archived at the Phase A gate.
- Runtime floor is PHP `^8.5`; every edited PHP file must declare `declare(strict_types=1);` and pass `./vendor/bin/pint --dirty --test`.
- **Zero-DB-query `mod_xml_curl` cache-hit contract** (`app/Support/RoutingCacheVersion.php`): dialplan and contributor cache-key builders MUST read exclusively from cache (`RoutingCacheVersion`) and MUST NEVER query MariaDB/SQLite (e.g., never call `SecuritySetting::get()` inside a cache-key builder).
- **Installer script safety** (`.agents/skills/tallpbx-custom/SKILL.md`): `scripts/resources/tall.sh` edits must remain idempotent (`grep -q` guard so re-runs never clobber explicit operator overrides in `.env`), include plain-language comments explaining why the setting is written, and pass `bash -n scripts/resources/tall.sh`.
- **Documentation & unit standards:** use `tpbx <command>` for interactive appliance CLI examples (retaining `php artisan` in non-interactive scripts/systemd units and noting equivalence on first mention in `INSTALL.md`), use decimal storage/memory units (`GB`, `MB`) — binary IEC units only inside literal, unformatted command output quotes — and obfuscate any public IPs in committed reports (last octet only, e.g. `x.x.x.218`).
- **Benchmark environment disclosure (binding from D2b):** every benchmark table published in `docs/load-testing-results.md` (and any future re-benchmark report) must state that measurements ran with the **full default `nftables` firewall enabled** — the production-default ruleset (geo-blocking, pre-filters, default-drop) active. Firewall-disabled runs must never be presented as production-representative.
- **Cache clearing & test discipline:** run `tpbx optimize:clear` after code/config/view changes; run targeted Pest files per task; the full suite runs at most **once** (C3 Step 3). Never mutate live `nftables` state to "see what happens"; load-generator ingress whitelisting in C2 follows the documented bounded procedure only.

## Review Focus

1. **E911 & outbound gateway failover/disable during a 60s dialplan cache window:** editing an `emergency` `Gateway` host/port or disabling an outbound trunk `Gateway` (`enabled = false`) without touching `Emergency` or `OutboundRoute` must immediately invalidate both cached `DialplanXmlCollector` contributor fragments and `DialplanXmlBuilder` dialplan responses on the very next `mod_xml_curl` lookup *(pinned in R1)*.
2. **Hidden SQL queries on cached `mod_xml_curl` hits or cross-tenant stale scanner signatures:** saving custom SIP scanner signatures via `SipScannerSignatures::saveCustom()` must immediately invalidate cached public dialplans across *all* tenants, while repeated cached `/api/v1/xml-handler` dialplan requests must execute **zero** database queries (`DB::getQueryLog() === []`) *(pinned in R2)*.
3. **Installer re-run clobbering intentional operator `.env` overrides:** fresh installs must receive `FS_XML_HANDLER_CACHE_TTL=60`, while re-running `tall.sh` on a server where an administrator explicitly set a custom `FS_XML_HANDLER_CACHE_TTL` in `.env` must leave that custom value untouched *(pinned in R3)*.
4. **`session:flush` wiping application locks (`DB 0`) or telephony caches (`DB 1`) on misconfigured `.env`:** when `database.redis.{session_connection}` resolves to the same `(host, port, database)` as `database.redis.default` or `database.redis.cache`, `session:flush` without `--force` must emit a clear collision error, exit `Command::FAILURE`, and **never** invoke `flushdb()`, while `--force` explicitly overrides *(pinned in R4)*.
5. **Post-`v4.2.0` mini-rail `<x-tooltip>` Alpine binding (`64e58a7c`) & decomposed Blade views in a real browser:** `resources/views/layouts/app.blade.php` (`x-bind:data-tip` / `x-bind:aria-label`) and the decomposed Certificate Manager / Security Center views must render cleanly with zero browser console/Alpine syntax errors under Playwright *(pinned in C3)*.
6. **Documentation accuracy & non-duplication (Phase A):** every prose doc states the 60-second default and immediate `RoutingCacheVersion` invalidation semantics; the mechanism is explained **once** (architecture §7.5) and merely linked elsewhere; no stale `TTL=5` claims or "propagate within N seconds" guidance remain anywhere.
7. **Re-benchmark representativeness (Phase C):** regenerated tables are measured at the shipped default (60s) with installer-default PHP-FPM pools and the full default firewall — and once fresh results land, **all references to retired/legacy tests are removed from the documentation; anything no longer measured must not be referenced or mentioned**.

---

## File Structure

**Phase A (documentation/tooling):**
- `docs/architecture.md` — add `### 7.5` closing Part I (after §7.4, before `# Part II`); never renumber existing sections (§16.3 etc. are cross-referenced from `AGENTS.md`/`docs/operations.md`).
- `README.md`, `docs/env-reference.md` — remaining `TTL=5` → `60` value corrections (§2.2 default column, both example templates; the Redis XML Caching feature bullet).
- `docs/load-testing-guide.md`, `docs/load-testing-results.md` — already corrected/annotated (T1–T4); T7 trims definitional cache bullets to a §7.5 link; T8 normalizes units; T9 adds the firewall disclosure.
- `docs/operations.md`, `AGENTS.md`, `.agents/skills/**` — sweep for stale TTL/cache-semantics references.
- `scripts/run-cache-sweep.sh` — already fixed (T4: snapshot/restore operator TTLs, 60s default tier, ensure-keys guard).
- `docs/latest-implementation-plan.md` (this file) replaces `docs/post-v420-remediation-implementation-plan.md` at the Phase A gate.

**Phase B (code remediation):**
- `app/Providers/AppServiceProvider.php` — registers `RoutingCacheObserver` across telephony Eloquent models (`registerRoutingCacheObservers()`); add `Modules\Gateways\Models\Gateway`.
- `app/Support/RoutingCacheVersion.php` — cache-only routing revision counter; add `globalKey()`, `bumpGlobal()`, and `getGlobal()` alongside the per-tenant methods.
- `app/Services/DialplanXmlCollector.php` — context-wide contributor fragment cache (`contributorCacheKey()`); embed `RoutingCacheVersion::getGlobal()`.
- `app/Services/Xml/DialplanXmlBuilder.php` — full dialplan response cache (`dialplanCacheKey()`); embed `RoutingCacheVersion::getGlobal()`.
- `app-modules/security/src/Support/SipScannerSignatures.php` — persists custom SIP scanner User-Agent signatures (`saveCustom()`); bump `RoutingCacheVersion::bumpGlobal()` after persisting.
- `app-modules/security/src/Services/Detection/SipScannerDialplanContributor.php` — public-context SIP scanner dialplan guard; update class docblock for immediate global revision invalidation.
- `.env.example`, `scripts/resources/tall.sh`, `config/freeswitch.php` (header comment only) — align the 60-second default (env-reference/guide portions already done in Phase A).
- `app/Console/Commands/SessionFlushCommand.php` — driver-aware session invalidation CLI; add pre-confirmation target descriptor (`describeTarget()`) and Redis DB collision guard (`detectRedisDbCollision()`).
- `INSTALL.md`, `CHANGELOG.md` — PHP `8.5` floor, `tpbx`/`tallpbx` CLI parity, `FS_XML_HANDLER_CACHE_TTL=60` + `.env` upgrade note, `tpbx permissions:sync` in manual upgrades, `[Unreleased]` entries for `v4.3.0`.
- `tests/Feature/Observers/RoutingCacheObserverTest.php`, `app-modules/security/tests/SipScannerSignaturesTest.php`, `tests/Feature/XmlHandlerCacheConfigTest.php`, `tests/Feature/Console/SessionFlushCommandTest.php` — targeted Pest regression coverage for R1–R4.

**Phase C (verification & release):** `docs/load-testing-results.md` (regenerated tables + disclosure), `storage/app/load-tests/**` (benchmark artifacts), this plan file (retired at release cut), `CHANGELOG.md` (`[Unreleased]` → `## [4.3.0]`).

---

## Current Working-Tree State (2026-10-30, pre-gate)

Phase A tasks T1–T4 are **complete but uncommitted** in 5 dirty files: `scripts/run-cache-sweep.sh`, `docs/load-testing-guide.md`, `docs/load-testing-results.md`, `CHANGELOG.md` (`[Unreleased]` entries), `docs/post-v420-remediation-implementation-plan.md` (Deferred Item D-1 appended). Verification already performed: `bash -n` on the sweep script; a 3-scenario sandboxed simulation of the snapshot/ensure/restore cycle (legacy-commented / keys-absent / explicit-custom — all restored byte-identical); `tpbx optimize:clear`.

# Phase A — Documentation & Tooling (T1–T9 + commit gate)

### T1 — Load-testing docs: correct factually wrong invalidation claims — **COMPLETE (uncommitted)**

Replaced "admin panel changes propagate within 5 seconds" (Standard Office rationale), "Panel updates take up to 30s to reflect unless caches are cleared" (High-Density rationale), and all "…with 5s invalidation" / "Recommended baseline" phrasing in the four tier tables with immediate-invalidation (`RoutingCacheVersion`) semantics and "Legacy 5s default profile" framing.

### T2 — Load-testing docs: annotate measured tables as legacy — **COMPLETE (uncommitted)**

Intro reworded to "under the then-default production cache policy"; scope NOTE added ("All figures … measured at the legacy `FS_XML_HANDLER_CACHE_TTL=5` default (pre-v4.2.0)…"); labels relabeled: "Production (`TTL: 5s`)" → "Legacy default (`TTL: 5s`)", "Prod" → "Legacy", the four "**3. Production Baseline**" rows → "**3. Legacy 5s Baseline**"; benchmark-section intro carries a one-sentence scope note; hit-rate/latency columns carry a provenance bullet.

### T3 — Load-testing docs: update recommendation tables to the 60s default — **COMPLETE (uncommitted)**

Sizing matrix "Cache TTL Window" = "60 seconds (default)" for all tiers + NOTE explaining tier-differentiated windows are obsolete; cache policy table: Standard Office 60/60/60 with write-driven-freshness rationale, High-Density 60+ with "extend only when Redis memory allows", Development 0 unchanged.

### T4 — `run-cache-sweep.sh` snapshot/restore + guide alignment + CHANGELOG — **COMPLETE (uncommitted)**

Script snapshots the operator's exact granular TTL lines (including commented ones) before sweeping and restores them on exit/interrupt (absent keys re-removed); ensures the granular keys exist so tiers apply reliably; tier 3 is now `prod-baseline-60` (60/60); memory-ceiling tier raised to 60s so TTL exceeds test duration; legacy 30s tier kept as comparison. Guide env block and sweep description updated to match. CHANGELOG `[Unreleased]` gained the `### Changed` (script) and `### Fixed` (docs guidance) entries.

### T5 — Remaining value corrections (README + env-reference) — **COMPLETE (uncommitted)**

- [x] **Step 1:** `README.md` (~line 130, "Redis XML Caching" feature bullet): change `FS_XML_HANDLER_CACHE_TTL=5` → `FS_XML_HANDLER_CACHE_TTL=60` (the sentence's "Web panel changes invalidate active caches immediately" stays — it is correct).
- [x] **Step 2:** `docs/env-reference.md` §2.2: default column of the `FS_XML_HANDLER_CACHE_TTL` row: `5` → `60`; keep the description ("Changing this setting synchronizes dialplan, directory, and ACL caches simultaneously").
- [x] **Step 3:** `docs/env-reference.md` example templates: Small/Medium Business template `FS_XML_HANDLER_CACHE_TTL=5` → `60`; High-Throughput PBX template `FS_XML_HANDLER_CACHE_TTL=10` → `60` (a "high-throughput" profile below the default is incoherent post-v4.2.0).
- [x] **Step 4:** Verify: `grep -n "FS_XML_HANDLER_CACHE_TTL=5\|FS_XML_HANDLER_CACHE_TTL=10" README.md docs/env-reference.md` → empty.

### T6 — Add the canonical cache explanation to `docs/architecture.md` §7.5 (D8a) — **COMPLETE (uncommitted)**

**Content scope (strict):** the mechanism is explained here and **only** here.
- [x] **Step 1:** Insert new `### 7.5 Telephony XML Handler: Hot Path, Layered Caching & Versioned Invalidation` after §7.4 and before the `# Part II` divider. Do NOT renumber any existing section.
- [x] **Step 2:** Write the section covering exactly: (1) *why the path is hot* — FreeSWITCH `mod_xml_curl` POSTs to `/api/v1/xml-handler` per call leg (auth digest, dialplan context, destination lookup); each uncached request costs HTTP + PHP-FPM + MariaDB; (2) *the complete layer table* — final dialplan XML (tenant, context, destination), contributor fragments (tenant, context), directory XML (tenant, tag, domain, user), ACL configuration, and tenant identity resolution — each row noting the key inputs **including the per-tenant `RoutingCacheVersion`**; (3) *key composition* — `sha1(tenantId | context | destination | routingVersion | hiredis flags)` pattern; (4) *separation of roles* — the version bump owns correctness (writes change the key → guaranteed miss → immediate freshness), the TTL only bounds memory/GC of abandoned entries and how long unchanged data stays cached; routing edits never wait for TTL expiry; (5) a single factual mention that the default was raised to 60s in v4.2.0, linking out to `docs/env-reference.md` §2.2 for knobs and the load-testing pair for tuning — **no env defaults tables or tuning advice in-section**.
- [x] **Step 3 (D8a):** Include a small Mermaid `sequenceDiagram` of one `mod_xml_curl` lookup (FreeSWITCH → XML handler → cache key with version → hit serves cached XML / miss rebuilds from MariaDB → write path bumps version). Follow the project's Mermaid pitfalls on record (no overflowing notes, no `<br>` in node labels).
- [x] **Step 4:** Add one cross-reference sentence in §11.1 (Redis Decoupling, "DB 1 (Disposable Cache)" bullet) pointing to §7.5.
- [x] **Step 5:** Verify placement: §7.5 renders between §7.4 and Part II; `grep -c '^## ' docs/architecture.md` unchanged (no new top-level sections).

### T7 — De-duplicate the periphery via cross-links — **COMPLETE (uncommitted)**

- [x] **Step 1:** `docs/load-testing-results.md` "Cache Profiles & Telephony Scenarios": replace the two definitional bullets (`**Contributor Cache (…)**: Caches individual dialplan building blocks…` and `**Dialplan Cache (…)**: Caches the complete compiled XML response…`) with one bullet linking to architecture §7.5 for layer semantics and invalidation; keep the `mixed`/`cache-hit` scenario bullets (they define test scenarios, not the cache mechanism).
- [x] **Step 2:** `docs/load-testing-guide.md` NOTE (RoutingCacheVersion paragraph): append "See `docs/architecture.md` §7.5 for the full layered-cache design." — keep the NOTE itself (operationally reassuring).
- [x] **Step 3:** `docs/env-reference.md` §2.2 intro: add one line after the existing intro sentence — "See `docs/architecture.md` §7.5 for how the layered caches and versioned invalidation work." No mechanism duplication.
- [x] **Step 4:** Verify no mechanism prose remains outside §7.5: `grep -n "version bump\|versioned cache key\|RoutingCacheVersion" docs/load-testing-guide.md docs/load-testing-results.md docs/env-reference.md README.md` → only short references/links remain.

### T8 — Unit normalization GiB/MiB → GB/MB (D1a) — **COMPLETE (uncommitted)**

- [x] **Step 1:** `docs/load-testing-results.md`: normalize all non-literal occurrences — tier headings (`1 vCPU / 1 GiB RAM` → `1 GB RAM`, all four tiers), the TOC anchor links (~lines 21–30, slugs must change together, e.g. `#1-vcpu--1-gib-ram-entry-baseline` → `#1-vcpu--1-gb-ram-entry-baseline`), summary-table hardware columns (`1 vCPU, 1 GiB RAM`…), sizing-matrix "Recommended Hardware" column, the PHP-FPM Worker Sizing Formula TIP (`1.5 GiB` overhead → `1.5 GB`, `~65 MiB` → `~65 MB`, `2.5 GiB free` → `2.5 GB free`, `800 MiB` → `800 MB`), and installer auto-tuning bullets (`>2.5 GiB free RAM`).
- [x] **Step 2:** **Exempt literal quotes:** host-spec bullets quoting `free -m`-style output (e.g. "967 MiB RAM, 2.0 GiB swap (121 MiB used)", "15,999 MiB RAM (15,090 MiB available)") stay untouched per the AGENTS.md exception.
- [x] **Step 3:** `docs/load-testing-guide.md`: sweep for the same violations and normalize.
- [x] **Step 4:** Verify: `grep -n "GiB\|MiB" docs/load-testing-results.md docs/load-testing-guide.md` → only the exempted literal host-spec lines remain; every TOC link resolves (anchors match new heading slugs).

### T9 — Residual doc sweep + firewall disclosure — **COMPLETE (uncommitted)**

- [x] **Step 1:** Add the D2b disclosure to `docs/load-testing-results.md`: in the "Datacenter Telephony Capacity Comparison" scope NOTE (added in T2), append — "All measurements ran with the **full default `nftables` firewall enabled** (geo-blocking, pre-filters, and default-drop active), matching a production-hardened deployment."
- [x] **Step 2:** Sweep `docs/operations.md`, `AGENTS.md`, and `.agents/skills/**` for stale `FS_XML_HANDLER_CACHE_TTL=5` references or "changes propagate within N seconds" claims; align to 60s + immediate invalidation. (`AGENTS.md` was already standardized on `tpbx` by `c2c94e5` — do not undo that.)
- [x] **Step 3:** Verify: `grep -rn "FS_XML_HANDLER_CACHE_TTL=5" docs/ AGENTS.md README.md .agents/ .env.example scripts/resources/tall.sh` → only hits allowed are: historical `CHANGELOG.md` release sections (never rewrite history) and the two files intentionally deferred to R3 (`.env.example`, `tall.sh`).

### Phase A Commit Gate (D4a — granular commits, user approval required before each) — **COMPLETE**

No commit happens without explicit user approval at this gate. Granular commits:

- [x] **Commit 1 — `docs(architecture): add XML handler layered cache section and sequence diagram`** (`877599a`) — `docs/architecture.md` (§7.5 + §11.1 cross-ref).
- [x] **Commit 2 — `fix(load-testing): correct cache guidance, de-duplicate periphery, and normalize units`** — `docs/load-testing-guide.md`, `docs/load-testing-results.md` (T1–T3 + T8 + T9 disclosure), `README.md`, `docs/env-reference.md` (T5 + T7 cross-link), `CHANGELOG.md` (`### Fixed` hunk in `3269506`), `docs/latest-implementation-plan.md`, plus retirement of `docs/post-v420-remediation-implementation-plan.md`.
- [x] **Commit 3 — `fix(scripts): preserve operator cache TTL settings across cache sweeps`** (`f0e33c0`) — `scripts/run-cache-sweep.sh` + the `### Changed` CHANGELOG hunk.
- [x] **Pre-gate checks:** `tpbx optimize:clear`; Pint N/A (no PHP files in Phase A); no test run here (consolidated tests are Phase C per the sequencing directive). All pre-gate checks passed.
# Phase B — Post-v4.2.0 Remediation Implementation (R1–R6, strictly sequential, TDD)

*Carried verbatim from the superseded plan (Tasks 1–6) with the adjustments noted per task. Every task: write the failing test first, watch it fail, implement, watch it pass, then commit (user approval required).*

### R1 — Observe `Gateway` in `RoutingCacheObserver` for Outbound & E911 Dialplan Invalidation — **COMPLETE (uncommitted)**

**Files:**
- Modify: `app/Providers/AppServiceProvider.php:92-95,388-420`
- Test: `tests/Feature/Observers/RoutingCacheObserverTest.php`

**Interfaces:**
- Consumes: `Modules\Gateways\Models\Gateway` (`tenant_id`, `name`, `host`, `port`, `enabled`), `App\Observers\RoutingCacheObserver`, `App\Support\RoutingCacheVersion::get(int $tenantId): int`
- Produces: Automatic `RoutingCacheVersion::bump(int $tenantId)` on `Gateway` `saved` and `deleted` events (including cross-tenant `tenant_id` reassignment via `RoutingCacheObserver`)

- [x] **Step 1: Write the failing `Gateway` observer and E911/outbound cache-invalidation tests in `tests/Feature/Observers/RoutingCacheObserverTest.php`**

Import `Modules\Emergency\Models\Emergency` and `Modules\Gateways\Models\Gateway`, then append two tests:

```php
it('bumps routing cache version when a gateway is created, updated, disabled, or deleted', function (): void {
    $tenant = Tenant::factory()->create();
    $versionBefore = RoutingCacheVersion::get((int) $tenant->id);

    $gateway = Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'host' => 'sip1.carrier.test',
        'port' => 5060,
        'enabled' => true,
    ]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 1);

    $gateway->update(['host' => 'sip2.carrier.test', 'port' => 5080]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 2);

    $gateway->update(['enabled' => false]);
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 3);

    $gateway->delete();
    expect(RoutingCacheVersion::get((int) $tenant->id))->toBe($versionBefore + 4);
});

it('immediately invalidates cached emergency and outbound dialplan XML when a gateway is modified or disabled', function (): void {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_ttl' => 60,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 60,
    ]);
    Cache::flush();

    $tenant = Tenant::factory()->create();
    $context = app(DialplanContext::class)->internal((string) $tenant->id);

    Emergency::factory()->create([
        'tenant_id' => $tenant->id,
        'caller_id' => '+15551234567',
        'address' => '100 Main St',
    ]);

    $emergencyGw = Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'emergency',
        'host' => 'e911-primary.carrier.test',
        'port' => 5060,
        'enabled' => true,
    ]);

    $trunkGw = Gateway::factory()->create([
        'tenant_id' => $tenant->id,
        'name' => 'primary-trunk',
        'host' => 'trunk.carrier.test',
        'port' => 5060,
        'enabled' => true,
    ]);

    OutboundRoute::factory()->create([
        'tenant_id' => $tenant->id,
        'dial_pattern' => '^(\d{10})$',
        'gateway_id' => $trunkGw->id,
        'gateway' => null,
        'enabled' => true,
    ]);

    $e911Query = http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '911',
    ]);
    $outboundQuery = http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $context,
        'Caller-Destination-Number' => '5559876543',
    ]);

    // Warm both 60s caches (full dialplan + context-wide contributor fragments)
    $this->get('/api/v1/xml-handler?'.$e911Query)
        ->assertOk()
        ->assertSee('sofia/external/${destination_number}@e911-primary.carrier.test:5060', false);
    $this->get('/api/v1/xml-handler?'.$outboundQuery)
        ->assertOk()
        ->assertSee("sofia/gateway/{$trunkGw->id}/\$1", false);

    // Update emergency gateway host/port and disable outbound trunk gateway without touching Emergency or OutboundRoute
    $emergencyGw->update(['host' => 'e911-backup.carrier.test', 'port' => 5080]);
    $trunkGw->update(['enabled' => false]);

    // Next mod_xml_curl lookups must immediately reflect both gateway changes
    $this->get('/api/v1/xml-handler?'.$e911Query)
        ->assertOk()
        ->assertSee('sofia/external/${destination_number}@e911-backup.carrier.test:5080', false)
        ->assertDontSee('e911-primary.carrier.test', false);
    $this->get('/api/v1/xml-handler?'.$outboundQuery)
        ->assertOk()
        ->assertDontSee("sofia/gateway/{$trunkGw->id}/\$1", false);
});
```

- [x] **Step 2: Run the targeted test file to verify the new tests fail**

Run: `./vendor/bin/pest tests/Feature/Observers/RoutingCacheObserverTest.php`
Expected: FAIL — `RoutingCacheVersion::get((int) $tenant->id)` stays unchanged when `Gateway` is created/updated, and cached `e911-primary.carrier.test` / `sofia/gateway/{$trunkGw->id}` XML remains stale.

- [x] **Step 3: Register `Gateway::class` in `AppServiceProvider::registerRoutingCacheObservers()`**

In `app/Providers/AppServiceProvider.php`, import `use Modules\Gateways\Models\Gateway;` (alphabetically among `Modules\*` imports) and add `Gateway::class` to `$telephonyModels` inside `registerRoutingCacheObservers()`.

- [x] **Step 4: Clear caches, format, and verify tests pass**

Run:
```bash
tpbx optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest tests/Feature/Observers/RoutingCacheObserverTest.php
```
Expected: Pint passes; all tests in `RoutingCacheObserverTest.php` PASS.

- [ ] **Step 5: Commit R1 (user approval required)**

```bash
git add app/Providers/AppServiceProvider.php tests/Feature/Observers/RoutingCacheObserverTest.php
git commit -m "fix(xml): observe Gateway mutations in RoutingCacheObserver for E911 and outbound dialplans"
```

---

### R2 — Add Global Routing Cache Revision for Custom SIP Scanner Signatures — **COMPLETE (uncommitted)**

**Files:**
- Modify: `app/Support/RoutingCacheVersion.php:10-66`
- Modify: `app/Services/DialplanXmlCollector.php:153-168`
- Modify: `app/Services/Xml/DialplanXmlBuilder.php:419-432`
- Modify: `app-modules/security/src/Support/SipScannerSignatures.php:207-220`
- Modify: `app-modules/security/src/Services/Detection/SipScannerDialplanContributor.php:27-31`
- Test: `app-modules/security/tests/SipScannerSignaturesTest.php`
- Test: `tests/Feature/Observers/RoutingCacheObserverTest.php`

**Interfaces:**
- Consumes: `App\Support\RoutingCacheVersion::store(): Repository`
- Produces:
  - `App\Support\RoutingCacheVersion::globalKey(): string` (returns `'freeswitch:routing-version:global'`)
  - `App\Support\RoutingCacheVersion::bumpGlobal(): void` (increments `'freeswitch:routing-version:global'` on `self::store()` with the same `is_int($store->increment(...))` fallback used by `bump()`)
  - `App\Support\RoutingCacheVersion::getGlobal(): int` (reads `(int) self::store()->get(self::globalKey(), 0)` — strictly cache-only, never a database query)

- [x] **Step 1: Write failing tests for global revision bumping, multi-tenant public dialplan invalidation, and zero-DB-query cache hits**

1. In `app-modules/security/tests/SipScannerSignaturesTest.php`, import `App\Support\RoutingCacheVersion` and `Illuminate\Support\Facades\Cache`, then add:

```php
it('bumps the global routing cache version whenever custom scanner signatures are saved', function (): void {
    Cache::flush();
    expect(RoutingCacheVersion::getGlobal())->toBe(0);

    SipScannerSignatures::saveCustom(['BadScanner/1.0']);
    expect(RoutingCacheVersion::getGlobal())->toBe(1);

    SipScannerSignatures::saveCustom([]);
    expect(RoutingCacheVersion::getGlobal())->toBe(2);
});
```

2. In `tests/Feature/Observers/RoutingCacheObserverTest.php`, import `Illuminate\Support\Facades\DB` and `Modules\Security\Support\SipScannerSignatures`, then add:

```php
it('immediately invalidates cached public dialplans across all tenants on custom scanner signature updates while keeping cache hits query-free', function (): void {
    config([
        'freeswitch.xml_handler.auth' => false,
        'freeswitch.xml_handler.dialplan_cache_ttl' => 60,
        'freeswitch.xml_handler.dialplan_contributor_cache_ttl' => 60,
    ]);
    Cache::flush();

    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $contextA = app(DialplanContext::class)->public((string) $tenantA->id);
    $contextB = app(DialplanContext::class)->public((string) $tenantB->id);

    $queryA = http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $contextA,
        'Caller-Destination-Number' => '1000',
    ]);
    $queryB = http_build_query([
        'section' => 'dialplan',
        'Caller-Context' => $contextB,
        'Caller-Destination-Number' => '1000',
    ]);

    // Warm 60s caches for both tenants prior to adding a custom scanner signature
    $this->get('/api/v1/xml-handler?'.$queryA)->assertOk()->assertDontSee('CustomBot99');
    $this->get('/api/v1/xml-handler?'.$queryB)->assertOk()->assertDontSee('CustomBot99');

    // Verify cached dialplan hit executes ZERO database queries
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->get('/api/v1/xml-handler?'.$queryA)->assertOk();
    expect(DB::getQueryLog())->toBeEmpty();
    DB::disableQueryLog();

    // Save custom global SIP scanner signature — must invalidate both tenants immediately
    SipScannerSignatures::saveCustom(['CustomBot99']);

    $this->get('/api/v1/xml-handler?'.$queryA)->assertOk()->assertSee('CustomBot99');
    $this->get('/api/v1/xml-handler?'.$queryB)->assertOk()->assertSee('CustomBot99');
});
```

- [x] **Step 2: Run the targeted tests to verify they fail**

Run: `./vendor/bin/pest app-modules/security/tests/SipScannerSignaturesTest.php`
Expected: FAIL — `Call to undefined method App\Support\RoutingCacheVersion::getGlobal()`.

- [x] **Step 3: Implement `RoutingCacheVersion::globalKey()`, `bumpGlobal()`, and `getGlobal()`, wire into dialplan cache keys, and call `bumpGlobal()` in `SipScannerSignatures::saveCustom()`**

1. `app/Support/RoutingCacheVersion.php`: Add `public static function globalKey(): string` (`'freeswitch:routing-version:global'`), `public static function bumpGlobal(): void`, and `public static function getGlobal(): int` mirroring `key()`, `bump()`, and `get()` on `self::store()`.
2. `app/Services/DialplanXmlCollector.php`: Append `(string) RoutingCacheVersion::getGlobal()` to the `implode('|', [...])` array in `contributorCacheKey()`.
3. `app/Services/Xml/DialplanXmlBuilder.php`: Append `(string) RoutingCacheVersion::getGlobal()` to the `implode('|', [...])` array in `dialplanCacheKey()`.
4. `app-modules/security/src/Support/SipScannerSignatures.php`: Import `App\Support\RoutingCacheVersion` and call `RoutingCacheVersion::bumpGlobal();` immediately after `SecuritySetting::set(...)` inside `saveCustom()`.
5. `app-modules/security/src/Services/Detection/SipScannerDialplanContributor.php`: Update lines 29–30 of the class docblock to state that custom signatures are merged at render time and invalidated immediately via `RoutingCacheVersion::bumpGlobal()`.

- [x] **Step 4: Clear caches, format, and verify tests pass**

Run:
```bash
tpbx optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest app-modules/security/tests/SipScannerSignaturesTest.php
./vendor/bin/pest tests/Feature/Observers/RoutingCacheObserverTest.php
./vendor/bin/pest tests/Feature/Performance/XmlHandlerZeroSyncQueriesTest.php
```
Expected: Pint passes; all three test files PASS. *(Note: under `--parallel` Pest accepts a single path — run the three files sequentially as listed.)*

- [ ] **Step 5: Commit R2 (user approval required)**

```bash
git add app/Support/RoutingCacheVersion.php app/Services/DialplanXmlCollector.php app/Services/Xml/DialplanXmlBuilder.php app-modules/security/src/Support/SipScannerSignatures.php app-modules/security/src/Services/Detection/SipScannerDialplanContributor.php app-modules/security/tests/SipScannerSignaturesTest.php tests/Feature/Observers/RoutingCacheObserverTest.php
git commit -m "fix(security): invalidate cached public dialplans immediately on custom scanner signature updates"
```

---

### R3 — Align `FS_XML_HANDLER_CACHE_TTL=60` Across `.env.example`, Installer, and Config Comment

> **Scope adjustment vs. the superseded plan:** the `docs/env-reference.md` and `docs/load-testing-guide.md` portions of the original Task 3 are **already complete** (Phase A T5 + T4). Remaining scope: `.env.example`, `scripts/resources/tall.sh`, plus the Task-3 scope expansion accepted in the documentation session — the stale `config/freeswitch.php` header comment.

**Files:**
- Modify: `.env.example:118-122`
- Modify: `scripts/resources/tall.sh:245-246`
- Modify: `config/freeswitch.php` (XML Handler Caching header comment only)
- Test: `tests/Feature/XmlHandlerCacheConfigTest.php`

**Interfaces:**
- Consumes: `config/freeswitch.php` (`env('FS_XML_HANDLER_CACHE_TTL', 60)`)
- Produces: Synchronized `60`-second default across `.env.example` and `scripts/resources/tall.sh` (idempotent seed for fresh installs); config comment accurately describing the 60s default and version-driven invalidation

- [x] **Step 1: Write the failing `.env.example` and installer parity test in `tests/Feature/XmlHandlerCacheConfigTest.php`**

Append to `tests/Feature/XmlHandlerCacheConfigTest.php`:

```php
it('aligns .env.example and the appliance installer script with the 60-second XML handler cache TTL default', function (): void {
    $envExample = (string) file_get_contents(base_path('.env.example'));
    $tallInstaller = (string) file_get_contents(base_path('scripts/resources/tall.sh'));

    expect($envExample)
        ->toContain('FS_XML_HANDLER_CACHE_TTL=60')
        ->not->toContain('FS_XML_HANDLER_CACHE_TTL=5')
        ->and($tallInstaller)
        ->toContain('grep -q "^FS_XML_HANDLER_CACHE_TTL=" .env')
        ->toContain('echo "FS_XML_HANDLER_CACHE_TTL=60" >> .env')
        ->not->toContain('FS_XML_HANDLER_CACHE_TTL=5');
});
```

- [x] **Step 2: Run the targeted test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/XmlHandlerCacheConfigTest.php`
Expected: FAIL — `.env.example` and `scripts/resources/tall.sh` still contain `FS_XML_HANDLER_CACHE_TTL=5`.

- [x] **Step 3: Update `.env.example`, `scripts/resources/tall.sh`, and the `config/freeswitch.php` header comment**

1. `.env.example:118-122`: Update `FS_XML_HANDLER_CACHE_TTL=60` and the four commented subsystem override examples (`# FS_XML_HANDLER_*_CACHE_TTL=60`).
2. `scripts/resources/tall.sh:245-246`: Add a plain-language comment above the `grep -q "^FS_XML_HANDLER_CACHE_TTL="` check explaining that fresh installs default to 60s (backed by `RoutingCacheVersion` instant invalidation) while existing `.env` values are preserved on re-runs, and change `echo "FS_XML_HANDLER_CACHE_TTL=5" >> .env` to `echo "FS_XML_HANDLER_CACHE_TTL=60" >> .env`.
3. `config/freeswitch.php` (scope expansion): reword the "FreeSWITCH XML Handler Caching" header comment — "Short-lived caching avoids rebuilding identical XML responses during call bursts" → wording that states the 60-second master default with `RoutingCacheVersion` immediate invalidation on routing writes (keep it a comment-only change; no value changes).

- [x] **Step 4: Validate shell syntax, format PHP test, and run targeted test**

Run:
```bash
bash -n scripts/resources/tall.sh
tpbx optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest tests/Feature/XmlHandlerCacheConfigTest.php
```
Expected: `bash -n` exits `0` with no output; Pint passes; `XmlHandlerCacheConfigTest.php` PASSES.

- [ ] **Step 5: Commit R3 (user approval required)**

```bash
git add .env.example scripts/resources/tall.sh config/freeswitch.php tests/Feature/XmlHandlerCacheConfigTest.php
git commit -m "fix(installer): align FS_XML_HANDLER_CACHE_TTL=60 across .env.example, tall.sh, and config docs"
```

---

### R4 — Harden `SessionFlushCommand` with Target-Aware Confirmation & Redis DB Collision Guard

**Files:**
- Modify: `app/Console/Commands/SessionFlushCommand.php:43-85,156-179`
- Test: `tests/Feature/Console/SessionFlushCommandTest.php`

**Interfaces:**
- Consumes: `config('session.driver')`, `config('session.connection')`, `config('session.files')`, `config('session.table')`, `config('database.redis.*')`
- Produces:
  - `private function describeTarget(string $driver): string` — resolves human-readable target metadata *before* prompting:
    - `'redis'` -> `"driver: redis, connection: [{$connectionName}], endpoint: [{$host}:{$port}], database: [{$dbIndex}]"`
    - `'database'` -> `"driver: database, connection: [{$connectionLabel}], table: [{$table}]"` (where `$connectionLabel` falls back to `(string) config('database.default')` when `session.connection` is `null`)
    - `'file'` -> `"driver: file, path: [{$path}]"`
    - `'cookie'` -> `"driver: cookie (client-side only; no server store will be modified)"`
    - `'array'` -> `"driver: array (in-memory)"`
    - `default` -> if `app('session')->driver()->getHandler() instanceof CacheBasedSessionHandler`, `"driver: {$driver} (WARNING: cache-backed handler flushes the entire backing cache store)"`; otherwise `"driver: {$driver}"`
  - `private function detectRedisDbCollision(string $connectionName): ?string` — compares normalized `(host, port, database)` (or `url` + `database` when `url` is set) of `database.redis.{$connectionName}` against `database.redis.default` and `database.redis.cache` (skipping self-comparison if `$connectionName` is literally `'default'` or `'cache'`, which is itself a collision with that role: if `$connectionName === 'default'` return `'default'`, if `$connectionName === 'cache'` return `'cache'`, and otherwise if another connection `'default'` or `'cache'` shares the exact same endpoint + DB index, return that colliding connection name; otherwise `null`).
  - Confirmation prompt format: `"This will invalidate all active HTTP sessions across all tenants and administrators ({$target}). Do you wish to continue?"`
  - Collision guard behavior on `'redis'`: If `$collidingConnection = $this->detectRedisDbCollision($connectionName)` is non-null and `! $this->option('force')`, emit `$this->components->error("Refusing to run FLUSHDB: Redis session connection [{$connectionName}] shares database [{$dbIndex}] on [{$host}:{$port}] with Redis [{$collidingConnection}] connection. Isolate REDIS_SESSION_DB (e.g. DB 2) or pass --force to override.")` and return `self::FAILURE` **before** prompting or calling `flushdb()`. When `--force` is passed on a colliding DB, emit `$this->components->warn(...)` and proceed.

- [x] **Step 1: Update existing confirmation tests and add Redis target disclosure + DB collision guard tests in `tests/Feature/Console/SessionFlushCommandTest.php`**

1. Preserve/restore `database.redis.session`, `database.redis.default`, and `database.redis.cache` in `beforeEach` / `afterEach`.
2. Update the two existing confirmation tests to set a deterministic driver (`array`) and expect the target-aware confirmation prompt: `'This will invalidate all active HTTP sessions across all tenants and administrators (driver: array (in-memory)). Do you wish to continue?'`
3. Add three new tests:

```php
it('discloses the exact Redis connection, host, port, and database index in the confirmation prompt before flushing', function (): void {
    config([
        'session.driver' => 'redis',
        'session.connection' => 'session',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
        'database.redis.session' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '13'],
    ]);

    $this->artisan('session:flush')
        ->expectsConfirmation(
            'This will invalidate all active HTTP sessions across all tenants and administrators (driver: redis, connection: [session], endpoint: [127.0.0.1:6379], database: [13]). Do you wish to continue?',
            'no'
        )
        ->expectsOutputToContain('Session flush cancelled.')
        ->assertSuccessful();
});

it('refuses to run FLUSHDB without --force when the session Redis database collides with default or cache', function (string $collidingDb, string $expectedRole): void {
    Redis::shouldReceive('connection')->never();

    config([
        'session.driver' => 'redis',
        'session.connection' => 'session',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
        'database.redis.session' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => $collidingDb],
    ]);

    $this->artisan('session:flush')
        ->expectsOutputToContain("shares database [{$collidingDb}]")
        ->expectsOutputToContain("with Redis [{$expectedRole}] connection")
        ->assertFailed();
})->with([
    'collides with default DB 0' => ['0', 'default'],
    'collides with cache DB 1' => ['1', 'cache'],
]);

it('allows overriding the Redis database collision guard when --force is explicitly passed', function (): void {
    $redisConn = \Mockery::mock();
    $redisConn->shouldReceive('flushdb')->once()->andReturnTrue();
    Redis::shouldReceive('connection')->once()->with('session')->andReturn($redisConn);

    config([
        'session.driver' => 'redis',
        'session.connection' => 'session',
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '0'],
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
        'database.redis.session' => ['host' => '127.0.0.1', 'port' => '6379', 'database' => '1'],
    ]);

    $this->artisan('session:flush', ['--force' => true])
        ->expectsOutputToContain('shares database [1]')
        ->expectsOutputToContain('Flushed Redis session store')
        ->assertSuccessful();
});
```
*(Remember `use Illuminate\Support\Facades\Redis;` at the top of `SessionFlushCommandTest.php`.)*

- [x] **Step 2: Run `SessionFlushCommandTest.php` to verify the tests fail**

Run: `./vendor/bin/pest tests/Feature/Console/SessionFlushCommandTest.php`
Expected: FAIL — prompt text does not include target descriptor and Redis DB collision does not abort before `flushdb()`.

- [x] **Step 3: Implement `describeTarget()` and `detectRedisDbCollision()` in `app/Console/Commands/SessionFlushCommand.php`**

In `SessionFlushCommand::handle(Filesystem $filesystem): int`:
1. Resolve `$driver = (string) config('session.driver', 'redis');` first.
2. If `$driver === 'redis'`, check `$this->detectRedisDbCollision($connectionName)`:
   - When non-null and `! $this->option('force')`, log the error via `$this->components->error(...)` and return `self::FAILURE` immediately (before prompting).
   - When non-null and `$this->option('force')`, emit `$this->components->warn(...)` noting `--force` override.
3. Build `$target = $this->describeTarget($driver);` and pass `"This will invalidate all active HTTP sessions across all tenants and administrators ({$target}). Do you wish to continue?"` to `$this->confirm(...)` when `! $this->option('force')`.

- [x] **Step 4: Clear caches, format, and verify tests pass**

Run:
```bash
tpbx optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest tests/Feature/Console/SessionFlushCommandTest.php
```
Expected: Pint passes; all tests in `SessionFlushCommandTest.php` PASS.

- [ ] **Step 5: Commit R4 (user approval required)**

```bash
git add app/Console/Commands/SessionFlushCommand.php tests/Feature/Console/SessionFlushCommandTest.php
git commit -m "fix(console): disclose session:flush target before confirmation and guard Redis DB collisions"
```

---

### R5 — Synchronize `INSTALL.md` & `CHANGELOG.md` for `v4.3.0` Readiness

> **D7(c) is binding on this task:** do NOT add a custom-module fail-closed breaking-change callout and do NOT add v4.2.0→v4.2.1 upgrade guidance to the v4.3.0 notes.

**Files:**
- Modify: `INSTALL.md:9-23,166-183,206,230,285-287,314-316,403-411,472`
- Modify: `CHANGELOG.md:8-22` (curating the existing `[Unreleased]` block — the Phase A `### Changed`/`### Fixed` entries are already present)

- [x] **Step 1: Update `INSTALL.md` for PHP 8.5, `tpbx` CLI parity, `FS_XML_HANDLER_CACHE_TTL=60`, and `permissions:sync`**

Apply these targeted updates in `INSTALL.md` (§1 PHP Runtime bullet; administrator-password section → `tpbx admin:password` variants; sound prompts → `tpbx pbx:sounds:default`; Redis Cache & Session Storage → `FS_XML_HANDLER_CACHE_TTL=60` and `tpbx optimize:clear && tpbx optimize`; File Permissions → `sudo tpbx permissions:repair --scope=full`; firewall CLI examples → `tpbx security:status` / `tpbx security:unban`; Manual CLI Upgrade → the `tpbx migrate --force` / `tpbx module:sync --only-local` / `tpbx permissions:sync` / `tpbx optimize:clear` / `sudo tpbx permissions:repair --scope=full` / `fs_cli -x "reloadxml"` sequence; lockout troubleshooting → `` `tpbx` (`php artisan`) commands ``).

- [x] **Step 2: Update `[Unreleased]` in `CHANGELOG.md`**

- Update the `### Added` → **Session Flush Console Command** bullet to note pre-confirmation target disclosure (connection, endpoint, DB index / path / table) and the Redis DB collision safety guard preventing accidental `FLUSHDB` when `REDIS_SESSION_DB` collides with `REDIS_DB` (`DB 0`) or `REDIS_CACHE_DB` (`DB 1`).
- Under `### Changed`: keep the existing Phase A entries (cache-sweep script behavior, and add the XML Handler Cache TTL Installer & Environment Alignment bullet with the explicit **Upgrade Note** — existing servers whose `.env` still specifies `FS_XML_HANDLER_CACHE_TTL=5` preserve their explicit setting on installer re-runs; set `FS_XML_HANDLER_CACHE_TTL=60` in `.env` and run `tpbx optimize:clear` to adopt the 60-second window) plus Installation & Upgrade Guide Parity (PHP `8.5` floor, `tpbx`/`tallpbx` global CLI, `tpbx permissions:sync`).
- Under `### Fixed`: add **Gateway Dialplan Cache Invalidation** (R1) and **Custom SIP Scanner Signature Dialplan Invalidation** (R2) bullets as worded in the superseded plan.
- Do **not** add the D7(c)-excluded items.

- [x] **Step 3: Verify documentation consistency**

Run:
```bash
grep -n "FS_XML_HANDLER_CACHE_TTL=5" .env.example scripts/resources/tall.sh INSTALL.md README.md docs/env-reference.md docs/load-testing-guide.md || true
grep -n "permissions:sync" INSTALL.md
```
Expected: First `grep` produces empty output (zero stale `=5` references across those six files); second `grep` shows `tpbx permissions:sync` in `INSTALL.md`.

- [ ] **Step 4: Commit R5 (user approval required)**

```bash
git add INSTALL.md CHANGELOG.md
git commit -m "docs: synchronize INSTALL.md and CHANGELOG.md for post-v4.2.0 remediations"
```

---

# Phase C — Consolidated Verification & Release Gate (C1–C3)

*Runs only after Phase B completes, per the user's sequencing directive: "Re-running tests should come after implementing docs/post-v420-remediation-implementation-plan.md" (now this plan).*

### C1 — Consolidated targeted test pass over everything changed in Phases A+B

- [ ] **Step 1:** Re-run the targeted suites covering all changed behavior, each with `--parallel`, sequentially per file (Pest's parallel mode accepts a single path):
  - `./vendor/bin/pest tests/Feature/XmlHandlerCacheConfigTest.php --parallel` (Phase A docs + R3)
  - `./vendor/bin/pest tests/Feature/Observers/RoutingCacheObserverTest.php --parallel` (R1 + R2)
  - `./vendor/bin/pest app-modules/security/tests/SipScannerSignaturesTest.php --parallel` (R2)
  - `./vendor/bin/pest tests/Feature/Performance/XmlHandlerZeroSyncQueriesTest.php --parallel` (R2 zero-query contract)
  - `./vendor/bin/pest tests/Feature/Console/SessionFlushCommandTest.php --parallel` (R4)
- [ ] **Step 2:** Sweep the suite for any test asserting on touched docs and run those too: `grep -rln "load-testing\|run-cache-sweep\|env-reference\|architecture.md\|README" tests/ | grep -v Browser` → run each hit.
- [ ] **Step 3:** Expected: every targeted file PASSES. Record exact pass counts as verification evidence for C3.

### C2 — Full 60-Second Re-Benchmark Across All Four Reference Tiers (D2b)

**Environment:** the four datacenter VPS tiers from `docs/load-testing-results.md` (1 vCPU/1 GB, 1 vCPU/2 GB, 2 vCPU/2 GB, 4 vCPU/16 GB), installed with current defaults (`FS_XML_HANDLER_CACHE_TTL=60`, installer-tuned static PHP-FPM pools), and — disclosed in the published results — the **full default `nftables` firewall enabled** on the PBX.

- [ ] **Step 1:** Per tier: provision/resize the reference VPS, run the installer, seed load-test accounts (`tpbx pbx:load-test:seed` — alphanumeric passwords only, e.g. `LoadTest1234`), and sync `storage/app/load-tests/sipp-users.csv` to the load generator (`scp`), per `docs/load-testing-guide.md`.
- [ ] **Step 2:** Whitelist the PBX IP on the generator's ingress firewall via the documented bounded procedure (`sudo nft add element inet tallpbx_filter whitelist_ips { <PBX_IP> }`) — test-host only, never on the PBX.
- [ ] **Step 3:** Re-warm SIP registrations (`Expires: 3600`) before extended scenarios; run the XML throughput suites (`tpbx pbx:load-test:dialplan` at `100 x 5`, `500 x 25`, `1,000 x 25`, `mixed` + `cache-hit` scenarios, median of 3 repetitions) and `bash scripts/run-cache-sweep.sh` (now sweeps the 60s default and restores operator TTLs).
- [ ] **Step 4:** Run the SIPp capacity suites per guide (`scripts/pbx-sipp-validate.sh` with `MEDIA_FLOW=1`, `EXTENDED=1`; clean up background UAS listeners between steps to avoid port collisions; mind `EXTENDED_UAC_LOCAL_PORT` defaults when the generator also runs FreeSWITCH).
- [ ] **Step 5:** Regenerate the tables in `docs/load-testing-results.md` at TTL=60: capacity comparison, XML throughput comparison, per-tier scaling and cache-policy tables — and **remove every legacy-test artifact** (the legacy 5s baseline rows, 30s profile rows, "Legacy default" labels, and the "measured at the legacy TTL=5" scope notes all disappear once replaced by fresh results). Standard profiles: cache-disabled / default-60 (`mixed`) / default-60 (`cache-hit` ceiling); the "Dialplan cache off" diagnostic is included only per the user's final keep/remove decision; the "call-center 30s" profile is retired and must not appear in the documentation or the script after the simplification pass. Update the sizing matrix where numbers move; every regenerated table carries the disclosure — "measured with the full default `nftables` firewall enabled"; obfuscate public IPs (last octet only); decimal units (GB/MB); artifacts under `storage/app/load-tests/`.
- [ ] **Step 6:** Update `CHANGELOG.md [Unreleased]` with a short **Changed** bullet summarizing the re-benchmark (60s default, firewall-enabled methodology) — plain English, administrator perspective.
- [ ] **Step 7:** Commit C2 (user approval required): `docs(load-testing): re-benchmark all tiers at 60s default under full default firewall`.

### C3 — Final Pre-Release Verification Gate (`v4.3.0` Readiness)

- [ ] **Step 1: Clear compiled caches and verify clean code style**

Run:
```bash
git branch --show-current
tpbx optimize:clear
./vendor/bin/pint --dirty --test
```
Expected: Branch is `4.x`; caches clear cleanly; Pint reports zero style issues.

- [ ] **Step 2: Run targeted Playwright browser smoke tests for `layouts/app.blade.php` (`64e58a7c`) and decomposed Blade views**

Run:
```bash
./vendor/bin/pest tests/Browser/PanelOperationsSmokeTest.php
./vendor/bin/pest tests/Browser/CertificateManagerBrowserTest.php
./vendor/bin/pest tests/Browser/SecurityCenterBrowserTest.php
```
Expected: PASS with zero browser console/Alpine expression errors on `<x-tooltip>` in the mini navigation rail or decomposed Security Center / Certificate Manager tabs.

- [ ] **Step 3: Run the single pre-release parallel Pest test gate (the only full-suite run of this plan)**

Run:
```bash
tpbx test --compact --parallel
```
Expected: Full suite PASSES with 0 failures (including `SddLedgerHygieneTest` and `UiArchitectureTest`).

- [ ] **Step 4: Run final whole-branch SDD review, report verification evidence, and await release-cut / push confirmation**

Dispatch the final whole-branch code reviewer (`superpowers:requesting-code-review`) over `v4.2.1..HEAD` per `superpowers:subagent-driven-development` (the superseded plan's `b7cd1837..HEAD` reference is replaced by the `v4.2.1` tag anchor), clean up this plan's `.superpowers/sdd/<workspace>` directory once clean, and report exact pass counts, `git log --oneline v4.2.1..HEAD`, and any recorded `Ruling:` entries to the user. Once the user confirms cutting `v4.3.0`, retire `docs/latest-implementation-plan.md`, move `[Unreleased]` in `CHANGELOG.md` to `## [4.3.0] - YYYY-MM-DD`, commit `chore(release): v4.3.0`, and tag/push only as instructed.

---

## Deferred Items

Tracked out-of-scope findings accepted during the v4.3.0 cycle. Each entry must be scheduled into a future implementation plan; none block this plan's execution.

- [ ] **D-1 (deferred 2026-10-30, decided with user approval): Duplicated impersonation session key constant.** `App\Support\PanelActor::isImpersonating()` hard-codes the string literal `'impersonation.original_admin_id'`, duplicating `App\Services\ImpersonationService::SESSION_ORIGINAL_ADMIN_ID` (a `private const`). Renaming either side silently desynchronizes impersonation detection across middleware, Livewire action guards, and edit/list components. Fix sketch: promote the constant to `public` on `ImpersonationService` and reference it from `PanelActor` (and any other literal sites found by grep); verify with `tests/Feature/Auth/ImpersonationFeatureTest.php` and `tests/Unit/Support/PanelActorTest.php`. Small, low-risk refactor deferred to a future cycle to keep this plan scoped to its reviewed findings.

## Accepted Design Decisions (no action, binding on future reviews)

- **D5(c) — `GET /panel/impersonation/stop` stays.** The state-changing GET is a deliberate anti-lockout escape hatch: an administrator impersonating a tenant user who hits a 403 page must always be able to stop impersonation (themed 403 button or direct address-bar navigation), mirroring the project's lockout-prevention philosophy. The associated logout-CSRF exposure is an accepted risk. Future work may harden it (e.g., rate limiting) but must never remove the GET route or its 403-page escape button.
