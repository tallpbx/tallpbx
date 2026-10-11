# Implementation Plan: Security Module Architecture & Performance Refactoring

**Document Version:** 1.0  
**Target Branch:** `3.x`  
**Target Module:** `app-modules/security/`  
**Primary Goal:** Transform the Security module from a monolithic Livewire component into an isolated, responsive Livewire Island architecture, drastically reducing latency during development, code maintenance, and UI interaction.

---

## 1. Executive Summary & Problem Analysis

Developing and interacting with the Security Center currently feels sluggish compared to other modules. Small edits require disproportionate effort, and UI actions involve noticeable roundtrip latency.

### Core Bottlenecks Identified

1. **Monolithic Livewire Component (`SecurityManager.php`)**:
   - Despite splitting logic into trait files (`ManagesFirewallRules`, `ManagesPreFilters`, `ManagesAttackers`, `ManagesExternalBlocklists`), `SecurityManager` is still **a single, massive Livewire component**.
   - It maintains **45+ public state properties** across all six tabs simultaneously.
   - Any single interaction (e.g. toggling the External Blocklist switch, clicking a pagination link on Banned Attackers, or typing in a search box) causes Livewire to:
     - Serialize the entire state of all 6 tabs.
     - Re-evaluate all computed counters, kernel status regexes, and policy queries.
     - Diff and transmit the DOM for the entire page.
2. **Hidden Execution Latency**:
   - Every roundtrip touches the live kernel `nftables` status unless guarded by caching.
   - Long running sequential operations (like unreferenced threat feed loading or redundant dry-run parses) previously blocked PHP requests.
3. **Heavy Monolithic Test Suite**:
   - Because `SecurityManager` is one component, testing a single tab requires booting the complete manager state, establishing group permissions, mocking every subsystem service (`SecurityExecutorInterface`, `SecurityConfigGenerator`, `ThreatFeedIngestionService`), and rendering the entire template tree.
   - Running tests sequentially takes ~60 seconds vs ~5 seconds in parallel.

---

## 2. Target Architecture: Livewire Island Decomposition

### 2.1 The New Security Center Component Hierarchy

Instead of one monolithic component managing all tabs, `SecurityManager` will become a **lightweight orchestrator shell**, delegating each tab to an **independent Livewire island component**:

```
SecurityManager (Page Shell Orchestrator)
├── Header & Global Status Overview Cards (Cached Reads)
├── Master Switches Card (Firewall On/Off & Observe Mode)
├── Top Cockpit Alert Banners (Lockout, Bypassed Pre-filters, etc.)
└── Tab Navigation Bar
    ├── Tab 1: <livewire:security.tabs.firewall-rules />
    ├── Tab 2: <livewire:security.tabs.block-allow />
    ├── Tab 3: <livewire:security.tabs.attackers />
    ├── Tab 4: <livewire:security.tabs.external-blocklists />
    ├── Tab 5: <livewire:security.tabs.service-ports />
    └── Tab 6: <livewire:security.tabs.audit-log />
```

### 2.2 Benefits of the Island Architecture

| Metric | Monolithic Architecture (Current) | Livewire Island Architecture (Proposed) |
|---|---|---|
| **Component File Size** | 1 class + 4 traits (~1,800 lines total) | Shell: ~120 lines; Tabs: ~80–180 lines each |
| **State Serialization** | 45+ properties serialized per request | Only the active tab's properties serialized |
| **DOM Diff Payload** | Entire Security Center DOM diffed | Only active tab sub-tree diffed |
| **Test Execution** | Monolithic test setup (~260 lines) | Focused component tests (~30–50 lines) |
| **Blast Radius** | Editing one tab risks breaking others | Tabs are completely isolated |
| **Micro-Iteration Speed** | Slow roundtrips & complex debugging | Sub-second test execution & rapid debugging |

---

## 3. Detailed Implementation Phases

### Phase 1: Create Component Island Directory & Shell Definition
**Files:**
- `app-modules/security/src/Livewire/SecurityManager.php` (Refactored Shell)
- `app-modules/security/src/Livewire/Tabs/` (New Component Namespace)

