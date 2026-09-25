# Test Suite Optimization & Consolidation Plan

## Executive Summary

The TallPBX test suite currently contains **284 test files** (280 Pest test classes and 4 Dusk browser test suites), **2,349 automated Pest tests** (plus 44 Dusk browser tests), and **9,801 assertions** (verified passing on September 25, 2026). While 100% passing, running the full Pest suite takes approximately **175–185 seconds in parallel** (on 4 CPU cores) and **~7.5–8.0 minutes sequentially**.

This suite has expanded significantly through recent development iterations, including the v1.1.3 and v1.1.4 release cycles, the one-line bootstrap installer, alternate sound prompt management, standardized `tallpbx/module-*` package naming, multi-host SIP load testing sweep scripts, cascading `XML_CACHE_*` configuration, and automatic dialplan cache invalidation model mutation observers (`RoutingCacheObserver`).

This document outlines a phased strategy to significantly speed up total execution time by:
1. **Consolidating test files** from 284 down to ~140–145 files (~50% reduction in file boundary transitions).
2. **Eliminating redundant database and fixture operations** in `beforeEach()` hooks and permission helpers.
3. **Optimizing SQLite testing configurations and process execution**.

### Targets & Guarantees
- **Test Count Preservation:** Exactly **2,349 / 2,349 Pest tests preserved** (0 tests lost).
- **Assertion Parity:** All **9,801+ assertions preserved**.
- **Target Parallel Runtime:** Reduced from ~180s down to **~90–110s (~40–50% speedup)**.
- **Target Sequential Runtime:** Reduced from ~460s down to **~240–280s**.
- **Best Practice Adherence:** Cohesive domain grouping using Pest `describe()` blocks; avoiding parallel worker starvation (capping files at 15–30 tests).

---

## 1. Root Cause Analysis

### 1.1 Parallel Worker File-Boundary Overhead
- Paratest / Pest parallelizes at the **file (test class) level**, not at the test function level.
- Each test file incurs fixed overhead: worker IPC, Laravel application boot, service provider discovery, and database reset (`LazilyRefreshDatabase`).
- **65% of all Pest test files (181 out of 280 test files) contain 7 or fewer tests**, with 43 files containing only 1 to 3 tests. For these micro-files, the framework bootstrap overhead dominates actual execution time.

### 1.2 The "Straggler Problem" & Optimal File Sizing
- Merging everything into monolithic files (e.g. 80+ tests) causes worker starvation, where 3 CPU cores finish early and sit idle waiting for 1 core to finish the mega-file.
- The **optimal sweet spot is 15 to 30 tests per file** (runtime ~1.5 to 3.5 seconds). This keeps all CPU cores fully saturated while eliminating ~140 redundant file resets.

### 1.3 Wasteful Fixtures in `beforeEach()`
- In `tests/Feature/Pbx/RouteMiddlewareTest.php`, `beforeEach()` iterates over all registered permissions and inserts them into SQLite before *every single test*.
- 5 out of the 6 tests in that file only test route naming, URI syntax, and reflection parameter braces—they never touch the database or the admin model. Over 4 seconds are wasted on unused inserts.
- Multiple test files call `$this->seed(AdminSeeder::class)` and `$this->seed(SecurityServiceSeeder::class)` in `beforeEach()`, re-running multi-table permission synchronization for every single test method.

### 1.4 Un-optimized Permission Helpers
- `grantAdminPermissions()` and `grantTenantUserPermissions()` in `tests/Pest.php` are called >100 times across feature tests.
- Each call invokes `fake()->sentence()`, creates new `Group` records, performs single-row pivot inserts in loops, and queries the database repeatedly.

### 1.5 Real Process Execution in Service Tests
- `GitUpdateServiceTest.php` executes real `git` subprocesses (`git fetch --dry-run`, `git branch`) against the filesystem, taking 150ms–750ms per test, rather than utilizing `FakeProcessRunner.php`.

---

## 2. Phased Implementation Plan

