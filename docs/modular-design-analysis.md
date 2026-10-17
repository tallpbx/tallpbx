# Modular Design Analysis: Security Module Split & DDD Adherence

Date: 2026-10-16 · Scope: `app-modules/security` boundary review and whole-project Domain-Driven Modular Monolith assessment.

Reference design guides:

- "Exploring Modular Monolithic Architecture — a Laravel Developer's Guide" (Medium, @harryespant) — not retrievable (HTTP 403); its shared criteria are covered by the two below.
- "Best Architectural Patterns Approach for Senior Laravel Developers" (dev.to, @abdulsalamamtech)
- "Modularizing the Monolith: a Real-World Experience" (mateusguimaraes.com)

---

## Part 1 — Breaking Up the Security Module

### 1.1 Current State (Measured)

| Metric | Security | Next largest | Median module |
|---|---|---|---|
| src PHP LOC | **10,206** | admin (3,311), certificates (3,247), file-stores (2,740) | ~470 |
| src PHP files | **59** | certificates / file-stores (27) | ~7 |
| Module tests LOC | **9,524** (40 files) | — | — |
| Blade views | 17 files, 2,785 LOC | — | — |
| Migrations | 12 | — | — |
| Artisan commands | 8 (+ 4 scheduled jobs) | — | 0–1 |
| Share of all module src LOC | **21%** (10,206 / 48,790) | — | — |

The module reached this size in one month (created 2026-09-18; 95 commits: 64 in September, 31 in October), absorbing in sequence: firewall core → attack protection/bans → threat feeds → observe mode → geo-blocking (v4.0.1).

### 1.2 Domain Inventory — the Natural Seams

| Sub-domain | Key classes (LOC) | Tables |
|---|---|---|
| **Firewall ruleset engine** | `SecurityConfigGenerator` (1,044), `SecurityExecutor` (404), `LockoutGuardService` (275), `FirewallBanReconciler` (245), `FirewallSyncVerifier` | security_rules, security_services, security_settings |
| **Ban / attack protection** | `SecurityBanService` (319), `LogFailedLoginListener`, `LogFailedSipAuthListener` | security_bans, security_ip_lists, security_audit_logs |
| **Threat feeds** | `ThreatFeedManager`, `ThreatFeedIngestionService` (262), `VoipblFeedProvider` | security_threat_feeds |
| **Geo-blocking** | `GeoBlockingService` (304), `IpdenyProvider` | security_geo_rules |
| **SIP scanner defense** | `SipScannerSignatures` (295), `LogSipScannerListener` (221), `SipScannerDialplanContributor` (dialplan XML injection) | — |
| **Observe mode** | `ObserveMetricsParser` (236), `SecurityObserveCommand` | — |
| **UI cockpit** | `SecurityManager` (546) + 7 concern traits (~2,450) + 6 tabs + 4 modals + 2 drawers | — |

### 1.3 Coupling Analysis

