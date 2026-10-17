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
| 1.1.1 | Extract `FirewallApplier` domain service | Pending | 2026-10-16 |
| 1.1.2 | Lazy `#[Computed]` properties for cockpit tabs | Pending | 2026-10-16 |
| 1.2.1 | Decompose `SecurityConfigGenerator` | Pending | 2026-10-16 |
| 1.3.1 | Reorganize `src/` into sub-domain namespaces | Pending | 2026-10-16 |
| 1.3.2 | Realign domain trait boundaries | Pending | 2026-10-16 |
| 1.3.3 | Decompose oversized tab views into partials | Pending | 2026-10-16 |
| 2.1 | Standardized form submission loading feedback | Pending | 2026-10-16 |
| 2.2 | Page-title typography normalization | Pending | 2026-10-16 |
| 2.3 | Localize hardcoded table headers | Pending | 2026-10-16 |
| 2.4 | Normalize arbitrary font sizes | Pending | 2026-10-16 |
| 2.5 | Standardize toggle switch sizing & loading locks | Pending | 2026-10-16 |
| 3.1 | Migrate runtime status views from `wire:poll` to Echo push | Pending | 2026-10-16 |
| 3.2 | ESL event ingestion overflow & queue buffering | Pending | 2026-10-16 |
| 4.1 | Shared kernel boundary architecture test & listener migration | Pending | 2026-10-16 |
| 4.2 | Enforce contract seams over cross-module model imports | Pending | 2026-10-16 |
| 4.3 | Consolidate module route registration | Pending | 2026-10-16 |
| 4.4 | Clarify disabled module lifecycle semantics | Pending | 2026-10-16 |
| 4.5 | Clean up `TenantContext::current()` side effects | Pending | 2026-10-16 |
| 4.6 | Decompose large shared-kernel classes | Pending | 2026-10-16 |
| 4.7 | Environment variable documentation reference | Pending | 2026-10-16 |
| 5.1 | Click-to-call ESL command validation | Pending | 2026-10-16 |
| 5.2 | Open registration feature gate | Pending | 2026-10-16 |
| 5.3 | XML handler rate limiting & access hardening | Pending | 2026-10-16 |
| 5.4 | Threat feed redirect pinning | Pending | 2026-10-16 |

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
- **Problem**: `SecurityManager::render()` queries records for all 6 tabs simultaneously on every request (threat feeds, scanner logs, ban records, custom rules, services, whitelist/blacklist), causing unnecessary database queries when viewing a single tab.
- **Implementation**:
  - Convert tab record queries to Livewire 4 `#[Computed]` properties:
    - `#[Computed] public function rules(): Collection`
    - `#[Computed] public function serviceRules(): Collection`
    - `#[Computed] public function whitelist(): Collection`
    - `#[Computed] public function blacklist(): Collection`
    - `#[Computed] public function bans(): Collection`
    - `#[Computed] public function scannerIncidents(): Collection`
    - `#[Computed] public function threatFeeds(): Collection`
  - Ensure tab views reference these computed properties directly so inactive tabs execute zero DB queries during live updates.
- **Verification**: Query log assertions verifying requests on one tab execute 0 queries for other tabs.

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

#### Task 1.3.1: Reorganize `src/` into Sub-domain Namespaces
- **Implementation**:
  - Reorganize flat `app-modules/security/src/Services/` into cohesive sub-domain folders:
    - `src/Firewall/`: `FirewallApplier`, `SecurityConfigGenerator`, `SecurityExecutor`, `LockoutGuardService`, `FirewallBanReconciler`, `FirewallSyncVerifier`.
    - `src/Geo/`: `GeoBlockingService`, `IpdenyProvider`, `SecurityGeoRule` model.
    - `src/ThreatFeeds/`: `ThreatFeedManager`, `ThreatFeedIngestionService`, `VoipblFeedProvider`, `SecurityThreatFeed` model.
    - `src/Bans/`: `SecurityBanService`, `LogFailedLoginListener`, `LogFailedSipAuthListener`, `SecurityBan` model.
    - `src/Detection/`: `SipScannerSignatures`, `LogSipScannerListener`, `SipScannerDialplanContributor`.
    - `src/Observe/`: `ObserveMetricsParser`, `SecurityObserveCommand`.
  - Maintain `Contracts/` and `SecurityServiceProvider` as the module's clean public surface.

#### Task 1.3.2: Realign Domain Trait Boundaries
- **Implementation**:
  - Move misplaced methods in `SecurityManager` concern traits to their visual domain match:
    - Move `setTftpDefense()` and `tftpDefenseCounters()` from `ManagesAttackProtection` into `ManagesFirewallRules` (where the TFTP workbench is visually rendered).

