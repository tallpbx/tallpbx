# Implementation Plan: Security Module Architectural Decomposition & Domain Encapsulation

**Document Version:** 2.0  
**Target Branch:** `3.x`  
**Target Module:** `app-modules/security/`  
**Primary Goal:** Decompose the monolithic Security Center component and its coupled traits into encapsulated, domain-driven Livewire child components, eliminating multi-file cross-coupling so developers and AI agents can maintain individual security capabilities without reading or reasoning about unrelated subsystems.

---

## 1. Executive Summary & Problem Analysis

The Security Center is one of the most critical administrative consoles in TallPBX, governing host firewall policy, IP whitelists and blacklists, intrusion detection thresholds, and real-time brute force defense.

### 1.1 The Core Problem: Trait Sprawl and Cross-File Coupling
While the code was previously organized into traits, **traits provide only file splitting, not architectural encapsulation**:
- `SecurityManager.php` consists of **1 class + 6 traits totaling 2,315 lines**:
  - `SecurityManager.php` (499 lines)
  - `ManagesAllowBlockLists.php` (263 lines)
  - `ManagesAttackProtection.php` (409 lines)
  - `ManagesExternalBlocklists.php` (246 lines)
  - `ManagesFirewallRules.php` (442 lines)
  - `ManagesObserveMode.php` (98 lines)
  - `ManagesPreFilters.php` (358 lines)
- **High Cross-File Coupling**: Every trait reaches back into `SecurityManager` for `$this->autoApplyFirewallRuleset()`, `$this->adminIp`, and `$this->notifyError/Success()`. In addition, traits reach into other traits (e.g. `ManagesAllowBlockLists`, `ManagesPreFilters`, and `ManagesObserveMode` reaching into `firewallDefaultPolicy`; `ManagesAttackProtection` reaching into `loadFeedState`).
- **Heavy Developer & AI Agent Context Overhead**: Because all 45+ properties and 30+ methods share a single flat `$this` namespace, inspecting or changing a single button on one tab requires reading 4 to 6 separate files to understand shared state and avoid side effects.
- **Render Overload**: Although tab panels are conditionally rendered in Blade, `SecurityManager::render()` currently executes queries for **all tabs simultaneously on every request** (querying blacklist IPs, whitelist IPs, active bans, firewall rules, catalog services, scanner incidents, counters, and pre-filter rows).
- **Duplicated Apply Logic**: The 5-step apply pipeline (assert admin connection safe &rarr; assert local services safe &rarr; write pending config &rarr; validate syntax &rarr; apply helper &rarr; audit log & broadcast) was duplicated across `SecurityManager`, `SecurityApplyCommand`, and `FirewallBanReconciler`.

### 1.2 Architectural Framework Choice: Nested Components vs. Islands
The original Version 1.0 plan referred to this design as an "Island architecture". However, in Livewire 4, the `@island` directive creates isolated rendering regions *within* a component that share the same class, lifecycle, and properties. It does not provide file encapsulation.

To achieve true domain encapsulation and eliminate AI agent context overhead, the Security Center will use **Nested Child Livewire Components** (`<livewire:security.tabs.* />`), where each tab owns its own class, state, actions, and Blade views.

---

## 2. Target Architecture: Encapsulated Component Hierarchy

```
SecurityManager (Page Shell Orchestrator - ~150 lines)
├── Cockpit Alert Banners (Lockout Warning, Drift Notice, Observe Mode, Pre-filter Bypassed)
├── System Status Overview Cards (Cached Reads & Real-Time Echo Listeners)
├── Master Switches Card (Firewall On/Off & Observe Mode)
└── Tab Strip Navigation (Deep-linked via ?tab=)
    ├── Tab 1: <livewire:security.tabs.firewall-rules :prefilter-enabled="$prefilterEnabled" />
    ├── Tab 2: <livewire:security.tabs.block-allow :prefilter-enabled="$prefilterEnabled" />
    ├── Tab 3: <livewire:security.tabs.attackers :prefilter-enabled="$prefilterEnabled" />
    └── Tab 4: <livewire:security.tabs.external-blocklists :prefilter-enabled="$prefilterEnabled" />
```