**External coupling: effectively zero.** A full grep of `app/`, `routes/`, `config/`, `resources/`, and all other modules finds **no production code outside the module referencing `Modules\Security\`** — no class imports, FQCNs, or container string bindings. The only outside touchpoints are command names in the scheduler (`routes/console.php`) and two root test files (browser smoke + seeder test). Its integration with the rest of the system follows the recommended patterns:

- Listens to shared events (`App\Events\FreeSwitch\SofiaFailedAuth`, `SipScannerDetected`, `Illuminate\Auth\Events\Failed`) — inbound, event-driven.
- Implements shared contracts (`App\Contracts\DialplanGuardXmlContributor`, `ContextWideDialplanXmlContributor`) via the tagged `dialplan.xml` extension point — outbound, dependency-inverted.
- Uses only `App\Models\Admin` and one shared UI trait.

**Internal coupling: high, by design.** This is what blocks a clean split:

1. **One output artifact.** `SecurityConfigGenerator::generate()` compiles settings, services, rules, IP lists, bans, and geo rules into a single nftables ruleset with safety-critical stage ordering (pre-filter stages 1–7, diagnostics 8–9, geo perimeter 9.5, service catalog 10–12; see `docs/security-architecture.md`).
2. **Cross-cutting safety invariants.** `LockoutGuardService` must reason about every feature that can drop traffic. The geo L4 lockout finding (geo drop at stage 9.5 preceding the web-port accept) is precisely the class of bug that gets easier to create when drop-capable features live in separate modules.
3. **Shared kernel-state protocols.** Threat feeds and geo-blocking both write `external_blocklist.nft.pending` under an flock read-modify-write protocol (geo remediation findings M5/DM-2), and both populate kernel sets referenced by one chain.
4. **Shared privileged infrastructure.** One bounded sudoers helper (`/usr/local/sbin/tallpbx-security`) serves ban/unban/elements/apply across all sub-domains.
5. **One product surface.** The Security Center cockpit has two master switches governing all sub-domains, and the holistic parity rules in `AGENTS.md` require synchronized state reflection across tabs (e.g., disabling External Blocklists greys out its Firewall Rules row). That contract would become a cross-module broadcast problem after a split.

### 1.4 Pros and Cons of Splitting Into Multiple Modules

**Pros**

| # | Benefit | Weight |
|---|---|---|
| 1 | Smaller cognitive units; the module is 3× the next largest | Real, but addressable internally |
| 2 | Independent enable/disable of e.g. geo-blocking or threat feeds | Weak — both feed the same ruleset; a "geo module" disabled mid-flight creates DB↔kernel drift risk the module currently guards against |
| 3 | Change isolation (geo churn wouldn't touch firewall core tests) | Moderate — but today's cross-cutting tests (PreFilterOrder, TwoSwitches) exist because the interactions are the risk |
| 4 | Matches taxonomy hints (`docs/modular-architecture.md` §1 mentions "security, and firewall" as if separate) | Cosmetic / doc drift |
| 5 | Future extraction path | Irrelevant for a single-host appliance |

**Cons**

| # | Cost | Weight |
|---|---|---|
| 1 | The ruleset compiler would need cross-module DB reads or a contributor orchestration layer with strict, safety-critical stage ordering — rebuilding, inside the app, the coordination that currently lives in one class | **High** |
| 2 | Lockout-guard invariants span sub-domains; the two documented outage classes in `AGENTS.md` came from exactly this kind of partial-ruleset thinking | **High** |
| 3 | Cross-module file-lock protocols (`external_blocklist.nft.pending`) and the shared set/chain namespace become inter-module contracts — harder to evolve than intra-module ones | High |
| 4 | Multiplied operational surface: 2–4× manifests, providers, permission sets (`security.geo-blocking.manage` fragmentation), 3-locale dictionaries, `module:sync`/scheduling, docs, and parity obligations per module | Medium |
| 5 | The UI cockpit must fragment into multiple pages (product regression — the single-screen Command Center is deliberate) or become a cross-module aggregator component | Medium-High |
| 6 | The module's boundary quality is exemplary — zero importers, event+contract integration. A split cannot improve the best-isolated module in the codebase; it can only add seams | **Decisive** |

### 1.5 Verdict

**The module has a size problem; splitting it into multiple modules is not the right response today.**

The reference guides converge on one principle: modularize along *communication boundaries*, not along *size*. The pain here is intra-module: a 1,044-line god-compiler, a 546-line component plus seven ~350-line traits, and a flat `Services/` folder mixing six sub-domains. That is an internal structure problem with an internal fix and none of the split's cons.

### 1.6 Recommendations

#### 1.6.1 Backend & Compiler Architecture
1. **Keep one module; formalize sub-domain namespaces inside it.** Restructure `src/` into `Firewall/`, `Bans/`, `ThreatFeeds/`, `Geo/`, `Detection/`, `Observe/` (or equivalent), keeping `Contracts/` and the provider as the module's public surface. This achieves the "mini-apps" cognitive benefit at zero boundary cost.
2. **Decompose `SecurityConfigGenerator` into per-stage compilers** (`PreFilterCompiler`, `ServiceRulesCompiler`, `GeoPerimeterCompiler`, `BlocklistCompiler`…) orchestrated by a lean generator using the existing `preFilterOrder()` machinery. The test suite already slices it this way (`SecurityConfigGeneratorGeoTest`, `...PreFilterOrderTest`, `...TftpTest`, `...TwoSwitchesTest`) — the seams are proven. Target: no class over ~400 LOC.
3. **Unify the apply pipeline into a `FirewallApplier` domain service.** Currently, the 5-step apply sequence (assert admin safe → assert local services safe → write pending config → validate syntax with `nft -c` → bounded helper apply → record audit log → broadcast `FirewallRulesetUpdated`) is duplicated across `SecurityManager::autoApplyFirewallRuleset()`, `SecurityApplyCommand`, and `FirewallBanReconciler`. Encapsulating this into a `FirewallApplierInterface` / `FirewallApplier` returning a structured `FirewallApplyResult` eliminates cross-interface duplication and establishes a single atomic execution path for all firewall mutations.
4. **Record explicit split trigger criteria:** a genuine requirement for independently toggleable IP-intelligence sources; contributor/third-party demand for ruleset stages; or src LOC trending past ~15k despite the internal decomposition.
5. **If a split ever happens**, the only defensible seam is *ruleset engine* (core: firewall + bans + lockout guard + detection) vs. *IP intelligence sources* (threat feeds + geo) feeding it through a versioned `RulesetElementContributor` contract with explicit stage ordering — a redesign, not a refactor, deserving its own plan.

#### 1.6.2 UI Cockpit Architecture (`SecurityManager`)
1. **Retain `SecurityManager` as a single Livewire component lifecycle (Reject Nested Tab Components).** An earlier 3.x proposal suggested decomposing `SecurityManager` into nested child components (`<livewire:security.tabs.* />`). In Livewire 4, nested child components introduce independent HTTP request lifecycles, event-bus latency (`$this->dispatch('security-state-changed')`), and reactive prop synchronization lag. Because the Security Center relies on master switches (Firewall On/Off, Observe Mode, Pre-filters Bypassed) and strict lockout guards governing all tabs simultaneously, a single component lifecycle with focused concern traits is vastly superior for atomic consistency and instant visual feedback.
2. **Eliminate render overload with lazy `#[Computed]` properties.** `SecurityManager::render()` currently queries records for all 6 tabs on every single request (querying blacklist, whitelist, bans, rules, service catalog, scanner incidents, counters, and feeds). Refactoring these queries into Livewire 4 `#[Computed]` properties referenced only in their respective tab views ensures that inactive tabs execute zero database queries during requests.
3. **Realign domain trait boundaries.** Move mislocated methods into their matching UI concerns (e.g. moving `setTftpDefense` and `tftpDefenseCounters` out of `ManagesAttackProtection` into `ManagesFirewallRules`, where the TFTP defense workbench is visually presented).
4. **Decompose oversized Blade views into partials.** Large tab views like `tabs/firewall-rules.blade.php` (730 LOC) should be split into readable partials (`prefilter-pipeline.blade.php`, `rules-table.blade.php`, `system-services.blade.php`, `tftp-defense.blade.php`), reducing cognitive and editor context overhead without changing component architecture.