```
┌───────────────────────────────────────────────────────────────────────────────┐
│ Phase 1: Mechanics & Speed-Up Quick Wins                                      │
│ - Optimize permission helpers in tests/Pest.php (remove Faker, batch inserts)  │
│ - Fix RouteMiddlewareTest.php beforeEach waste                                │
│ - Tune SQLite in-memory testing pragmas                                       │
│ - Use FakeProcessRunner in GitUpdateServiceTest                               │
└──────────────────────────────────────┬────────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼────────────────────────────────────────┐
│ Phase 2: Legacy Pbx & Admin Livewire Consolidation (35 files eliminated)      │
│ - Consolidate 7 PBX domains (21 files -> 7 files)                             │
│ - Consolidate Admin & Core Livewire List/Edit pairs (24 files -> 14 files)    │
└──────────────────────────────────────┬────────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼────────────────────────────────────────┐
│ Phase 3: Module & Feature Test Consolidation (67 files eliminated)            │
│ - Standardize on 2-file pattern: ComponentTest + ServiceAndIsolationTest      │
│ - Consolidate small modules (<15 tests) into single [Module]Test.php          │
│ - Absorb standalone 1-3 test micro-files (*GreetingStorage, DeleteMedia, etc) │
│ - Integrate script validation suites (PbxCacheSweep + PbxSippValidation)     │
└──────────────────────────────────────┬────────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼────────────────────────────────────────┐
│ Phase 4: Security Module Consolidation (7–8 files eliminated)                 │
│ - Consolidate 15 test files (158 tests) into balanced suites                  │
│ - Retain standalone execution for long-running managers & runners             │
└──────────────────────────────────────┬────────────────────────────────────────┘
                                       │
┌──────────────────────────────────────▼────────────────────────────────────────┐
│ Final Verification & Benchmarking                                             │
│ - Verify 2,349 / 2,349 tests pass with 9,801+ assertions                      │
│ - Benchmark parallel execution time vs baseline                               │
└───────────────────────────────────────────────────────────────────────────────┘
```

---

### Phase 1: Mechanics & Test Speed-Ups (Zero File Moves)

#### 1.1 Optimize `grantAdminPermissions` and `grantTenantUserPermissions`
In `tests/Pest.php`:
- Replace `fake()->sentence()` with `'Permission '.$name`.
- Batch insert missing permissions and attach all permission IDs in a single query:
  ```php
  $group->permissions()->syncWithoutDetaching($permissionIds);
  ```
- Implement a static in-memory array `$permissionCache` to avoid duplicate `Permission::firstOrCreate` database roundtrips during a test run.

#### 1.2 Fix Fixture Waste in `RouteMiddlewareTest.php`
- Remove the full permission loop and admin creation from `beforeEach()`.
- Move admin creation and permission assignment exclusively inside `it('serves representative panel routes through the full HTTP stack')`.
- The remaining 5 route validation tests will run in <10ms instead of ~800ms each.

#### 1.3 Tune SQLite Testing Connection
In `config/database.php` (or `phpunit.xml` environment variables):
- Configure SQLite testing PRAGMAs:
  ```php
  'journal_mode' => 'OFF',
  'synchronous' => 'OFF',
  ```
- Eliminates rollback journal disk sync overhead for in-memory SQLite transactions.

#### 1.4 Mock Git Subprocesses in `GitUpdateServiceTest.php`
- Utilize `FakeProcessRunner` for remote git calls (`remoteBranches`, `remoteTags`, `fetch`) so tests execute in 1ms rather than 700ms.

---

### Phase 2: Legacy Pbx & Admin Livewire Consolidation

#### 2.1 Legacy `tests/Feature/Pbx` Consolidation (21 files → 7 files)
Currently, 7 domains have separate `ListTest.php`, `EditTest.php`, and `ServiceTest.php` across `tests/Feature/Pbx/Livewire` and `tests/Feature/Pbx/Services`. Consolidate each domain into a unified test file using `describe()` blocks:

| Domain | Files Merged | New Consolidated File | Test Count |
| :--- | :--- | :--- | :--- |
| **Destinations** | `DestinationsListTest` (5), `DestinationsEditTest` (6), `DestinationServiceTest` (5) | `tests/Feature/Pbx/DestinationsTest.php` | 16 tests |
| **Devices** | `DevicesListTest` (5), `DevicesEditTest` (7), `DeviceServiceTest` (6) | `tests/Feature/Pbx/DevicesTest.php` | 18 tests |
| **SipAccounts** | `SipAccountsListTest` (5), `SipAccountsEditTest` (7), `SipAccountServiceTest` (8) | `tests/Feature/Pbx/SipAccountsTest.php` | 20 tests |
| **AccessControls**| `AccessControlsListTest` (6), `AccessControlsEditTest` (6), `AccessControlServiceTest` (5) | `tests/Feature/Pbx/AccessControlsTest.php` | 17 tests |
| **FeatureCodes** | `FeatureCodesListTest` (6), `FeatureCodesEditTest` (6), `FeatureCodeServiceTest` (5) | `tests/Feature/Pbx/FeatureCodesTest.php` | 17 tests |
| **IvrMenus** | `IvrMenusListTest` (6), `IvrMenusEditTest` (7), `IvrMenuServiceTest` (6) | `tests/Feature/Pbx/IvrMenusTest.php` | 19 tests |
| **SipProfiles** | `SipProfilesListTest` (6), `SipProfilesEditTest` (10), `SipProfileServiceTest` (6) | `tests/Feature/Pbx/SipProfilesTest.php` | 22 tests |