#### Task 1.3.3: Decompose Oversized Tab Views into Partials
- **Implementation**:
  - Decompose `resources/views/tabs/firewall-rules.blade.php` (730 LOC; re-verified 2026-10-16) into readable partials:
    - `partials/prefilter-pipeline.blade.php`
    - `partials/rules-table.blade.php`
    - `partials/system-services.blade.php`
    - `partials/tftp-defense.blade.php`

---

## Stream 2: UI/UX Consistency, Loading Feedback & Typography

*Source: retired `ui-audit-report.md` (untracked working audit, consolidated here). Item IDs (M2, L1, ...) are retained for traceability; all quantitative figures were re-measured against the working tree on 2026-10-16.*

### Task 2.1: Standardized Form Submission Loading Feedback (`<x-form-submit-button>`) (UI Audit M3 & L3)
- **Problem**: Of the 47 module edit/create views (`acl-edit`, `dialplans-edit`, `bridges-edit`, `sip-profiles-edit`, `extensions-edit`, `users-edit`, `tenants-edit`, etc.), 42 contain no `wire:loading` directive at all *(re-measured 2026-10-16)*, so their submits have no busy state, spinner, or disabled attribute. When saves trigger FreeSWITCH reload jobs or cache invalidation, the screen freezes with zero feedback. In addition, `sip-profiles-edit` and `extensions-edit` have button sizing drift (`btn` instead of standard `btn-sm`).
- **Implementation**:
  - Create `<x-form-submit-button>` Blade component with built-in:
    - `wire:loading.attr="disabled"`
    - DaisyUI loading spinner (`<span class="loading loading-spinner loading-xs" wire:loading></span>`)
    - Standard `btn btn-primary btn-sm` sizing
    - Dynamic slot text supporting present-continuous busy labels (e.g., "Saving...").
  - Batch-migrate all 47 edit/create views to use `<x-form-submit-button>` and normalize cancel links to `btn btn-ghost btn-sm`.
- **Verification**: Browser smoke tests verifying button locks and spinner appears on save.

### Task 2.2: Page-Title Font Weight & Typography Normalization (UI Audit M2)
- **Problem**: 83 views use `text-2xl font-semibold` while 43 use a `text-2xl font-bold` variant; only 7 views contain `tracking-tight` at all, so the AGENTS.md page-title standard (`text-2xl font-bold tracking-tight text-base-content`) is currently the minority pattern *(re-measured 2026-10-16; the original audit catalogued 81 vs 27)*.
- **Implementation**:
  - Normalize all module and panel page titles to the established project standard: `text-2xl font-bold tracking-tight text-base-content`.
  - Add a Pest UI architecture test asserting page title headers match the standard pattern across all module views.

### Task 2.3: Localize Hardcoded Table Header (`<th>`) Contents (UI Audit L1)
- **Problem**: 39 table header cells contain raw English strings (`Name`, `Actions`, `Type`, `Status`, `Provider`, `Destination`, `Queue`, `Profile`, `Value`, `Key`, `Strategy`, `Timestamp`, `Rule Stage`, `Proto`, `Message`, plus `Source IP`, `SAN Domains`, `Name / Domain`, `Interface`, and `File`), rendering untranslated in Spanish and French *(re-measured 2026-10-16; the original audit catalogued ~25)*.
- **Implementation**:
  - Replace unlocalized `<th>` contents with root dictionary keys (`__('client.name')`, `__('client.actions')`, `__('client.status')`, etc.) or module-specific translations where domain-specific.
  - Verify with `TranslationParityTest` (must remain 100% green).

### Task 2.4: Normalize Arbitrary Font Sizes (UI Audit L2)
- **Problem**: 30 occurrences of arbitrary one-off font sizes (`text-[10px]` and `text-[11px]`) concentrated in the security module and display-settings drawer violate the design token convention *(re-measured 2026-10-16; the original audit catalogued 21)*.
- **Implementation**:
  - Normalize all occurrences to standard `text-xs` using opacity classes (`text-base-content/60`) for visual hierarchy.

### Task 2.5: Standardize Toggle Switch Sizing & Loading Locks (UI Audit L4)
- **Problem**: 12 views render DaisyUI toggle switches; 4 edit views (`tenants-edit`, `admins-edit`, `tenant-domains-edit`, `backups-edit`) still use `toggle toggle-primary` without the standard `toggle-sm` sizing, and 2 of those (`tenants-edit`, `tenant-domains-edit`) carry no `wire:loading.attr` lock at all *(re-measured 2026-10-16; the original audit catalogued 7 of 15 usages)*.
- **Implementation**:
  - Standardize all toggle switches to `toggle toggle-sm toggle-primary` with pointer-events lock and disabled attribute during mutation.

---

## Stream 3: Real-Time Telephony Push Architecture (Echo/Reverb)

*Source: retired `ui-audit-report.md` (M1) and `architecture-audit-report.md` (M4), both consolidated here.*