1. Create directory `app-modules/security/src/Livewire/Tabs/`.
2. Register the Livewire component names in `SecurityServiceProvider` or via convention (`security.tabs.*`).
3. Refactor `SecurityManager`:
   - Keep only page-level concerns: `$firewallEnabled`, `$firewallObserveMode`, `$activeTab`, lockout status, and overall status metrics.
   - Render tab content conditionally or with Livewire lazy-loading:
     ```blade
     @if ($activeTab === 'external-blocklists')
         <livewire:security.tabs.external-blocklists :wire:key="'tab-external-blocklists'" />
     @elseif ($activeTab === 'firewall-rules')
         <livewire:security.tabs.firewall-rules :wire:key="'tab-firewall-rules'" />
     ...
     @endif
     ```

---

### Phase 2: Extract Independent Tab Components

#### Step 2.1: `ExternalBlocklistsTab` Component
**Files:**
- `app-modules/security/src/Livewire/Tabs/ExternalBlocklistsTab.php`
- `app-modules/security/resources/views/tabs/external-blocklists.blade.php`
- Migrate logic from `ManagesExternalBlocklists` directly into `ExternalBlocklistsTab`.
- Properties: `$feedEnabled`, `$feedCountryMode`, `$feedCountriesInput`, `$feedSyncInterval`.
- Methods: `setFeedEnabled()`, `syncThreatFeedNow()`, `removeAllFeedBlocks()`, `saveFeedSettings()`.
- Dispatches global state event `#[Dispatch('security-state-changed')]` when the feed changes.

#### Step 2.2: `AttackersTab` Component
**Files:**
- `app-modules/security/src/Livewire/Tabs/AttackersTab.php`
- `app-modules/security/resources/views/tabs/attackers.blade.php`
- Migrate logic from `ManagesAttackers`.
- Manages banned IP search, pagination, manual ban modal, and unban actions in total isolation.

#### Step 2.3: `BlockAllowTab` Component
**Files:**
- `app-modules/security/src/Livewire/Tabs/BlockAllowTab.php`
- `app-modules/security/resources/views/tabs/block-allow.blade.php`
- Manages Whitelist and Blacklist tables, additions, deletions, CIDR parsing, and import/export.

#### Step 2.4: `FirewallRulesTab` Component
**Files:**
- `app-modules/security/src/Livewire/Tabs/FirewallRulesTab.php`
- `app-modules/security/resources/views/tabs/firewall-rules.blade.php`
- Migrate logic from `ManagesFirewallRules` and `ManagesPreFilters`.
- Manages pre-filter pipeline, rule ordering, policy toggles, TFTP defense, and rule addition.

#### Step 2.5: `ServicePortsTab` & `AuditLogTab` Components
- Extract remaining auxiliary tabs into standalone components.

---

### Phase 3: Cross-Component Communication & Event Architecture

When an action on one tab impacts global page state (e.g. enabling a pre-filter or applying a change):
1. **Livewire Event Dispatching**:
   - Tab triggers: `$this->dispatch('security-state-changed');`
   - Shell listens: `#[On('security-state-changed')] public function refreshGlobalStatus(): void`
2. **Local Operational Feedback**:
   - Each tab can trigger toast messages or display inline feedback directly within its own boundary without polluting global manager state.

---

### Phase 4: Test Suite Modernization & Isolation

1. **Decompose Monolithic Tests**:
   - `SecurityManagerThreatFeedTest.php` &rarr; `ExternalBlocklistsTabTest.php`
     - Tests test `Livewire::test(ExternalBlocklistsTab::class)` directly!
     - No need to boot firewall rule parsers or mock unrelated attacker services.
     - Test run time drops from 9.4s down to <0.8s.
   - `SecurityManagerAttackersTest.php` &rarr; `AttackersTabTest.php`
   - `SecurityManagerFirewallRulesTest.php` &rarr; `FirewallRulesTabTest.php`
2. **Enforce Targeted Test Command Rules**:
   - Document development test workflows in `AGENTS.md` and module README:
     ```bash
     # Fast, targeted single-component test:
     php artisan test app-modules/security/tests/ExternalBlocklistsTabTest.php
     
     # Full security suite in parallel:
     php artisan test app-modules/security --compact --parallel
     ```

---

## 4. Verification & Success Criteria Gate

- [ ] All 6 tabs function identically to the user with improved UI responsiveness.
- [ ] No regression in firewall lockout prevention or local-services guards.
- [ ] Tab switching does not trigger unneeded backend queries on other tabs.
- [ ] Component file lengths average ~100–150 lines instead of 600+ lines.
- [ ] Single tab test execution completes in under 1 second.
- [ ] Pint passes cleanly: `./vendor/bin/pint --dirty --test`.
- [ ] Full security test suite passes: `php artisan test app-modules/security --compact --parallel`.