#### 2.2 Core & Admin Livewire Consolidation (24 files → 14 files)
Merge paired CRUD List and Edit files in `tests/Feature/Livewire`:
- `AdminsListTest` (4) + `AdminsEditTest` (6) → `AdminsManagementTest.php` (10 tests)
- `UsersListTest` (5) + `UsersEditTest` (7) → `UsersManagementTest.php` (12 tests)
- `TenantsListTest` (7) + `TenantsEditTest` (9) → `TenantsManagementTest.php` (16 tests)
- `TenantDomainsListTest` (6) + `TenantDomainsEditTest` (7) → `TenantDomainsManagementTest.php` (13 tests)
- `GroupsListTest` (7) + `GroupsEditTest` (14) → `GroupsManagementTest.php` (21 tests)
- `AuthForgotPasswordTest` (4) + `AuthResetPasswordTest` (5) + `AuthRegisterTest` (5) → `AuthPasswordFlowTest.php` (14 tests)

---

### Phase 3: Module & Feature Test Consolidation (`tests/Feature/Modules/` & `tests/Feature/`)

#### 3.1 The Standard 2-File Pattern
For modules with standard CRUD, Livewire, and Isolation suites, merge 4–6 files into 2 cohesive files:

1. **`[Module]ComponentTest.php`** (~13–25 tests):
   - Merges: `[Module]ListTest.php`, `[Module]EditTest.php`, `[Module]PermissionTest.php` (and `BulkCreate` where applicable).
   - Groups tests via `describe('List Component')`, `describe('Edit Component')`, and `describe('Permissions')`.
2. **`[Module]ServiceAndIsolationTest.php`** (~12–24 tests):
   - Merges: `[Module]ServiceTest.php` and `[Module]TenantIsolationTest.php`.
   - Groups tests via `describe('Service Logic')` and `describe('Tenant Isolation')`.

**Target Modules for 2-File Pattern:**
- `Extensions` (6 files, 44 tests → 2 files: `ExtensionsComponentTest` [25], `ExtensionsServiceAndIsolationTest` [19])
- `Voicemails` (6 files, 36 tests → 2 files: `VoicemailsComponentTest` [18], `VoicemailsServiceAndIsolationTest` [18])
- `Dialplans` (5 files, 29 tests → 2 files: `DialplansComponentTest` [17], `DialplansServiceAndIsolationTest` [12])
- `Gateways` (5 files, 39 tests → 2 files: `GatewaysComponentTest` [20], `GatewaysServiceAndIsolationTest` [19])
- `InboundRoutes` (5 files, 31 tests → 2 files: `InboundRoutesComponentTest` [19], `InboundRoutesServiceAndIsolationTest` [12])
- `OutboundRoutes` (5 files, 41 tests → 2 files: `OutboundRoutesComponentTest` [22], `OutboundRoutesServiceAndIsolationTest` [19])
- `CallCenters` (4 files, 27 tests → 2 files: `CallCentersComponentTest` [13], `CallCentersServiceAndIsolationTest` [14])
- `Conferences` (4 files, 27 tests → 2 files: `ConferencesComponentTest` [13], `ConferencesServiceAndIsolationTest` [14])
- `RingGroups` (4 files, 26 tests → 2 files: `RingGroupsComponentTest` [14], `RingGroupsServiceAndIsolationTest` [12])
- `SipTrunks` (4 files, 27 tests → 2 files: `SipTrunksComponentTest` [15], `SipTrunksServiceAndIsolationTest` [12])
- `CallBroadcast` (4 files, 29 tests → 2 files: `CallBroadcastDispatchTest` [15], `CallBroadcastManagementTest` [14])
- `Backups` (5 files, 43 tests → 2 files: `BackupsManagementTest` [19], `BackupAndRestoreServiceTest` [24])