### 2.1 Component Domain Boundaries

| Component | Class Location | Domain Scope & Encapsulated State |
|---|---|---|
| **SecurityManager** (Shell) | `Livewire/SecurityManager.php` | Page orchestrator, active tab routing (`?tab=`), master firewall switch, observe mode switch, cockpit alert banners, status cards, and global Reverb push listeners. |
| **FirewallRulesTab** | `Livewire/Tabs/FirewallRulesTab.php` | Pre-filter stage ordering, sequential firewall rules table, system service ports catalog, default policy modal, edit service modal, and custom rule modal. |
| **BlockAllowTab** | `Livewire/Tabs/BlockAllowTab.php` | Whitelist and blacklist tables, CIDR and IP address validation, search filters, quick 1-click administrator protection, promote-to-whitelist/blacklist. |
| **AttackersTab** | `Livewire/Tabs/AttackersTab.php` | Active banned IP addresses table, manual ban modal, unban action, SIP scanner incident promotion, custom scanner signatures, attack protection sensitivity drawer. |
| **ExternalBlocklistsTab** | `Livewire/Tabs/ExternalBlocklistsTab.php` | Threat feed provider status, country code filtering, download sync frequency, on-demand synchronization, remove-all blocks button. Gated by `security.threat-feeds.manage`. |

---

## 3. Detailed Implementation Phases

### Phase 1: Unify Apply Pipeline into `FirewallApplier` Domain Service
**Files:**
- `app-modules/security/src/Contracts/FirewallApplierInterface.php` (New)
- `app-modules/security/src/Services/FirewallApplier.php` (New)
- `app-modules/security/src/Livewire/SecurityManager.php`
- `app-modules/security/src/Console/Commands/SecurityApplyCommand.php`
- `app-modules/security/src/Services/FirewallBanReconciler.php`

1. Create `FirewallApplier` service encapsulating:
   - Zero-lockout preflight validation (`assertSafe`, `assertLocalServicesSafe`).
   - Atomic pending configuration generation (`writePending`, `validateSyntax`).
   - Bounded helper execution (`SecurityExecutorInterface::apply()`).
   - Audit logging and `FirewallRulesetUpdated` event broadcasting.
   - Returns structured `FirewallApplyResult` (success status, error message, applied timestamp).
2. Refactor `SecurityManager::autoApplyFirewallRuleset()`, `SecurityApplyCommand`, and `FirewallBanReconciler` to inject and call `FirewallApplier`.
3. Verify all existing safety and lockout guard tests pass cleanly.

---

### Phase 2: Domain Boundary Clean-Up in Existing Traits
Before extracting classes, reorganize cross-cutting logic within traits so domain concerns are clean:
1. **Separate Attackers from Firewall Rules**:
   - Move TFTP Defense methods (`setTftpDefense`, `tftpDefenseCounters`) out of `ManagesAttackProtection` into `ManagesFirewallRules` (where TFTP is displayed in the UI).
   - Keep brute-force settings (`maxRetry`, `findTime`, `banTime`, SIP/Web/SSH protection) strictly with `ManagesAttackProtection`.
2. **Move Tab Queries into Lazy Computed Properties**:
   - Refactor `render()` so tab queries (e.g. `$blacklistIps`, `$firewallRules`, `$scannerIncidents`) become `#[Computed]` methods referenced only in that tab's view.
   - When a user views the Firewall Rules tab, queries for Attackers and IP lists are not executed.

---

### Phase 3: Extract Child Tab Components (One Tab at a Time)

#### Step 3.1: Extract `ExternalBlocklistsTab` (Most Isolated)
- Create `Livewire/Tabs/ExternalBlocklistsTab.php`.
- Move `ManagesExternalBlocklists` properties, methods, and `tabs/external-blocklists.blade.php` view.
- Inject `FirewallApplier` and `ThreatFeedManager`.
- Gated by `ensureFeedManagePermission()` (`security.threat-feeds.manage`).
- Dispatches event to shell: `$this->dispatch('security-state-changed')`.

