# TallPBX Outstanding Tasks Implementation Roadmap

- **Date**: 2026-10-16
- **Branch**: `4.x`
- **Scope**: Comprehensive consolidated master implementation roadmap integrating all architectural analyses, UI design audits, security module refactoring plans, and whole-project Domain-Driven Modular Monolith improvements.
- **Authority**: This document is the single authoritative source of truth for all outstanding engineering tasks across TallPBX.
- **Maintenance**: Statuses, audit figures, and the milestone log are maintained together — see [Task Status Index & Maintenance Cadence](#task-status-index--maintenance-cadence).

---

## Table of Contents
1. [Task Status Index & Maintenance Cadence](#task-status-index--maintenance-cadence)
2. [Stream 1: Security Module Sub-domain Refactoring & Performance](#stream-1-security-module-sub-domain-refactoring--performance)
3. [Stream 2: UI/UX Consistency, Loading Feedback & Typography](#stream-2-uiux-consistency-loading-feedback--typography)
4. [Stream 3: Real-Time Telephony Push Architecture (Echo/Reverb)](#stream-3-real-time-telephony-push-architecture-echoreverb)
5. [Stream 4: Shared Kernel Boundary & Modular Discipline](#stream-4-shared-kernel-boundary--modular-discipline)
6. [Stream 5: Application Security Hardening](#stream-5-application-security-hardening)
7. [Completed & Verified Milestone Log](#completed--verified-milestone-log)

---

## Task Status Index & Maintenance Cadence

- **Status legend**: `Pending` (not started), `In Progress` (work landed but the task is not closed), `Verified` (task closed — its commit appears in the Completed & Verified Milestone Log).
- **Refresh cadence**: every quantitative figure in this document (file counts, LOC, occurrence counts) is re-measured against the working tree whenever this document is edited, and carries the date it was measured. When a figure and the code disagree, the code wins.
- **Close-out rule**: a task is closed only in the same commit that appends its commit SHA to the Milestone Log and flips its status here.
- **Audit sources**: the original audit reports (`ui-audit-report.md`, `architecture-audit-report.md`, `project-audit-report.md`, `modular-design-analysis.md`, `plan-security-module-performance-refactor.md`) have been consolidated into this roadmap and retired; their surviving findings live on as the tasks below.

| Task | Title | Status | Figures verified |
| :--- | :--- | :--- | :--- |
| 1.1.1 | Extract `FirewallApplier` domain service | Verified | 2026-10-17 |
| 1.1.2 | Lazy `#[Computed]` properties for cockpit tabs | Verified | 2026-10-17 |
| 1.1.3 | Geo-blocking cache freshness & whitelist fail-closed integrity | Verified | 2026-10-17 |
| 1.2.1 | Decompose `SecurityConfigGenerator` | Verified | 2026-10-17 |
| 1.3.1 | Reorganize `src/Services/` into sub-domain namespaces | Verified | 2026-10-17 |
| 1.3.2 | Realign domain trait boundaries | Verified | 2026-10-17 |
| 1.3.3 | Retain unified firewall pipeline table (evaluated & consolidated) | Verified | 2026-10-17 |
| 2.1 | Standardized form submission loading feedback | Verified | 2026-10-17 |
| 2.2 | Page-title typography normalization | Verified | 2026-10-17 |
| 2.3 | Localize hardcoded table headers | Verified | 2026-10-18 |
| 2.4 | Normalize arbitrary font sizes | Verified | 2026-10-18 |
| 2.5 | Standardize toggle switch sizing & loading locks | Verified | 2026-10-18 |
| 3.1 | Migrate runtime status views from `wire:poll` to Echo push | Verified | 2026-10-18 |
| 3.2 | ESL event ingestion overflow & queue buffering | Verified | 2026-10-18 |
| 4.1 | Shared kernel boundary architecture test & listener migration | Verified | 2026-10-18 |
| 4.2 | Enforce contract seams over cross-module model imports | Verified | 2026-10-18 |
| 4.3 | Consolidate module route registration | Verified | 2026-10-18 |
| 4.4 | Clarify disabled module lifecycle semantics | Pending | 2026-10-16 |
| 4.5 | Clean up `TenantContext::current()` side effects | Pending | 2026-10-16 |
| 4.6 | Decompose large shared-kernel classes | Pending | 2026-10-16 |
| 4.7 | Environment variable documentation reference | Pending | 2026-10-16 |
| 5.1 | Click-to-call ESL command validation | Verified | 2026-10-17 |
| 5.2 | Open registration feature gate | Verified | 2026-10-17 |
| 5.3 | XML handler rate limiting & access hardening | Verified | 2026-10-17 |
| 5.4 | Threat feed redirect pinning | Verified | 2026-10-17 |
| 5.5 | Reverse lockout protection for armed whitelist geo-perimeter | Verified | 2026-10-17 |
| 5.6 | Module management and custom extension lifecycle authorization | Verified | 2026-10-17 |
| 5.7 | Atomic inter-process lock protocol for shared pending firewall sets | Verified | 2026-10-17 |

---

## Stream 1: Security Module Sub-domain Refactoring & Performance

*Source: superseded analyses `modular-design-analysis.md` (Parts 1 & 2; retired in commit `608f3d5b`) and `plan-security-module-performance-refactor.md` (retired in commit `2dfe1bdb`). Their surviving conclusions are preserved as the invariants and tasks below.*

### Architectural Invariants & Scope Rules
- **Keep Single Module Boundary**: Retain `app-modules/security/` as a single unified module. External coupling is currently zero (no external PHP code references `Modules\Security`). Splitting into multiple `app-modules/` would fragment the unified Command Center UI or introduce inter-module event latency, break single-artifact firewall generation, and increase lockout risk across shared kernel sets (`external_blocklist.nft.pending`).
- **Retain Single Livewire Component Lifecycle**: Reject nested child Livewire components (`<livewire:security.tabs.* />`). Master switches (Firewall On/Off, Observe Mode, Pre-filters Bypassed) require instant, synchronous, atomic UI reflection without event-bus (`$this->dispatch`) latency or prop sync lag.
- **Future Split Trigger Criteria**: A physical split into separate modules (`security-core` vs `threat-feeds` / `geo`) should ONLY occur if:
  1. A genuine requirement emerges for independently toggleable IP-intelligence sources distributed to third-party installations.
  2. Contributor/third-party demand requires external ruleset stage contributors.
  3. Source code trends past ~15k LOC despite internal sub-domain decomposition.
  If triggered, the only defensible seam is *ruleset engine* (firewall core + bans + lockout guard + detection) vs. *IP intelligence sources* (threat feeds + geo) communicating via a versioned `RulesetElementContributor` contract with explicit stage ordering.

---

### Phase 1.1: Unify Apply Pipeline & Cockpit Query Laziness

#### Task 1.1.1: Extract `FirewallApplier` Domain Service
- **Problem**: The safety-critical 5-step apply pipeline (assert admin safe → assert local services safe → write pending configuration → validate syntax via `nft -c` → invoke bounded sudoers helper `/usr/local/sbin/tallpbx-security` → record audit log → broadcast `FirewallRulesetUpdated`) is currently duplicated across `SecurityManager::autoApplyFirewallRuleset()`, `SecurityApplyCommand`, and `FirewallBanReconciler`.
- **Implementation**:
  - Create contract `Modules\Security\Contracts\FirewallApplierInterface`.
  - Implement `Modules\Security\Services\Firewall\FirewallApplier` returning a typed `FirewallApplyResult` value object.
  - Refactor `SecurityManager`, `SecurityApplyCommand`, and `FirewallBanReconciler` to depend solely on `FirewallApplierInterface`.
- **Verification**: Unit tests with mocked executors asserting all 5 steps, lockout rollback, and error propagation.

#### Task 1.1.2: Lazy `#[Computed]` Properties for Cockpit Tabs
- **Problem**: `SecurityManager::render()` queries records for all cockpit tabs simultaneously on every request (firewall rules, services, whitelist/blacklist, bans, scanner logs, threat feeds), causing unnecessary database queries when viewing an unrelated tab. In addition, `tabs/geo-blocking.blade.php` queries `SecurityGeoRule::instance()` directly within an inline `@php` block inside the view rather than receiving a prepared view-model from the component.
- **Implementation**:
  - Convert tab record queries to Livewire 4 `#[Computed]` properties:
    - `#[Computed] public function rules(): Collection`
    - `#[Computed] public function serviceRules(): Collection`
    - `#[Computed] public function whitelist(): Collection`
    - `#[Computed] public function blacklist(): Collection`
    - `#[Computed] public function bans(): Collection`
    - `#[Computed] public function scannerIncidents(): Collection`
    - `#[Computed] public function threatFeeds(): Collection`
    - `#[Computed] public function geoRule(): SecurityGeoRule`
  - Refactor `tabs/geo-blocking.blade.php` to access `$this->geoRule` directly instead of querying `SecurityGeoRule::instance()` inside the Blade template.
  - Ensure tab views reference these computed properties directly so inactive tabs execute zero DB queries during live updates.
- **Verification**: Query log assertions verifying requests on one tab execute 0 queries for other tabs, and assert no direct Eloquent queries execute inside Blade tab views.
 
#### Task 1.1.3: Geo-Blocking Cache Freshness & Whitelist Fail-Closed Integrity
- **Problem**: `GeoBlockingService::cacheNeedsDownload()` checks only whether zone cache files exist and have non-zero file sizes (`filesize === 0`). Once downloaded, country IP zones are never refreshed by the weekly scheduled `security:sync-geoip` run unless `--force` is passed manually, contradicting `CHANGELOG.md` and `docs/security-architecture.md` ("refresh automatically every week"). Additionally, the `sync_interval` setting (`weekly|monthly|on_demand`) is stored but never consulted by `SecuritySyncGeoIpCommand`. Furthermore, in `GeoBlockingService::sync()`, if one address family download fails while the other succeeds, writing `"\n"` into the failed family's cache permanently corrupts it with a 1-byte file that forever bypasses the zero-byte check; in whitelist mode, this can compile a partial allow-set marked as "success", silently dropping legitimate traffic.
- **Implementation**:
  - Implement age-aware TTL freshness checking in `cacheNeedsDownload()` based on file modification time (`mtime`) and the configured `sync_interval` (or default 7-day TTL).
  - Wire `sync_interval` into `SecuritySyncGeoIpCommand` and scheduler logic.
  - Fix `sync()` to never write cache files for an address family unless valid, non-empty CIDR entries were returned.
  - Treat zero-entry or whitespace-only cache files as invalid/stale.
  - Enforce strict fail-closed whitelist integrity: any country in whitelist mode that cannot be fully downloaded or populated from a valid cache must trigger `$whitelistIncomplete`, abort compilation, and report failure.
- **Verification**: Unit and feature tests verifying automatic download when cache age exceeds TTL, refusal to overwrite caches with empty/corrupt data, and proper fail-closed refusal when a whitelist country has an incomplete zone.

---

### Phase 1.2: Ruleset Compiler Decomposition

#### Task 1.2.1: Decompose `SecurityConfigGenerator` God-Class
- **Problem**: `SecurityConfigGenerator` (1,044 LOC) combines all firewall stage compilation, service catalog generation, geo perimeters, pre-filters, and checksum generation into a single monolithic file.
- **Implementation**:
  - Decompose into dedicated, stage-specific compiler classes (target: no class > 400 LOC):
    - `PreFilterCompiler`: Generates stages 1–7 (conntrack, loopback, tenant CIDRs, admin subnets, TFTP defense, custom pre-filters).
    - `GeoPerimeterCompiler`: Generates stage 9.5 geo perimeter drop rules and subchain definitions.
    - `ServiceRulesCompiler`: Generates stages 10–12 (PBX core SIP/RTP ports, system services, sequential rules).
    - `BlocklistCompiler`: Generates drop set references (`external_blocklist.nft`, threat feeds, active bans).
  - Retain `SecurityConfigGenerator` as a lean coordinator (~200 LOC) assembling stage outputs using the proven `preFilterOrder()` machinery.
- **Verification**: Assert exact bit-for-bit ruleset output parity against existing comprehensive tests (`SecurityConfigGeneratorTest`, `SecurityConfigGeneratorGeoTest`, `SecurityConfigGeneratorPreFilterOrderTest`, `SecurityConfigGeneratorTwoSwitchesTest`).

---

### Phase 1.3: Sub-domain Namespace Realignment & View Partials

#### Task 1.3.1: Reorganize `src/Services/` into Sub-domain Namespaces [COMPLETED]
- **Implementation**:
  - Reorganized flat `app-modules/security/src/Services/` into cohesive sub-domain folders matching TallPBX conventions:
    - `src/Services/Firewall/`: `FirewallApplier`, `FirewallBanReconciler`, `FirewallSyncVerifier`, `LockoutGuardService`, `SecurityConfigGenerator`, `SecurityExecutor`, `Compilers/` (`BlocklistCompiler`, `GeoPerimeterCompiler`, `PreFilterCompiler`, `ServiceRulesCompiler`).
    - `src/Services/Geo/`: `GeoBlockingService`, `IpdenyProvider`.
    - `src/Services/ThreatFeeds/`: `ThreatFeedManager`, `ThreatFeedIngestionService`, `VoipblFeedProvider`.
    - `src/Services/Bans/`: `SecurityBanService`, `SecurityIncidentService`.
    - `src/Services/Detection/`: `SipScannerDialplanContributor`.
  - Maintained `Contracts/` and `SecurityServiceProvider` as the module's clean public surface, and preserved `Models/`, `Listeners/`, `Console/Commands/`, and `Support/` per standard Laravel modular layering. All 421 tests pass with 0 regressions.

#### Task 1.3.2: Realign Domain Trait Boundaries [COMPLETED]
- **Implementation**:
  - Moved misplaced methods in `SecurityManager` concern traits to their visual domain match:
    - Moved `$tftpDefenseEnabled`, `setTftpDefense()`, and `tftpDefenseCounters()` from `ManagesAttackProtection` into `ManagesFirewallRules` (where the TFTP workbench is visually rendered). Verified with targeted `SecurityManagerTftpTest` and full 421-test suite.

#### Task 1.3.3: Firewall Rules Unified Table Architecture [COMPLETED - RETAINED UNIFIED]
- **Evaluation & Decision**:
  - Re-evaluated 2026-10-17 based on the principle of separating by logical boundaries rather than file size.
  - `resources/views/tabs/firewall-rules.blade.php` (732 LOC) is a single, cohesive HTML `<table>` with 7 shared columns governed by a unified Alpine.js horizontal scroll controller, visually modeling the sequential Linux nftables evaluation pipeline from top to bottom (Pre-filters → PBX Core Services → Custom Rules → Default Policy).
  - Slicing `firewall-rules.blade.php` into 4 separate files would produce headless sets of `<tr>` tags that cannot stand alone or be reused, damaging readability and maintainability without creating domain abstractions.
  - **Decision**: Retained unified as a single cohesive template. Stream 1 is now 100% complete.

---

## Stream 2: UI/UX Consistency, Loading Feedback & Typography

*Source: retired `ui-audit-report.md` (untracked working audit, consolidated here). Item IDs (M2, L1, ...) are retained for traceability; all quantitative figures were re-measured against the working tree on 2026-10-16.*

### Task 2.1: Standardized Form Submission Loading Feedback (`<x-form-submit-button>`) [COMPLETED] (UI Audit M3 & L3)
- **Problem**: Of the 47 module edit/create views (`acl-edit`, `dialplans-edit`, `bridges-edit`, `sip-profiles-edit`, `extensions-edit`, `users-edit`, `tenants-edit`, etc.), 42 contain no `wire:loading` directive at all *(re-measured 2026-10-16)*, so their submits have no busy state, spinner, or disabled attribute. When saves trigger FreeSWITCH reload jobs or cache invalidation, the screen freezes with zero feedback. In addition, `sip-profiles-edit` and `extensions-edit` have button sizing drift (`btn` instead of standard `btn-sm`).
- **Implementation**:
  - Created `<x-form-submit-button>` Blade component in `resources/views/components/form-submit-button.blade.php` with built-in:
    - `wire:loading.attr="disabled"`
    - Scoped `wire:target` (defaulting to `'save'`, customizable via prop, omissible via `:target="false"`)
    - DaisyUI loading spinner (`<span class="loading loading-spinner loading-xs" wire:loading></span>`)
    - Standard `btn btn-primary btn-sm` sizing with class merging support
    - Dynamic slot text supporting present-continuous busy labels (defaulting to `__('admin.saving')`, localized across en/es/fr).
  - Batch-migrated all 47 edit/create views (plus `fax-send` and `dialplan-tester`) to `<x-form-submit-button>` and normalized cancel links to `btn btn-ghost btn-sm` with `wire:navigate`.
  - Added unit test `FormSubmitButtonTest` and architecture test `FormSubmitButtonArchitectureTest` verifying 100% compliance across all edit views.
- **Verification**: Verified via `FormSubmitButtonTest`, `FormSubmitButtonArchitectureTest`, Playwright browser tests (`PanelRoutingSmokeTest`, `PanelCoreSmokeTest`), and full parallel test suite (2,923 tests, 19,724 assertions).

### Task 2.2: Page-Title Font Weight & Typography Normalization (UI Audit M2) [COMPLETED]
- **Problem**: 83 views used `text-2xl font-semibold` while 43 used a `text-2xl font-bold` variant; only 7 views contained `tracking-tight` at all, so the AGENTS.md page-title standard (`text-2xl font-bold tracking-tight text-base-content`) was the minority pattern.
- **Implementation**:
  - Normalized all 125 module and panel page-title headings (`<h1>` / `<h2>` with `text-2xl`) across `app-modules/` and `resources/views/` to match the established design standard: `text-2xl font-bold tracking-tight text-base-content`, preserving layout spacing utilities (`mb-6`, `mb-4`, etc.).
  - Added Pest UI architecture test `PageTitleTypographyArchitectureTest` enforcing that all page-title headings across all views strictly adhere to the standard.
- **Verification**: Verified via `PageTitleTypographyArchitectureTest` (scanned 127 matching page titles across 127 views), Pest Browser suite (53 passed, 273 assertions), and Pint code style linting.

### Task 2.3: Localize Hardcoded Table Header (`<th>`) Contents (UI Audit L1) [COMPLETED]
- **Problem**: 39 table header cells contained raw English strings (`Name`, `Actions`, `Type`, `Status`, `Provider`, `Destination`, `Queue`, `Profile`, `Value`, `Key`, `Strategy`, `Timestamp`, `Rule Stage`, `Proto`, `Message`, plus `Source IP`, `SAN Domains`, `Name / Domain`, `Interface`, and `File`), rendering untranslated in Spanish and French.
- **Implementation**:
  - Replaced all 39 unlocalized `<th>` cells across 7 views (`file-stores-list`, `observe-activity`, `certificate-manager`, `settings-edit`, `call-center-active-list`, `gateways-list`, and `active-conferences-list`) with root `client` translation keys (`__('client.name')`, `__('client.actions')`, `__('client.status')`, `__('client.file')`, `__('client.destination')`, `__('client.attempts')`, `__('client.provider')`, `__('client.connection')`, `__('client.timestamp')`, `__('client.interface')`, `__('client.proto')`, `__('client.action')`, `__('client.administrator')`, `__('client.message')`, `__('client.queue')`, `__('client.strategy')`, `__('client.agent')`, `__('client.profile')`, `__('client.key')`, `__('client.value')`, `__('client.type')`, `__('client.created_at')`) or domain-specific keys (`__('admin.security_rule_stage')`, `__('admin.security_source_ip')`, `__('admin.certificates_name_domain')`, `__('admin.certificates_san_domains')`, `__('admin.certificates_expires_in')`, `__('admin.certificates_assigned_services')`).
  - Added full translation parity for all new table header keys across English, Spanish, and French in `lang/{en,es,fr}/client.php`, `app-modules/security/lang/{en,es,fr}/admin.php`, and `app-modules/certificates/lang/{en,es,fr}/admin.php`.
  - Extended `TranslationParityTest` to enforce 100% key parity across `lang/{locale}/client.php` in addition to `admin.php`.
  - Created architecture test `TableHeadersLocalizationArchitectureTest` scanning all Blade views across `app-modules/` and `resources/views/` to assert zero unlocalized `<th>` cells.
- **Verification**: Verified via `TableHeadersLocalizationArchitectureTest` (200+ headers scanned, 0 violations), `TranslationParityTest` (6 tests, 698 assertions), targeted module tests (file-stores, certificates, gateways, call-center-active, active-conferences: 168 tests, 614 assertions), and Pint code style linting.

### Task 2.4: Normalize Arbitrary Font Sizes (UI Audit L2) [COMPLETED]
- **Problem**: 30 occurrences of arbitrary one-off font sizes (`text-[10px]` and `text-[11px]`) concentrated in the security module and display-settings drawer violate the design token convention *(re-measured 2026-10-16; the original audit catalogued 21)*.
- **Implementation**:
  - Normalized all 30 occurrences across 10 Blade templates to standard `text-xs` paired with semantic opacity utilities (`text-base-content/60`, `text-base-content/50`, `opacity-75`) and standard DaisyUI badge sizing (`badge-sm`, `badge-xs`), eliminating one-off font sizes.
  - Added architecture test `ArbitraryFontSizesArchitectureTest` asserting zero arbitrary font size utility classes (`text-[...px]` or `text-[...rem]`) exist in any Blade template across `app-modules/` and `resources/views/`.
- **Verification**: Verified via `ArbitraryFontSizesArchitectureTest` (>100 Blade templates scanned, 0 violations), full architecture test suite (10 tests, 709 assertions), and Pint code style linting.

### Task 2.5: Standardize Toggle Switch Sizing & Loading Locks (UI Audit L4) [COMPLETED]
- **Problem**: 12 views render DaisyUI toggle switches; 4 edit views (`tenants-edit`, `admins-edit`, `tenant-domains-edit`, `backups-edit`) still use `toggle toggle-primary` without the standard `toggle-sm` sizing, and 2 of those (`tenants-edit`, `tenant-domains-edit`) along with `log-viewer.blade.php` carry no `wire:loading.attr` lock at all *(re-measured 2026-10-17; the original audit catalogued 7 of 15 usages)*.
- **Implementation**:
  - Standardized all 19 toggle switch inputs across 12 views to explicit DaisyUI sizing (`toggle-sm` or compact table `toggle-xs`), adding `wire:loading.attr="disabled"` and pointer-events locks (`wire:loading.class="opacity-70 pointer-events-none"`) to prevent state desync and double-click race conditions.
  - Added architecture test `ToggleSwitchArchitectureTest` asserting 100% of DaisyUI toggle switches across `app-modules/` and `resources/views/` specify standard size tokens and enforce `wire:loading.attr="disabled"`.
- **Verification**: Verified via `ToggleSwitchArchitectureTest` (19 toggles scanned, 0 violations), full architecture test suite (11 tests, 713 assertions), targeted Livewire feature tests (66 tests, 274 assertions), and Pint code style linting. Stream 2 is now 100% complete.

---

## Stream 3: Real-Time Telephony Push Architecture (Echo/Reverb)

*Source: retired `ui-audit-report.md` (M1) and `architecture-audit-report.md` (M4), both consolidated here.*

### Task 3.1: Migrate Runtime Telephony Status Views from `wire:poll` to Echo Push (UI Audit M1) [COMPLETED]
- **Problem**: Six runtime telephony status views aggressively poll the backend via `wire:poll` (2s–15s), violating the project mandate to use Reverb/Echo push for real-time tables:
  - `active-calls-list` (2s)
  - `active-conferences-list` (5s)
  - `registrations-list` (10s)
  - `sip-status-list` (15s)
  - `call-center-active-list` (5s)
  - `operator-panel-index` (5s)
  *(Note: three non-telephony views poll legitimately and are preserved — `git-update` (1s while an update runs), `restore-archive` (5s during restores), and the log viewer (5s live tail). Poll signatures re-checked 2026-10-16.)*
- **Implementation**:
  - Created `TelephonyUpdated` broadcasting event implementing `ShouldBroadcastNow` and private channel `tenant.{tenantId}.telephony` (plus `tenant.global.telephony`).
  - Created `BroadcastTelephonyOnFreeSwitchEvent` listener and wired 14 FreeSWITCH ESL events to dispatch `TelephonyUpdated`.
  - Configured all 6 Livewire components to listen on tenant private channels with `#[On('echo-private:tenant.{tenantId}.telephony,.TelephonyUpdated')]` and dual-binding `#[On('echo:dashboard.monitoring,.DashboardStatsUpdated')]`.
  - Removed all `wire:poll` directives from the 6 views.
  - Added feature test suite `TelephonyPushBroadcastingTest` asserting `assertDontSee('wire:poll')` on all 6 views, verifying Echo push listeners, broadcast payloads, and strict tenant channel isolation boundaries.

### Task 3.2: ESL Event Ingestion Overflow & Queue Buffering (Arch Audit M4) [COMPLETED]
- **Problem**: `FreeSwitchListenCommand` dispatches every ESL event inline in a single-threaded loop; heavy event storms could create backpressure on the FreeSWITCH socket.
- **Implementation**:
  - Implemented bounded in-memory buffer queue (`SplQueue`) in `FreeSwitchListenCommand` with configurable capacity (`--buffer-size` and `config('freeswitch.esl.buffer_size', 1000)`), dropping stale events on overflow with warning logs and diagnostics.
  - Added non-blocking socket draining via `FreeSwitchServiceInterface::hasPendingData()` to drain kernel socket buffers immediately.
  - Converted heavyweight database-writing and ban-evaluating event listeners (`LogFailedSipAuthListener`, `LogSipScannerListener`, `RegisterCompletedCallRecording`, `MarkBroadcastRecipientOutcome`, `RegisterCompletedVoicemailMessage`) to implement `ShouldQueue`, offloading heavy file I/O and SQL queries onto asynchronous background queue workers.
  - Retained synchronous inline dispatch for lightweight real-time state telemetry (`BroadcastDashboardStatsOnFreeSwitchEvent`, `BroadcastTelephonyOnFreeSwitchEvent`).
  - Added unit and feature tests covering socket draining, bounded buffer capacity, overflow drop behavior, and listener queue contracts.

---

## Stream 4: Shared Kernel Boundary & Modular Discipline

*Source: retired `architecture-audit-report.md` (M1–M3, L3–L7) and `modular-design-analysis.md` (Part 2; retired in commit `608f3d5b`), both consolidated here.*

### Task 4.1: Shared Kernel Reverse Dependency Architecture Test & Listener Migration [COMPLETED]
- **Problem**: `app/` contained domain leakage where shared kernel classes reached into module models directly (`app/Listeners/FreeSwitch/*`, `app/Services/TenantDefaults/*`, `app/Services/DestinationResolver`, `PbxLoadTestSeedCommand`).
- **Implementation**:
  - Added Pest architecture test `tests/Feature/Architecture/SharedKernelBoundaryTest.php` forbidding `use Modules\` inside `app/` outside an explicit, documented allowlist with justifications (AppServiceProvider composition root, TenantDefaults bootstrap provisioners, load testing seeder/commands, privileged restore helper CLI, and core FreeSWITCH XML/routing engine).
  - Migrated 3 domain event listeners out of `app/Listeners/FreeSwitch/*` into their respective modules:
    - `RegisterCompletedCallRecording` moved to `Modules\CallRecordings\Listeners\RegisterCompletedCallRecording` (registered in `CallRecordings` service provider for `RecordStop`).
    - `RegisterCompletedVoicemailMessage` moved to `Modules\VoicemailMessages\Listeners\RegisterCompletedVoicemailMessage` (registered in `VoicemailMessages` service provider for `ChannelExecuteComplete`).
    - `MarkBroadcastRecipientOutcome` moved to `Modules\CallBroadcast\Listeners\MarkBroadcastRecipientOutcome` (registered in `CallBroadcast` service provider for `ChannelHangupComplete`).
  - Retired `app/Listeners/FreeSwitch/`, ensuring `app/Listeners/` retains strictly shared real-time telemetry broadcasters.
  - Added unit test `MarkBroadcastRecipientOutcomeTest` in `app-modules/call-broadcast/tests/` asserting that `ChannelHangupComplete` events correctly resolve recipient outcomes, call durations, hangup causes, and broadcast completion settlement.
- **Verification**: Verified via `SharedKernelBoundaryTest` (3 tests, 40 assertions), all architecture tests (14 tests, 753 assertions), targeted FreeSwitch and listener module tests (`tests/Feature/FreeSwitch/`, `tests/Feature/Services/FreeSwitchListenCommandTest.php`, `app-modules/call-broadcast/tests/`, `app-modules/call-recordings/tests/`, `app-modules/voicemail-messages/tests/`: 64 tests, 212 assertions), and Pint code style linting.

### Task 4.2: Enforce Contract Seams Over Direct Cross-Module Model Imports [COMPLETED]
- **Problem**: `MediaAsset` was imported in 20 src files and `Extension` in 18 src files across different modules, coupling schemas across contexts even when service interfaces (`MediaStorageServiceInterface`) exist.
- **Implementation**:
  - Added Pest architecture test `ContractSeamsArchitectureTest` establishing directional architecture rules:
    - Forbids direct instantiation or persistence calls (`new FileStore`, `new MediaAsset`, `FileStore::create`, `MediaAsset::create`, `firstOrCreate`) outside `Modules\FileStores`.
    - Forbids direct instantiation or persistence calls (`new Extension`, `Extension::create`, `firstOrCreate`) outside `Modules\Extensions`.
    - Enforces that all domain services managing media files (`CallRecordingService`, `VoicemailMessageService`, `RecordingService`, `MusicOnHoldService`, `FaxService`) inject and route through `MediaStorageServiceInterface`.
  - Added decoupled contract query methods: `find()` and `getOrCreateLocal()` on `FileStoreServiceInterface` and `FileStoreService`, and `find()` on `ExtensionServiceInterface` and `ExtensionService`.
  - Refactored `BackupService` to obtain default local backup store profiles through `FileStoreServiceInterface::getOrCreateLocal()`.
  - Refactored `CallForwardService` and `ProvisionService` to query extensions through `ExtensionServiceInterface::find()`.
  - Removed unused `Extension` model import from `HotDeskingService`.
- **Verification**: Verified via `ContractSeamsArchitectureTest` (3 tests, 14 assertions), all architecture tests (17 tests, 767 assertions), and full targeted module test suites across `file-stores`, `backups`, `extensions`, `call-forwards`, `provision`, and `hot-desking` (188 tests, 669 assertions).

### Task 4.3: Consolidate Module Route Registration (Arch Audit M2) [COMPLETED]
- **Problem**: 22 of the 60 modules use explicit `routes/web.php` while the remaining modules use convention-based auto-registration in `App\Support\ModuleServiceProvider::autoRegisterRouteGroups()` *(re-measured 2026-10-16; the original audit catalogued 21)*. Maintaining two parallel mechanisms doubles the cognitive overhead.
- **Implementation**:
  - Standardized all 60 modules on explicit `routes/web.php` route declarations, eliminating runtime reflection-based route generation and achieving 100% route grepability and auditability.
  - Retired `autoRegisterRouteGroups()`, `routeGroups()`, and `editRouteParameterName()` from `App\Support\ModuleServiceProvider` (~170 lines of magic reflection code removed).
  - Streamlined `registerRoutes()` in `ModuleServiceProvider` to strictly load `routes/web.php` while maintaining safety audits and collision guards for custom modules.
  - Added Pest architecture test `tests/Feature/Architecture/ModuleRoutesArchitectureTest.php` enforcing that 100% of modules under `app-modules/` declare an explicit `routes/web.php` file, ensuring all registered module routes have explicit route names (`panel.*` or `api.*`), and asserting that legacy reflection-based dynamic auto-routing methods remain retired.
  - Verified 100% bit-for-bit route parity across all 198 application routes (`routes_before.json` vs `routes_after.json` diff was 0 differences).
- **Verification**: Verified via `ModuleRoutesArchitectureTest` (3 tests, 5 assertions), `ModuleServiceProviderTest` (21 tests, 243 assertions), all architecture tests (20 tests, 772 assertions), and Pint code style linting.

### Task 4.4: Clarify Disabled Module Lifecycle Semantics (Arch Audit M3)
- **Problem**: `ModuleServiceProvider::boot()` registers views, translations, migrations, and config before the `ModuleState::isEnabled()` gate. `php artisan migrate` creates schema for disabled modules.
- **Implementation**:
  - Move migration and route registration behind the `isEnabled()` check, providing an explicit `--all-modules` flag for migrations if schema retention is desired.
  - Align documentation in `docs/modular-architecture.md` with the exact execution order.

### Task 4.5: Clean up `TenantContext::current()` Read-vs-Mutation Side Effects (Arch Audit L3)
- **Problem**: `TenantContext::current()` mutates global `TenantManager` state as a side effect of a read operation.
- **Implementation**:
  - Split into a pure `resolve(): ?Tenant` method and an explicit `apply(?Tenant $tenant): void` method.
  - Review and document the single-tenant fallback to ensure explicit tenant boundary checks.

### Task 4.6: Decompose Large Shared-Kernel Classes (Arch Audit M1 & L7)
- **Scope & Architectural Evaluation**:
  - Re-evaluated 2026-10-17 based on the principle of separating by domain/logical boundaries rather than file size.
  - `GitUpdateService` (716 LOC) and `ModuleLifecycleService` (696 LOC) are cohesive single-domain orchestrators; decomposing their internal status storage or lifecycle steps into artificial micro-classes adds indirection without reuse value, so they remain unified.
  - `FreeSwitchService` (714 LOC): Retains the genuine protocol/transport boundary — separating raw TCP socket framing/reconnects (`EslConnection`) from the high-level telephony command API (`FreeSwitchApi`) when socket resiliency requires it.

### Task 4.7: Environment Variable Documentation Reference (Arch Audit L5)
- **Implementation**:
  - Document the 230 unique `env()` configuration keys *(re-measured 2026-10-16)* in a categorized reference guide (`docs/env-reference.md`) grouping FreeSWITCH, security, media storage, Reverb, and database settings.

---

## Stream 5: Application Security Hardening

*Source: retired `project-audit-report.md` (untracked working audit, consolidated here).*

### Task 5.1: Click-to-Call ESL Command Validation & Socket Sanitization (Security Audit H1)
- **Problem**: In `ClickToCallForm.php` (`initiateCall()`), `phoneNumber` and `extension` are interpolated into an ESL `originate` string sent via `bgapi()`. A value containing newline (`\n`) characters allows protocol command injection directly into FreeSWITCH's privileged ESL socket.
- **Implementation**:
  - Add strict input regex validation in `ClickToCallForm`: `phoneNumber` (`regex:/^\+?[0-9*#]{3,20}$/`) and validate `extension` against the tenant's actual extension numbers.
  - Defense in depth: in `FreeSwitchService::api()` and `bgapi()`, reject and log any command string containing `\r` or `\n` before transmitting to the ESL socket.
  - Route click-to-call origination through `FreeSwitchControlService::originate()` which already enforces token sanitization.

### Task 5.2: Open Registration Feature Gate (Security Audit M3)
- **Problem**: `Register` Livewire component and `panel.register` route are accessible to anyone with no `registration.enabled` configuration switch, allowing arbitrary tenant user registration and potential spam.
- **Implementation**:
  - Add a configurable `auth.registration_enabled` setting (default `false`), gating the registration route and displaying a clean disabled state or redirecting to login.

### Task 5.3: XML Handler Rate Limiting & Access Hardening (Security Audit L1 & L2)
- **Problem**: `/api/v1/xml-handler` has no rate-limiting middleware, allowing unrestricted lookup attempts.
- **Implementation**:
  - Attach a dedicated high-throughput rate limiter (e.g. `throttle:600,1`) to `/api/v1/xml-handler` to protect against token brute force without impeding FreeSWITCH operations.
  - Add failed-token security alerts.

### Task 5.4: Threat Feed Redirect Pinning (Security Audit L3)
- **Problem**: `ThreatFeedIngestionService` follows HTTP redirects without host pinning when downloading external threat feeds.
- **Implementation**:
  - Configure the Guzzle/HTTP client to enforce `allow_redirects` with same-host pinning or disable redirects.

### Task 5.5: Reverse Lockout Protection for Armed Whitelist Geo-Perimeter (4.x Audit H3)
- **Problem**: In `ManagesGeoBlocking.php`, arming a whitelist geo perimeter refuses the action if defensive pre-filters are disabled (because pre-filter stage 1 contains the administrator's trusted whitelist accept). However, the reverse path in `ManagesPreFilters.php` (`setPrefilterEnabled(false)`) consults only `LockoutGuardService`, which has no awareness of geo perimeter state. If an administrator disables pre-filters while whitelist geo-blocking is armed, stage 1's trusted accept rule is removed while stage 9.5's geo drop rule fires unconditionally, creating a severe **administrator lockout path**.
- **Implementation**:
  - In `ManagesPreFilters::setPrefilterEnabled(false)`, check if `SecurityGeoRule::instance()` is enabled with `mode === 'whitelist'`.
  - Refuse disabling pre-filters while whitelist geo-blocking is armed, returning a descriptive error notification (`admin.security_geo_prefilter_required` or a specialized lockout notice).
- **Verification**: Unit and Livewire tests verifying that `setPrefilterEnabled(false)` is blocked when whitelist geo-blocking is enabled, and allowed when disabled or in blacklist mode.

### Task 5.6: Module Management and Custom Extension Lifecycle Authorization (4.x Audit H4)
- **Problem**: The module workbench route `/panel/modules` (`ModulesList`) is gated solely by the view-only permission `admin.can:admin.modules.view`. However, `ModulesList` provides destructive lifecycle actions (`deleteCustomModule()`, which runs `ModuleLifecycleService::uninstall()` to permanently purge all extension files and database state, and `toggleEnabled()`). Neither action enforces an in-component permission re-check (`ensureManagePermission()` pattern), allowing any administrator with read-only module access to permanently delete custom extensions or toggle core module states.
- **Implementation**:
  - Register `admin.modules.manage` permission in root language dictionaries and seed via `AdminSeeder`.
  - Add `ensureManagePermission()` check inside `ModulesList::deleteCustomModule()` and `toggleEnabled()`.
  - Hide destructive deletion and toggle UI controls from administrators lacking `admin.modules.manage`.
- **Verification**: Feature and Livewire authorization tests verifying that users with only `admin.modules.view` cannot delete custom modules or toggle module enablement, and that unauthorized attempts throw `AuthorizationException` / 403.

### Task 5.7: Atomic Inter-Process Lock Protocol for Shared Pending Firewall Sets (4.x Audit M5)
- **Problem**: In `ThreatFeedIngestionService::compileSetElementFile()`, the service reads `geo_blocking.nft.pending` into memory *before* taking the exclusive file lock (`LOCK_EX`) on `external_blocklist.nft.pending`. If a concurrent geo-blocking toggle executes between the read and lock acquisition, its pending elements can be silently overwritten and reverted by the threat-feed sync.
- **Implementation**:
  - Standardize file locking across `ThreatFeedIngestionService` and `GeoBlockingService`: acquire `LOCK_EX` on the shared target before snapshotting and compiling merged pending sets.
  - Remove dead fallback branches for obsolete separate `.nft` files.
- **Verification**: Concurrency-safe test verifying that pending geo elements are preserved when threat feeds compile under simulated interleaved writes.

---

## Completed & Verified Milestone Log

All items below have been implemented, verified through automated Pest test suites, and committed to branch `4.x`. Rows are appended in the same commit that closes a task (see the close-out rule in the Task Status Index):

| **2026-10-18** | `d81e58f6` | **Architecture / Modular** | **Consolidate Module Route Registration**: Standardized all 60 modules on explicit `routes/web.php` route declarations, eliminating runtime reflection-based auto-registration in `ModuleServiceProvider::autoRegisterRouteGroups()`. Retired ~170 lines of reflection-heavy magic routing code, retained custom-module collision guards, added `ModuleRoutesArchitectureTest` enforcing explicit route files and named routes across all modules, and verified bit-for-bit route parity across all 198 application routes. *(Resolves Task 4.3 / Arch Audit M2)* |
| **2026-10-18** | `513afcae` | **Architecture / Modular** | **Enforce Contract Seams Over Cross-Module Model Imports**: Established directional architecture test `ContractSeamsArchitectureTest` forbidding direct instantiation or persistence of `FileStore` or `MediaAsset` outside `Modules\FileStores`, and `Extension` outside `Modules\Extensions`. Injected and wired `ExtensionServiceInterface` across `call-forwards` (`CallForwardService`) and `provision` (`ProvisionService`), expanded `FileStoreServiceInterface` and `ExtensionServiceInterface` with contract query APIs (`find()`, `getOrCreateLocal()`), and refactored `BackupService` to route through contract seams. *(Resolves Task 4.2 / Arch Audit M1)* |
| **2026-10-18** | `e4e06923` | **Architecture / Modular** | **Shared Kernel Boundary Architecture Test & Listener Modularization**: Added Pest architecture test `SharedKernelBoundaryTest` enforcing that `app/` does not import `Modules\*` outside an explicit, documented allowlist with verified justifications. Migrated 3 domain listeners out of `app/Listeners/FreeSwitch/` (`RegisterCompletedCallRecording`, `RegisterCompletedVoicemailMessage`, `MarkBroadcastRecipientOutcome`) into their respective module boundaries (`CallRecordings`, `VoicemailMessages`, `CallBroadcast`), retired `app/Listeners/FreeSwitch/`, and added unit test `MarkBroadcastRecipientOutcomeTest`. *(Resolves Task 4.1 / Arch Audit M5)* |
| **2026-10-18** | `9af130b1` | **FreeSWITCH / Queue** | **ESL Event Ingestion Bounded Buffer & Heavyweight Listener Queue Offloading**: Implemented bounded in-memory buffer (`SplQueue`) with overflow dropping and socket draining via `hasPendingData()` in `FreeSwitchListenCommand`. Offloaded heavyweight listeners (`LogFailedSipAuthListener`, `LogSipScannerListener`, `RegisterCompletedCallRecording`, `MarkBroadcastRecipientOutcome`, `RegisterCompletedVoicemailMessage`) to `ShouldQueue` background workers while keeping real-time telemetry inline, completely eliminating ESL socket backpressure during high-concurrency event storms. *(Resolves Task 3.2 / Arch Audit M4; Stream 3 100% complete)* |
| **2026-10-18** | `20f4913f` | **Telephony / Broadcasting** | **Migrate Runtime Telephony Status Views from `wire:poll` to Echo Push**: Eliminated aggressive HTTP polling (2s–15s) from all 6 runtime telephony status views (`active-calls-list`, `active-conferences-list`, `registrations-list`, `sip-status-list`, `call-center-active-list`, `operator-panel-index`), migrating them to real-time Echo push listeners with dual-event binding (`TelephonyUpdated` and `DashboardStatsUpdated`) and public `$tenantId` property bindings. Created `TelephonyUpdated` event with `ShouldBroadcastNow`, authorized `tenant.{tenantId}.telephony` private channels with admin bypass and strict tenant isolation, wired 14 FreeSWITCH ESL events to `BroadcastTelephonyOnFreeSwitchEvent`, and added feature test suite `TelephonyPushBroadcastingTest`. *(Resolves Task 3.1 / UI Audit M1)* |
| **2026-10-18** | `fc1ca33e` | **UI / Components** | **Standardize Toggle Switch Sizing & Mutation Locks**: Standardized all 19 toggle switch inputs across 12 views (`backups-edit`, `tenants-edit`, `tenant-domains-edit`, `admins-edit`, `log-viewer`, `modules-list`, `geo-blocking`, `external-blocklists`, `attackers`, `block-allow`, `firewall-rules`, `firewall-switches`) to DaisyUI `toggle-sm` (or compact table `toggle-xs`), adding `wire:loading.attr="disabled"` and pointer-events locks to prevent double-submit races and rapid-click desync. Added architecture test `ToggleSwitchArchitectureTest` enforcing explicit sizing and loading locks across all views. *(Resolves Task 2.5 / UI Audit L4; Stream 2 100% complete)* |
| **2026-10-18** | `4bf90759` | **UI / Typography** | **Normalize Arbitrary Font Sizes to Standard Design Tokens**: Normalized all 30 occurrences of arbitrary one-off font sizes (`text-[10px]` and `text-[11px]`) across 10 Blade views in `app-modules/security`, `app-modules/admin`, and `resources/views` to standard Tailwind / DaisyUI `text-xs` design tokens with semantic opacity utilities (`text-base-content/60`, `text-base-content/50`, `opacity-75`). Added architecture test `ArbitraryFontSizesArchitectureTest` enforcing zero arbitrary font-size utility classes in any Blade template. *(Resolves Task 2.4 / UI Audit L2)* |
| **2026-10-18** | `4734ba20` | **UI / Localization** | **Localize Hardcoded Table Header (`<th>`) Contents**: Localized all 39 previously hardcoded English `<th>` cells across 7 views (`file-stores-list`, `observe-activity`, `certificate-manager`, `settings-edit`, `call-center-active-list`, `gateways-list`, and `active-conferences-list`) using root `client` keys and module domain keys with 100% parity across English, Spanish, and French. Extended `TranslationParityTest` to enforce 100% parity across `client.php`, and added architecture test `TableHeadersLocalizationArchitectureTest` ensuring zero unlocalized table headers across all views. *(Resolves Task 2.3 / UI Audit L1)* |
| **2026-10-17** | `e972ff44` | **UI / Typography** | **Page-Title Typography Normalization**: Normalized 125 module and panel page-title headings (`<h1>` / `<h2>` with `text-2xl`) across `app-modules/` and `resources/views/` to strictly match the established design standard in `AGENTS.md` (`text-2xl font-bold tracking-tight text-base-content`), eliminating non-standard `font-semibold` and untightened variants while preserving layout spacing utilities (`mb-6`, `mb-4`, etc.). Added architecture test `PageTitleTypographyArchitectureTest` enforcing 100% compliance across all views. *(Resolves Task 2.2)* |
| **2026-10-17** | `f50c2347` | **UI / Components** | **Standardized Form Submission Loading Feedback (`<x-form-submit-button>`)**: Created `<x-form-submit-button>` Blade component with built-in DaisyUI loading spinner, disabled state lock, standard `btn-sm` sizing, and dynamic present-continuous busy text. Batch-migrated all 47 edit/create views (plus fax send and dialplan tester) to `<x-form-submit-button>`, normalized cancel links to `btn btn-ghost btn-sm` with `wire:navigate`, and added unit and architecture tests. *(Resolves Task 2.1)* |
| **2026-10-17** | `ba7b0a5a` | **Security / Livewire** | **Firewall Rules Unified Table Architecture**: Evaluated `firewall-rules.blade.php` against logical boundary principles; retained the single cohesive HTML table and unified Alpine scroller rather than fragmenting into headless `<tr>` partials. *(Resolves Task 1.3.3; Stream 1 100% complete)* |
| **2026-10-17** | `ba7b0a5a` | **Security / Livewire** | **Realign Domain Trait Boundaries**: Relocated `$tftpDefenseEnabled`, `setTftpDefense()`, and `tftpDefenseCounters()` from `ManagesAttackProtection` into `ManagesFirewallRules`, aligning component concerns with the visual TFTP workbench in the Firewall Rules tab. *(Resolves Task 1.3.2)* |
| **2026-10-17** | `3e0c3356` | **Security / Services** | **Sub-domain Namespace Realignment**: Reorganized flat `app-modules/security/src/Services/` into cohesive sub-domain folders (`Firewall/`, `Geo/`, `ThreatFeeds/`, `Bans/`, `Detection/`) with zero backward-compatibility fallbacks on `4.x`. *(Resolves Task 1.3.1)* |
| **2026-10-17** | `ad7e9697` | **Security / Architecture** | **Decompose SecurityConfigGenerator**: Decomposed monolithic 1,045 LOC compiler god-class into four stage-specific compiler domain classes (`BlocklistCompiler`, `GeoPerimeterCompiler`, `PreFilterCompiler`, `ServiceRulesCompiler`) under `Modules\Security\Services\Firewall\Compilers`, retaining `SecurityConfigGenerator` as a lean coordinator (~400 LOC) with 100% bit-for-bit ruleset parity and backward-compatible delegation APIs. *(Resolves Task 1.2.1)* |
| **2026-10-17** | `ac6ce11d` | **Security / Performance** | **Lazy Computed Properties for Cockpit Tabs**: Converted all cockpit tab records and counts (`rules`, `serviceRules`, `whitelist`, `blacklist`, `bans`, `scannerIncidents`, `threatFeeds`, `threatFeed`, `geoRule`, `bannedCount`, `whitelistCount`, `blacklistCount`, `preFilterRows`) into Livewire 4 `#[Computed]` properties, eliminating up to 14 eager database queries per render in `SecurityManager::render()`. Inactive tabs now execute 0 queries during live updates, and direct `SecurityGeoRule::instance()` calls were purged from Blade views. *(Resolves Task 1.1.2)* |
| **2026-10-17** | `f674ef98` | **Security / Firewall** | **Extract FirewallApplier Domain Service**: Extracted safety-critical 5-step apply pipeline (lockout preflight, pending configuration generation, `nft -c` syntax check, bounded sudoers helper apply, audit logging, pending counter reset, and Reverb broadcasting) into dedicated domain service `FirewallApplier` implementing `FirewallApplierInterface` with typed `FirewallApplyResult` value objects. Refactored `SecurityManager::autoApplyFirewallRuleset()`, `SecurityApplyCommand`, and `FirewallBanReconciler` to eliminate duplicate pipeline logic. *(Resolves Task 1.1.1)* |
| **2026-10-17** | `b2b0c7cd` | **Security / XML Handler** | **XML Handler Rate Limiting & Security Alerts**: Attached dedicated configurable rate limiter (`throttle:xml-handler`, default 600 req/min per IP) to `/api/v1/xml-handler` to protect against token brute force without impeding FreeSWITCH operations. Dispatched `XmlHandlerFailedAuth` and logged security alerts on failed token validations, integrated with `LogFailedXmlHandlerAuthListener` to record security incidents and trigger kernel bans on repeated failure. *(Resolves Task 5.3 / Security Audit L1 & L2)* |
| **2026-10-17** | `e3fc7b71` | **Auth / Panel** | **Open Registration Feature Gate**: Gated tenant user registration behind `auth.registration_enabled` (default `false`), redirecting `/panel/register` to login and aborting direct component mount with 404 when disabled. *(Resolves Task 5.2 / Security Audit M3)* |
| **2026-10-17** | `ac40716b` | **Telephony / ESL** | **Click-to-Call ESL Validation & Socket Sanitization**: Added strict regex validation in `ClickToCallForm`, tenant extension scoping, FreeSwitchControlService loopback bridging, and defense-in-depth newline injection guards in `FreeSwitchService::api()` and `bgapi()`. *(Resolves Task 5.1 / Security Audit H1)* |
| **2026-10-17** | `07c9fed0` | **Security / Threat Feeds** | **Threat Feed Redirect Pinning & SSRF Mitigation**: Enforced Guzzle `allow_redirects` with same-host pinning (`on_redirect`) and protocol downgrade protection in `ThreatFeedIngestionService`, refusing cross-host redirects to protect against SSRF and open redirect abuse. *(Resolves Task 5.4 / Security Audit L3)* |
| **2026-10-17** | `d3bc9496` | **Security / Threat Feeds** | **Atomic Inter-Process Lock Protocol for Shared Pending Sets**: Standardized exclusive file locking across `ThreatFeedIngestionService` and `GeoBlockingService`, acquiring `LOCK_EX` on `external_blocklist.nft.pending` before reading/snapshotting geo elements, and purged obsolete fallback references to separate `.nft` files. *(Resolves Task 5.7 / 4.x Audit M5)* |
| **2026-10-17** | `f6ade550` | **Security / Geo** | **Geo Cache Freshness & Whitelist Fail-Closed Integrity**: Enhanced `cacheNeedsDownload()` with TTL expiration checking based on `sync_interval`, wired interval checking into `SecuritySyncGeoIpCommand`, prevented partial-fetch cache corruption, and enforced fail-closed refusal when whitelist country zones fail. *(Resolves Task 1.1.3 / 4.x Audit H1 & H2)* |
| **2026-10-17** | `8ccbbcc9` | **Admin / Modules** | **Module Management Authorization Gate**: Registered `admin.modules.manage` permission and enforced in-component authorization checks inside `ModulesList::toggleEnabled()`, `confirmDeleteCustom()`, and `deleteCustomModule()`. Read-only administrators (`admin.modules.view`) receive read-only badges with interactive mutation controls suppressed. *(Resolves Task 5.6 / 4.x Audit H4)* |
| **2026-10-17** | `486f7a54` | **Security / Firewall** | **Reverse Lockout Protection for Whitelist Geo-Perimeter**: Refused `setPrefilterEnabled(false)` when "Block All Except Selected" (whitelist) Geo-Blocking is armed, preventing administrator lockout caused by removing stage 1's trusted IP accept while stage 9.5's geo drop perimeter remains active. *(Resolves Task 5.5 / 4.x Audit H3)* |
| **2026-10-16** | `4.0.2 Release` | **Security / Geo** | **Geo-Blocking Safety & Catalog Hardening**: Expanded country catalog to all 249 ISO 3166-1 countries, corrected IPdeny IPv6 download URL template, added dual-stack indicator badge in master firewall rules, and implemented atomic zone pre-syncing with automatic rollback. |
| **2026-10-16** | `08d07b31` | **Security / Browser Tests** | **Geo-Blocking Browser Coverage**: Added read-only browser assertions for the disabled Geo-Blocking tab (status badge, policy mode radios, CLI hint, country picker, operational metrics) and the master Firewall Rules geo row, keeping both surfaces in agreement. |
| **2026-10-16** | `2d295307` | **Security / Geo** | **Geo Sync Failure Hardening & Drop Counter Fix**: Missing, zero-byte, or unreadable zone caches now force a re-download and refuse whitelist arming when the re-download fails; locked and fallback set-element writes now fail the sync instead of reporting success while the kernel keeps stale elements; the Geo-Blocking drop counter now anchors to its own `@set` rule line so unrelated rules are never reported as geo drops. |
| **2026-10-16** | `608f3d5b` | **Documentation** | **Roadmap Source Consolidation**: Retired the temporary `modular-design-analysis.md` now that its surviving conclusions live in this roadmap. |
| **2026-10-16** | `919a7c7c` | **UI / Logs** | **Log Viewer Modernization & Dead Stub Purge**: Restyled `log-viewer.blade.php` with DaisyUI semantic tokens (`bg-base-100`, `border-base-300`, `select-bordered`, `input-bordered`, `toggle-primary`, `loading-spinner`), eliminating legacy Tailwind colors and raw inline SVG spinner. Purged 3 unused scaffolding stubs (`call-blocks`, `call-forwards`, `certificates`). *(Resolves UI Audit M4 & L5)* |
| **2026-10-16** | `56a1875b` | **FreeSWITCH / XML** | **Directory Lookup Query Optimization**: Bulk eager-loaded extensions and extension settings in `DirectoryXmlBuilder` for domain-wide lookups, bounding queries to a constant maximum ($\le 5$ queries) and eliminating $2N+1$ query degradation. *(Resolves Arch Audit L6)* |
| **2026-10-16** | `ddb73eb3` | **Security / Panel** | **Impersonation Banner Stored XSS Mitigation**: Escaped user and admin display names in `layouts/app.blade.php` using Blade escaping and removed raw HTML from `client.php` translations across English, Spanish, and French. Added security test verifying script tag escaping. *(Resolves UI Audit L6)* |
| **2026-10-16** | `2dfe1bdb` | **Documentation** | **Modular Architecture Consolidation**: Consolidated architectural analysis and retired stale 3.x security refactor plan. |
| **2026-10-16** | `5647251b` | **Security / Geo** | **DM-3 Geo Mode Transition Hardening**: Modified `GeoBlockingService` to flush only target-mode sets during sync, preserving active kernel sets until `apply`, and updated privileged helper to reload geo blocklist elements after ban clears even when threat feeds are off. *(Resolves Geo Refinement DM-3)* |
| **2026-10-16** | `2d6c0a0e` | **Security / Geo** | **DM-1 & DM-2 Geo-Blocking Atomic Pipeline**: Pre-synced zones before arming perimeter drop rule, atomic rollback on sync failure, validation of minimum 1 country and pre-filters, and exclusive flock read-modify-write protocol for `external_blocklist.nft.pending`. *(Resolves Geo Refinements DM-1 & DM-2)* |
| **2026-10-16** | `0bae4fe3` | **Security / Core** | **Armed Perimeter Restoration**: Ensured armed geo perimeters and kernel sets are restored on refused firewall changes. |
| **2026-10-16** | `4.0.1 Release` | **Security / Geo** | **Dedicated Geo-Blocking Subsystem**: Implemented IPdeny aggregated zone filtering, pure drop perimeter subchain (stage 9.5), dual whitelist/blacklist modes, and automated weekly CLI sync. |