#### 3.2 FileStores Module Consolidation (9 files → 2 files, 42 tests)
Currently 9 files for 42 tests (~4.6 tests/file):
- `FileStoreUiAndStreamTest.php` (15 tests): merges `FileStoreUiTest` (9) + `MediaStreamControllerTest` (6)
- `FileStoreStorageServiceTest.php` (27 tests): merges `FileStoreServiceTest` (7), `MediaStorageServiceTest` (6), `SyncMediaArchiveTest` (5), `MediaAssetModelTest` (4), `MediaArchiveDestinationServiceTest` (3), `DeleteMediaAssetTest` (1), `ReconcileMediaAssetsCommandTest` (1).

#### 3.3 Absorb Single-Test Files into Existing Suites
- `VoicemailGreetingStorageTest.php` (1 test) → `VoicemailsServiceAndIsolationTest.php`
- `ConferenceGreetingStorageTest.php` (1 test) → `ConferenceCenterTest.php`
- `MusicOnHoldStorageTest.php` (1 test) → `MusicOnHoldTest.php`
- `IvrGreetingStorageTest.php` (1 test) → `IvrMenusTest.php`

#### 3.4 Feature Script & Telephony Cache Consolidation (New Suites)
Consolidate standalone script validation and XML/telephony caching suites added in recent features:
- `PbxCacheSweepScriptTest.php` (5 tests) + `PbxSippValidationScriptTest.php` (5 tests) → `tests/Feature/PbxScriptsValidationTest.php` (10 tests)
- `XmlHandlerCacheConfigTest.php` (6 tests) → merge into or co-locate with `tests/Feature/FreeSwitch/PbxXmlHandlerSmokeTest.php` or `XmlHandlerControllerTest.php`
- `RoutingCacheObserverTest.php` (7 tests, 30 assertions): Retain as dedicated suite in `tests/Feature/Observers/` covering multi-module model mutation cache invalidations.

---

### Phase 4: Security Module Consolidation (15 files → 7–8 files)

The Security module currently contains **15 files for 158 tests**:
- `SecurityListenersTest.php` (12 tests): merges `LogFailedLoginListenerTest` (6) + `LogFailedSipAuthListenerTest` (6)
- `SecurityConsoleCommandsTest.php` (17 tests): merges `SecurityCommandsTest` (7) + `SecurityVerifyCommandTest` (5) + `SecurityReconcileCommandTest` (5)
- `SecurityConfigAndFirewallTest.php` (31 tests): merges `SecurityConfigGeneratorTest` (16) + `FirewallSyncVerifierTest` (7) + `FirewallBanReconcilerTest` (8)
- `SecurityCoreLifecycleTest.php` (19 tests): merges `SecurityModelsAndMigrationsTest` (9) + `SecurityModuleTest` (6) + `SecurityBroadcastTest` (4)
- Retain as standalone:
  - `SecurityManagerLivewireTest.php` (39 tests)
  - `SecurityExecutorTest.php` (17 tests)
  - `SecurityBanServiceTest.php` (13 tests)
  - `LockoutGuardServiceTest.php` (10 tests)

---

## 3. Verification & Quality Gates

At every phase checkpoint, the following commands must be executed and confirmed before proceeding:

```bash
# 1. Clear caches
php artisan optimize:clear

# 2. Parallel run - must confirm 2,349 tests and 9,801+ assertions pass
php artisan test --parallel --compact

# 3. Verify zero test regression
# (Pest total test count must match exactly 2,349)

# 4. Run Dusk browser tests for UI/browser parity verification
php artisan dusk
```

---

## 4. Risk Analysis & Mitigations

| Risk | Mitigation |
| :--- | :--- |
| **Lost or skipped tests during merge** | Compare pre-merge and post-merge test lists using automated script; strict adherence to the 2,349 count gate. |
| **Namespace / fixture collision** | Wrap merged suites in Pest `describe('Section Name', function () { ... })` blocks with scoped `beforeEach` closures where needed. |
| **Worker starvation (stragglers)** | No consolidated file will exceed 30–35 tests, ensuring even worker workload distribution across CPU cores. |
| **Database state leakage between tests** | Maintain `LazilyRefreshDatabase` trait across all feature tests, ensuring transactions roll back cleanly after each test. |