#### Step 3.2: Extract `BlockAllowTab`
- Create `Livewire/Tabs/BlockAllowTab.php`.
- Move `ManagesAllowBlockLists` logic, searches (`$ipSearch`, `$whitelistSearch`, `$blacklistSearch`), and `tabs/block-allow.blade.php`.
- Enforce `ensureEditPermission()` (`security.edit`) on all mutations.

#### Step 3.3: Extract `AttackersTab`
- Create `Livewire/Tabs/AttackersTab.php`.
- Move ban management, manual ban modal, scanner signatures, and attack settings drawer (`protection-settings.blade.php`).
- Enforce `ensureEditPermission()` (`security.edit`) on all mutations.

#### Step 3.4: Extract `FirewallRulesTab`
- Create `Livewire/Tabs/FirewallRulesTab.php`.
- Move rule sequencing, pre-filter ordering, system service catalog, TFTP defense, and associated modals (`default-policy`, `custom-rule`, `edit-system-service`, `confirm-disable-prefilter`).
- Split `tabs/firewall-rules.blade.php` (651 lines) into readable Blade partials:
  - `tabs/firewall-rules/prefilter-pipeline.blade.php`
  - `tabs/firewall-rules/rules-table.blade.php`
  - `tabs/firewall-rules/system-services.blade.php`
  - `tabs/firewall-rules/tftp-defense.blade.php`

---

### Phase 4: Event & State Communication Architecture

1. **Child &rarr; Shell Communication**:
   - When any tab applies a firewall change: `$this->dispatch('security-state-changed');`
   - Shell listens:
     ```php
     #[On('security-state-changed')]
     public function handleStateChange(): void
     {
         $this->refreshLiveFirewallPolicy();
         $this->refreshFirewallSyncState();
     }
     ```
2. **Shell &rarr; Child Communication (Reactive Props)**:
   - Shell passes global properties down as `#[Reactive]` attributes:
     - `:prefilter-enabled="$prefilterEnabled"`
     - `:firewall-enabled="$firewallEnabled"`
3. **Real-Time Push Distribution (Laravel Echo / Reverb)**:
   - Shell handles global alerts (`.FirewallRulesetUpdated`, `.ObserveTrafficLogged`).
   - `AttackersTab` listens directly to `.SecurityBanUpdated` and `.SecurityIncidentLogged` to refresh banned IP rows in real time without triggering full-page diffs.

---

### Phase 5: Test Suite Modernization & Parity Verification

1. **Retain and Re-home Existing Feature Tests**:
   - Verify that all existing tests in `app-modules/security/tests/` are mapped to the new component structure.
   - `SecurityManagerAuthorizationTest` validates `security.edit` across each child component's endpoints.
   - `SecurityManagerKernelStatusBudgetTest` ensures that mounting the shell and child components still queries the kernel helper at most once per cache window.
2. **Standard Test Conventions**:
   - Maintain `AdminSeeder` and `grantAdminPermissions()` conventions in all component tests.
   - Verify full test suite: `php artisan test app-modules/security --compact --parallel`.

---

## 4. Verification & Success Criteria Gate

- [ ] All 4 tabs maintain 100% feature and visual parity with existing DaisyUI design standards.
- [ ] No regression in zero-lockout prevention (`assertSafe`) or loopback local-services protection (`assertLocalServicesSafe`).
- [ ] Component file lengths average ~100–180 lines, eliminating trait sprawl and multi-file jumps.
- [ ] Changing one tab touches only that tab's component and view files, with zero blast radius on peer tabs.
- [ ] Single source of truth for kernel application: `FirewallApplier` used by UI, CLI, and background reconcilers.
- [ ] `security.edit` permission enforced across all mutating actions in all child components.
- [ ] Code style passes Pint: `./vendor/bin/pint --dirty --test`.
- [ ] Full security test suite passes: `php artisan test app-modules/security --compact --parallel`.