---

## Part 2 — DDD / Modular Monolith Adherence

### 2.1 Scorecard

| Principle (from the guides) | Verdict | Evidence |
|---|---|---|
| Code organized by **domain**, not technical type | **Strong** | 60 modules in `app-modules/` map to business capabilities (dialplans, voicemails, ring-groups, fax…), each a mini-app with own routes/views/lang/migrations/tests — including module-local tests, the mateusguimaraes end-state |
| **Bounded contexts with enforced boundaries** | **Strong (exceptional)** | `tests/Feature/Modules/ModuleBoundaryTest.php` enforces *declared* cross-module references plus an **acyclic dependency graph**; `ModuleProviderArchitectureTest.php` locks provider discipline; `TranslationParityTest.php` partitions language keys. Most real-world modular monoliths have zero such enforcement |
| **Communication via events/interfaces, not deep coupling** | **Good** | Security ↔ rest via events (`App\Events\FreeSwitch\*`) and contracts (`dialplan.xml` tagged contributors); Reverb broadcasting for UI. Several modules still import sibling models directly (see 2.3) |
| **Module-owned persistence & self-containment** | **Strong** | Every module owns models/migrations/factories; uniform `module.json` + declarative base provider; 100% root PSR-4 autoloading (the 4.x redesign removed path repositories — rationale in `docs/modular-architecture.md` §7.1) |
| **Shared kernel stays thin & generic** | **Mixed** | `app/Models` is identity-only (good), but `app/Services` and `app/Listeners` hold domain orchestration (see 2.3) |
| **Ubiquitous language** | **Good** | FreeSWITCH-aligned naming, tri-locale dictionaries per module, collision-guarded |
| **DDD tactical patterns** (aggregates, VOs, DTOs, layering) | **Weak by choice** | Modules use technical layering (`Models/`, `Services/`, `Livewire/`); interfaces exist where they pay (`SecurityExecutorInterface`, `MediaStorageServiceInterface`) but no DTO/VO discipline. Per the guides this is appropriate, not a defect: "avoid over-engineering… adopt stricter DDD as complexity grows" |
| **Fail-soft independent module lifecycle** | **Strong** | Requirement-based disable, `module:sync`, collision guards (routes/schema/permissions/lang), core modules non-uninstallable |