### Task 3.1: Migrate Runtime Telephony Status Views from `wire:poll` to Echo Push (UI Audit M1)
- **Problem**: Six runtime telephony status views aggressively poll the backend via `wire:poll` (2s–15s), violating the project mandate to use Reverb/Echo push for real-time tables:
  - `active-calls-list` (2s)
  - `active-conferences-list` (5s)
  - `registrations-list` (10s)
  - `sip-status-list` (15s)
  - `call-center-active-list` (5s)
  - `operator-panel-index` (5s)
  *(Note: three non-telephony views poll legitimately and are preserved — `git-update` (1s while an update runs), `restore-archive` (5s during restores), and the log viewer (5s live tail). Poll signatures re-checked 2026-10-16.)*
- **Implementation**:
  - FreeSWITCH ESL listeners already broadcast typed events (`ChannelCreate`, `ChannelAnswer`, `ChannelHangupComplete`, `SofiaRegister`).
  - Configure the 6 Livewire components to listen on tenant private channels with `#[On('echo:tenant.{tenantId}.telephony,...')]` and refresh data reactively.
  - Remove all `wire:poll` directives from the 6 views.
  - Add tests asserting `assertDontSee('wire:poll')` on all 6 views (mirroring existing dashboard tests).

### Task 3.2: ESL Event Ingestion Overflow & Queue Buffering (Arch Audit M4)
- **Problem**: `FreeSwitchListenCommand` dispatches every ESL event inline in a single-threaded loop; heavy event storms could create backpressure on the FreeSWITCH socket.
- **Implementation**:
  - Retain inline dispatch for lightweight state events.
  - Implement a bounded in-memory buffer or Redis queue push for heavyweight listeners (XML reloads, complex DB aggregations) to ensure the ESL socket never stalls during high call volume.

---

## Stream 4: Shared Kernel Boundary & Modular Discipline

*Source: retired `architecture-audit-report.md` (M1–M3, L3–L7) and `modular-design-analysis.md` (Part 2; retired in commit `608f3d5b`), both consolidated here.*

### Task 4.1: Shared Kernel Reverse Dependency Architecture Test & Listener Migration
- **Problem**: `app/` contains domain leakage where shared kernel classes reach into module models directly (`app/Listeners/FreeSwitch/*`, `app/Services/TenantDefaults/*`, `app/Services/DestinationResolver`, `PbxLoadTestSeedCommand`).
- **Implementation**:
  - Add Pest architecture test `tests/Feature/Architecture/SharedKernelBoundaryTest.php` forbidding `use Modules\` inside `app/` outside an explicit, documented allowlist (`AppServiceProvider` composition root, TenantDefaults, load testing).
  - Opportunistically migrate `app/Listeners/FreeSwitch/*` adapters into module-owned listeners subscribing to shared events (using the security module's clean listener pattern as the template).

### Task 4.2: Enforce Contract Seams Over Direct Cross-Module Model Imports
- **Problem**: `MediaAsset` is imported in 20 src files and `Extension` in 18 src files across different modules, coupling schemas across contexts even when service interfaces (`MediaStorageServiceInterface`) exist.
- **Implementation**:
  - Add directional architecture rules forbidding direct instantiation of `FileStore` / `MediaAsset` outside `Modules\FileStores`.
  - Route cross-module consumers through `MediaStorageServiceInterface`.

### Task 4.3: Consolidate Module Route Registration (Arch Audit M2)
- **Problem**: 22 of the 60 modules use explicit `routes/web.php` while the remaining modules use convention-based auto-registration in `App\Support\ModuleServiceProvider::autoRegisterRouteGroups()` *(re-measured 2026-10-16; the original audit catalogued 21)*. Maintaining two parallel mechanisms doubles the cognitive overhead.
- **Implementation**:
  - Standardize all modules on explicit `routes/web.php` files (easiest to grep, audit, and debug), and retire the reflection-heavy `autoRegisterRouteGroups()` path from `ModuleServiceProvider`.

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
- **Problem**: Several large single-purpose classes mix raw protocol handling with business APIs:
  - `FreeSwitchService` (714 LOC): Mixes raw ESL socket framing and reconnects with high-level command APIs.
  - `GitUpdateService` (715 LOC): Mixes Git process execution with status storage and web logging.
  - `ModuleLifecycleService` (696 LOC; previously recorded as 893 — re-measured 2026-10-16): Mixes dependency resolution with DB lifecycle and rollback.
- **Implementation**:
  - Split `FreeSwitchService` into `EslConnection` (protocol framing) + `FreeSwitchApi` (command API).
  - Split `GitUpdateService` into `GitUpdateRunner`, `UpdateStatusStore`, and `UpdateLogWriter`.

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

---

## Completed & Verified Milestone Log

All items below have been implemented, verified through automated Pest test suites, and committed to branch `4.x`. Rows are appended in the same commit that closes a task (see the close-out rule in the Task Status Index):

| Date | Commit | Component | Description |
| :--- | :--- | :--- | :--- |
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