**Overall: ~8/10 as a pragmatic modular monolith; ~5/10 as strict DDD — which lands almost exactly where the reference guides say a project of this profile should land.** The architecture is enforced by tests, not just documented, which is rare.

### 2.2 What the Project Gets Right (keep doing)

1. The declarative `App\Support\ModuleServiceProvider` base (menu/permissions/listeners/commands as data) with an arch test that *forbids* ad-hoc boot logic — the mechanism that keeps 60 modules uniform.
2. Cross-module src coupling is genuinely low and **matches declared manifests** (verified: backups→file-stores, hot-desking→devices+extensions, outbound-routes→gateways…). File-stores is the most-consumed module (8 importers), consumed via both models and service interfaces.
3. The security module demonstrates the ideal integration style: events in, contracts out, zero importers.

### 2.3 Violations / Smells (ranked)

1. **Shared-kernel domain leakage (medium):** `app/` reaches into module internals — `app/Listeners/FreeSwitch/*` write module models directly, `app/Services/TenantDefaults/*` provision dialplans/sip-profiles/feature-codes, `app/Services/DestinationResolver` implements routing logic, `PbxLoadTestSeedCommand` imports 17 module namespaces. These are integration adapters living in the shared layer; the security module proves the better in-repo pattern (module-owned listeners subscribing to shared events).
2. **Model-level coupling between modules (medium):** `MediaAsset` imported in 20 src files, `Extension` in 18. Declared and acyclic, but couples schemas across contexts. Service-interface seams (`MediaStorageServiceInterface`) exist yet are bypassed where convenient.
3. **One module dominating (Part 1 subject):** security is 21% of module src; structural imbalance breeds god-class problems.
4. **Category taxonomy is organizational, not domain-driven (low):** "advanced" is a grab-bag (security, certificates, file-stores, acl, email-*, tenant-limits, event-rate-limits); "monitoring" mixes ESL dashboards with xml-cdr. Sidebar parents are hardcoded, so bounded-context clustering is fuzzy at the edges — FusionPBX-parity pragmatism; acceptable, but worth knowing when judging which modules belong together.
5. **Minor:** two providers with custom `boot()` (admin, file-stores — allowlisted in the arch test); domain events largely live in the shared kernel (`app/Events/FreeSwitch`) rather than being module-owned; `docs/modular-architecture.md` §1 still names "firewall" as a separate example module that does not exist (doc drift).

### 2.4 Recommendations for the Whole Project

1. **Add a Pest arch test for the shared kernel's reverse dependencies:** forbid `use Modules\` inside `app/` outside an explicit allowlist (AppServiceProvider composition root, the FreeSwitch listener bridge, TenantDefaults, load-test tooling). Then opportunistically migrate those adapters to module-owned listeners over shared events — the security module is the template, and each migration is small and independently testable (the mateusguimaraes "weekly extraction PR" cadence).
2. **Prefer contract seams over model imports for new cross-module code**, and consider a directional arch rule for the worst offender (e.g., only `Modules\FileStores\` implementations may instantiate `FileStore`/`MediaAsset`; consumers go through `MediaStorageServiceInterface`). Do not retrofit everything at once.
3. **Record the security-module decision** (single module + internal sub-domain namespaces + stage-compiler decomposition + split triggers) as an architecture decision note in `docs/modular-architecture.md`, including fixing the "security, and firewall" example list.
4. **Do not chase strict DDD layering** (Domain/Application/Infrastructure folders, DTOs everywhere). At 60 modules with enforced boundaries and a thin-enough shared kernel, the added ceremony would not pay for itself — both guides explicitly warn against it for exactly this profile of application.

---

## Bottom Line

TallPBX is a well-above-average implementation of the modular monolith style the reference articles describe — past the dev.to article's baseline and most of the way to the mateusguimaraes end-state, with test-enforced boundaries most projects never achieve. The one place it visibly strains is the security module's *internal* organization, and that is fixable without touching the module boundary that is currently its greatest strength.
