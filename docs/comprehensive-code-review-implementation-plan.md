# Comprehensive Code & UI Review Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remediate all Critical (`C1`–`C6`), High (`H1`–`H8`), Medium (`M1`–`M8`), Low (`L1`–`L6`), UI (`U1`–`U16`, `§2.4`), and SDD ledger hygiene (`§1.7`, `§1.8`) findings verified in the comprehensive code and UI review across six independently shippable increments (Phase 1, Phase 2, Phase 3A, Phase 3B, Phase 3C, Phase 4).

**Architecture:** Unify panel actor resolution (`PanelActor`), make Livewire action gating fail-closed across every `Modules\*` component including `Modules\Admin\*`, lock record identifiers via `#[Locked]`, restrict device provisioning templates to tenant-scoped view namespaces, and decouple permission DB syncs from the FreeSWITCH `mod_xml_curl` hot path. Layer topological manifest loading, compound-indexed list pagination, standardized Blade UI primitives (`<x-form-error>`, `<x-page-header>`), localized edit forms, push-compliant WebSocket health recovery, and decomposed module collaborators on top.

**Tech Stack:** PHP `^8.5` floor & runtime, Laravel `13`, Livewire `4`, Tailwind CSS `v4`, DaisyUI `5`, Pest `4`, Laravel Pint `1.32`, Laravel Reverb / Echo, FreeSWITCH `1.11` (`mod_xml_curl` & ESL).

**Spec:** Superseded and self-contained. The verified source review (`docs/comprehensive-code-and-ui-review.md` and its `-update.md` revision) was folded into this plan and removed from the working tree when this plan was committed. Every finding ID cited below is defined inline in the task that remediates it, and the Phase-1 `Review Focus` section carries the exploit detail needed to review the work — no task depends on an external review document.

## Global Constraints

- Active development targets branch `4.x` (`git branch --show-current`); there is no `main`/`master` branch, and zero `3.x`/`2.x` backward-compatibility shims or aliases may be introduced. Because `4.x` already has live production tags (`v4.0.0`–`v4.1.0`), preserve clean in-place upgrades via additive forward migrations. This remediation series targets a future **`v4.2.0`** release on `4.x` — **do NOT create git tags or run `git push`** until explicitly instructed by the user.
- Enforce a uniform PHP `8.5` compatibility floor (`"php": "^8.5"` in `composer.json`, `"php": ">=8.5"` across all 60 `app-modules/*/module.json` manifests, and `">=8.5"` in the `resources/schemas/module.json` example), matching `scripts/resources/config.sh` (`php_version=8.5` since `v4.0.0`), `composer.lock`, and `.github/workflows/tests.yml`. Native PHP 8.4/8.5 functions (such as `array_any()` in `TenantEslScoping`) are valid on `4.x`.
- Preserve the strict push-only real-time UI invariant enforced by `tests/Feature/Telephony/TelephonyPushBroadcastingTest.php`: zero `wire:poll` directives and zero manual refresh buttons on telephony or dashboard views; `wire:poll` remains restricted to `['app-modules/admin/resources/views/git-update.blade.php', 'app-modules/backups/resources/views/restore-archive.blade.php', 'app-modules/logs/resources/views/log-viewer.blade.php']`.
- Preserve the two-tier loading dimming standard: `wire:loading.class="opacity-40 pointer-events-none"` on `<tr>` table rows (Pattern 3, asserted by `SecurityManagerLivewireTest`) and `wire:loading.class="opacity-70 pointer-events-none"` on interactive toggle switches and tool triggers (Pattern 2).
- Binary feature state controls must use `toggle toggle-sm toggle-success` when ON and `toggle-neutral` (or default neutral) when OFF, paired with state adjective labels (`__('admin.enabled')` / `__('admin.disabled')`); `checkbox` is reserved strictly for multi-select lists; amber (`warning`) is reserved strictly for "Enabled but Idle / Unconfigured" per the 3-Tier Health Model.
- Built-in module translations follow Option A Native Path Cascading (`app-modules/<module>/lang/{en,es,fr}/admin.php` resolved via `__('admin.<entity>_*')`), carrying the collision-warning header, 100% key parity across `en`/`es`/`fr`, and zero key collisions with root `lang/{en,es,fr}/admin.php` or peer modules (`TranslationParityTest`).
- Live firewall safety: never run raw `nft add|insert|chain|delete|flush` commands against a live kernel; validate firewall code strictly via unit/feature tests with mocked executors or check-only `nft -c -f`.
- Standard Pest permission setup for panel tests: `$this->artisan('module:sync --only-local')` + `$this->seed(AdminSeeder::class)` (or `grantAdminPermissions()` / `grantTenantPermissions()` for restricted-subset tests).
- Per-task verification gate: run `php artisan optimize:clear`, `./vendor/bin/pint --dirty --test` (whenever PHP files change), and targeted `./vendor/bin/pest <SpecificTest.php>`. Run the full parallel suite (`php artisan test --compact --parallel`) at most once at the end of each phase.
- **Test Impact is mandatory.** Every task that changes Blade output, manifests, or shared helpers MUST list the existing tests whose assertions the change invalidates — `tests/Feature/Architecture/UiArchitectureTest.php`, `tests/Feature/Architecture/ModuleArchitectureTest.php`, `tests/Feature/Architecture/TranslationParityTest.php`, `tests/Feature/Telephony/TelephonyPushBroadcastingTest.php`, `tests/Feature/ModuleManifestTest.php`, `tests/Feature/Services/GitUpdatePipelineTest.php` — and amend them in the same commit. A task that turns an existing test red without updating it is incomplete, not done.
- **Do not create a second competing test file** for a convention already policed elsewhere. Extend the existing suite (`UiArchitectureTest`, `ModuleArchitectureTest`, `TranslationParityTest`, `ModuleManifestTest`) or explicitly retire the superseded assertion in the same commit. `tests/Feature/Architecture/SddLedgerHygieneTest.php` (Task 23) is the sole new architecture test file because no existing suite covers `.superpowers/sdd/`.

## Review Focus

1. **Impersonating Super Admin invoking `Modules\Admin\*` Livewire actions or bypassing tenant bounds (`M6` / `M8` / `C1`):** While impersonating a tenant user (`session('impersonation.original_admin_id')` set and `Auth::guard('web')->check()` true), `Auth::guard('admin')->user()` remains populated in session; actor resolution must return the impersonated `User` and deny `admin.*` actions with HTTP `403`, never falling back to the underlying `Admin` *(pinned to Task 1 & Task 2)*. **Scope correction:** `AdminAuthorize::panelActor()` (`:61-63`), `EnforcePanelLivewireActionPermissions::authenticate()` (`:149-151`), and `BaseEditComponent::isAdminGuard()` already consult impersonation state today (as do `BaseListComponent`, `TenantMutationGuard`, `CdrDetail`, and `OperatorPanelIndex` via duplicate helpers). The **five live impersonation defects** are `OperationalControlFeedback::authorizeAction()`, `ProfileEdit::currentUser()`, `ScopeTenant::verifyTenantAccess()` (`app/Http/Middleware/ScopeTenant.php:50-54`), `NotificationsList` (`app-modules/admin/src/Livewire/NotificationsList.php:74,100`), and `SecurityManager` (`app-modules/security/src/Livewire/SecurityManager.php:247`). Task 1 fixes all five defects and consolidates the duplicate `isAdminGuard()` helpers onto `PanelActor`.
2. **`Super Administrators` group escalation by restricted admins (`C1` / `C2`):** Even with `#[Locked] public ?int $adminId = null`, an admin holding `admin.users.create` or `admin.users.update` who is *not* in `Super Administrators` could submit `selectedGroupIds` containing the `Super Administrators` group ID (`tenant_id = null`) or edit an existing Super Admin account; `AdminsEdit::save()` and `GroupsEdit::save()` must reject non-super-admin mutation of the `Super Administrators` group or its members *(pinned to Task 3)*.
3. **Existing `.blade.php` template suffix compatibility vs path traversal / log poisoning (`C3` / `M7`):** Seeders, factories (`ProvisionTemplateFactory`), and existing tests store `'grandstream/default.blade.php'` and `'grandstream/gxp2170/default.blade.php'`; normalization must accept 2–3 slug segments with an optional trailing `.blade.php` suffix while rejecting `..`, leading `/`, arbitrary `.php`/`.log`/`.env` paths (`storage/logs/laravel.log`, `../../.env`), and resolving strictly via `view('provision::templates.'.$viewName)` *(pinned to Task 4)*.
4. **Cold-hydration ESL control calls with empty or cross-tenant identifiers (`C6`):** An operator with `active-conferences.kick` or `call-center-active.control` can POST directly to `/livewire/update` on a cold/stale snapshot or supply another tenant's extension/room name (`3001`, `1001@tenant-b.test`); control actions must compute fresh tenant-scoped allowlists on the server via `TenantEslScoping` before calling `FreeSwitchControlService`, and empty allowlists for tenant-scoped actors must deny every target *(pinned to Task 7)*.
5. **Stale SIP directory credentials after raising `xml_handler.cache_ttl` from `5` to `60` seconds (`H8`):** `DialplanXmlBuilder` embeds `RoutingCacheVersion::get($tenantId)` in its cache keys, whereas `DirectoryXmlBuilder::directoryCacheKey()` currently omits `RoutingCacheVersion` and `ExtensionSetting` is not observed by `RoutingCacheObserver`; raising TTL without embedding `RoutingCacheVersion` in directory cache keys and observing `ExtensionSetting` leaves rotated SIP passwords or disabled SIP accounts valid for 60 seconds *(pinned to Task 8)*.

---

## Scope & Phase Decomposition

Because the review spans four distinct engineering domains (security/authz, runtime performance & manifests, 445-view UI polish, and god-class refactoring), the plan is structured into **four self-contained, independently shippable phases**. Each phase leaves `4.x` green and releasable on its own; Phase 1 should be executed and merged/verified before starting Phases 2–4:

- **Phase 1 (Tasks 1–7) — Immediate Security, Authorization & Hot-Path Fixes:** `C1`, `C2`, `C3`, `C4`, `C5`, `C6`, `H1`, `M2`, `M6`, `M7`, `M8`, `L1`, `L5`, `L6`.
- **Phase 2 (Tasks 8–13) — Performance, Manifest Integrity & Tenancy Hardening:** `H2`, `H3`, `H4`, `H5`, `H6`, `H7`, `H8`, `M1`, `M5`, `L2`, `L3`, `§5f`.
- **Phase 3A (Tasks 14–15) — Shared UI Primitives & Existing-Test Reconciliation:** `U4`, `U6`, `U8`, `§2.4`. Ships `<x-form-error>` and `<x-page-header>` and MUST update the pre-existing `UiArchitectureTest` heading assertion in the same commit (see Task 15 Test Impact).
- **Phase 3B (Tasks 16–17) — Control Semantics, Dimming & Accessibility:** `U1`, `U2`, `U5`, `U7`, `U9`–`U13`, `§2.4`.
- **Phase 3C (Tasks 18–19) — Localization Sweep & Realtime Health:** `U3`, `U14`. Runs `npm run build`, so it closes the UI phase.
- **Phase 4 (Tasks 20–23) — Architectural Decomposition & SDD Hygiene:** `M3`, `M4`, `U16`, `L4`, `§1.7`, `§1.8`.

Phases 3A/3B/3C each carry their own verification gate and commit series. Phase 3 is deliberately NOT one gate: it spans ~700 mechanical Blade edits across 445 views while three existing architecture tests police the same files, so a single gate would mix component design risk with bulk-migration risk.

## File Structure

### Phase 1 — Security, Authorization & Hot-Path Fixes
- **Create:** `app/Support/PanelActor.php` — single source of truth resolving the active unified-panel `Admin|User|null` and impersonation state.
- **Create:** `tests/Unit/Support/PanelActorTest.php` — unit coverage for admin, tenant user, anonymous, and impersonating-admin actor resolution.
- **Modify:** `app/Http/Middleware/AdminAuthorize.php`, `app/Http/Middleware/EnforcePanelLivewireActionPermissions.php`, `app/Http/Middleware/ScopeTenant.php`, `app/Support/BaseEditComponent.php`, `app/Support/BaseListComponent.php`, `app/Support/TenantMutationGuard.php`, `app/Support/OperationalControlFeedback.php`, `app-modules/admin/src/Livewire/ProfileEdit.php`, `app-modules/admin/src/Livewire/NotificationsList.php`, `app-modules/security/src/Livewire/SecurityManager.php`, `app-modules/cdr/src/Livewire/CdrDetail.php`, `app-modules/operator-panel/src/Livewire/OperatorPanelIndex.php` — delegate all actor/impersonation checks to `PanelActor`.
- **Create:** `database/migrations/2026_10_28_000001_grant_split_admin_mutation_permissions.php` — additive forward migration granting the 4 newly split `admin.*` permissions to existing `Super Administrators` (and `admin.queue.retry` to `Administrators`) on in-place `4.x` updates, with symmetric `down()` cleanup.
- **Modify:** `app/Support/LivewireActionPermissions.php`, `app-modules/admin/src/Providers/ModuleServiceProvider.php`, `database/seeders/AdminSeeder.php`, `app-modules/admin/src/Livewire/*.php` (20 components), `app-modules/tenant/src/Livewire/Dashboard.php`, `app-modules/call-broadcast/src/Livewire/BroadcastCreate.php`, `app-modules/event-rate-limits/src/Livewire/EventRateLimitsSettings.php`, `tests/Feature/LivewireActionPermissionTest.php`, and the 10 impacted feature test files (`tests/Feature/Livewire/{GitUpdateTest,SettingsEditTest,UserManagementTest,GroupManagementTest,TenantManagementTest,TenantDomainManagementTest,PermissionsListTest,NotificationsListTest}.php` and `app-modules/admin/tests/{NotificationsListTest,QueueStatusTest,MonitoringTest}.php`) — exempt guest `Modules\Auth\*`, remove `Modules\Admin\*` exemption, support `[['*']]` (for `ProfileEdit` and `Modules\Tenant\Livewire\Dashboard`) and `'@tenant-user'` (for shared `NotificationsList`), fail closed on unmapped actions, and wire explicit `livewireActionAbilities()` maps.
- **Modify:** All 45 `*Edit.php` Livewire components across `app-modules/*/src/Livewire/`, `app-modules/provision/tests/ProvisionTest.php` (seed permissions in `beforeEach`), `tests/Feature/Architecture/ModuleArchitectureTest.php` (extend with `#[Locked]` reflection check), plus create `tests/Feature/Security/EditComponentIdorProtectionTest.php` — lock primary record ID/UUID properties and re-assert create/update permission + tenant/super-admin ownership inside `save()`.
- **Modify:** `app-modules/provision/src/Services/ProvisionServiceInterface.php`, `app-modules/provision/src/Services/ProvisionService.php`, `app-modules/provision/src/Livewire/TemplateEdit.php`, `app-modules/provision/tests/ProvisionTest.php` — enforce template slug allowlist, `view('provision::templates.*')`-only rendering, and tenant-scoped template resolution.
- **Create:** `app/Console/Commands/SyncPermissionsCommand.php`, `app/Support/PermissionSyncPolicy.php`, `tests/Unit/Support/PermissionSyncPolicyTest.php`, `tests/Feature/Performance/XmlHandlerZeroSyncQueriesTest.php`.
- **Modify:** `app/Providers/AppServiceProvider.php`, `app/Services/PermissionService.php`, `app-modules/admin/src/Livewire/PermissionsList.php`, `app-modules/admin/src/Livewire/GroupsEdit.php`, `app/Services/GitUpdateService.php` — hoist `PermissionService::syncToDatabase()` out of HTTP requests behind a cached manifest hash + Artisan command, route the two remaining admin-page `mount()` syncs through `syncIfStale()`, memoize `PermissionService::all()` per request, and skip `Gate::define` on `api/v1/xml-handler*`.
- **Modify:** `composer.json`, all 60 `app-modules/*/module.json` manifests, `resources/schemas/module.json`, `app-modules/security/src/Services/Firewall/SecurityConfigGenerator.php`, `tests/Feature/Architecture/ModuleArchitectureTest.php` — bump declared PHP floor uniformly to `^8.5` / `>=8.5` (preserving native `array_any()` in `TenantEslScoping`) and delete dead `writeActive()`.
- **Modify:** `app/Services/FreeSwitchControlService.php`, `app-modules/active-conferences/src/Livewire/ActiveConferencesList.php`, `app-modules/call-center-active/src/Livewire/CallCenterActiveList.php`, `app-modules/active-calls/src/Livewire/ActiveCallsList.php`, `tests/Feature/Telephony/CrossTenantEslControlTest.php` (create) — enforce server-computed tenant allowlists on conference, call-center, and active-call ESL control actions.

### Phase 2 — Performance, Manifests & Core Tenancy Hardening
- **Modify:** `config/freeswitch.php`, `app/Services/Xml/DirectoryXmlBuilder.php`, `app/Providers/AppServiceProvider.php`, `app/Observers/RoutingCacheObserver.php`, `tests/Feature/Observers/RoutingCacheObserverTest.php` — raise `xml_handler.cache_ttl` default to `60`s, include `RoutingCacheVersion::get((int) $tenantId)` in directory cache keys, and observe `ExtensionSetting`.
- **Create:** `database/migrations/2026_10_28_000002_add_tenant_created_at_indexes_to_high_volume_tables.php` — add compound `(tenant_id, created_at)` indexes to `call_recordings`, `media_assets`, and `voicemail_messages` / `xml_cdrs` where missing.
- **Modify:** `app-modules/call-recordings/src/Livewire/CallRecordingsList.php` (+ blade view), top 9 unbounded list components (`voicemail-messages`, `file-stores`, `recordings`, `fax` [`FaxInbox.php`], `devices`, `extensions`, `sip-accounts`, `inbound-routes`, `outbound-routes`), and any impacted assertions across the 7 module tests calling `assertViewHas(...)`.
- **Create:** `app/Support/ModuleDependencySorter.php` — Kahn topological sorter over the existing `requirements.modules` declarations, used by both runtime registration and the manifest integrity test. Extend the existing `tests/Feature/ModuleManifestTest.php` with schema-shape, unique-priority, `required ⇒ protected`, and dependency-order assertions (no new test file).
- **Modify:** `app/Providers/ModuleServiceProvider.php`, `app/Services/ModuleLifecycleService.php`, and all 60 `app-modules/*/module.json` manifests — enforce topological provider registration driven by `requirements.modules`, unique priorities, schema-compliant `requirements.packages`, and `required ⇒ protected`.
- **Modify:** 36 Livewire form components across `app-modules/*/src/Livewire/` (**33** `*Edit.php` files + `BroadcastCreate.php`, `ExtensionsBulkCreate.php`, `FaxSend.php`) + extend `tests/Feature/Architecture/ModuleArchitectureTest.php` — standardize `'tenant_id' => $this->resolveTenantId()`.
- **Create:** `app/Exceptions/TenantContextMissingException.php`, `tests/Feature/Tenancy/BelongsToTenantFailClosedTest.php`; **Modify:** `app/Traits/BelongsToTenant.php`, `app/Services/TenantContext.php`, `tests/Feature/Tenancy/CrossTenantIsolationTest.php`, `tests/Feature/Services/TenantContextTest.php` — fail-closed zero-row read scope (`whereRaw('1 = 0')`) when unconfigured on read paths, strict `TenantContextMissingException` on write paths, explicit `allowSingleTenantFallback` parameter on `TenantContext::resolve()`, and `TenantContext::assertResolved()`.
- **Modify:** `app/Contracts/ProcessRunner.php`, `app/Support/SystemProcessRunner.php`, `app/Services/GitUpdateService.php`, `tests/Feature/Services/FakeProcessRunner.php`, `tests/Feature/Services/GitUpdateServiceTest.php`, `tests/Feature/Services/GitUpdatePipelineTest.php` — migrate `SystemProcessRunner` from `exec(string)` to Symfony `Process(array $argv)` with zero shell interpolation while preserving the step timeout, env assembly, and merged stdout/stderr that `GitUpdateService` parses today.

### Phase 3 — UI Consistency, Localization & Accessibility Sweep
- **Create:** `resources/views/components/form-error.blade.php`, `resources/views/components/page-header.blade.php`; extend the existing `tests/Feature/Architecture/UiArchitectureTest.php` (do NOT create a parallel conventions file — see Task 14).
- **Modify:** 362 `@error` occurrences across 64 `app-modules/*/resources/views/**/*.blade.php` files → `<x-form-error name="..." />`; 127 page header blocks → `<x-page-header ... />`; `tests/Feature/Architecture/UiArchitectureTest.php` (heading count assertion must move from raw `<h1|h2 text-2xl>` markup to `<x-page-header` usages in the same commit).
- **Modify:** `app-modules/admin/resources/views/settings-edit.blade.php`, `resources/views/layouts/app.blade.php`, `resources/views/components/dashboard/stats.blade.php` — bring settings workbench and layout quick wins onto standard tokens/tooltips/sizing.
- **Modify:** ~52 binary-state Blade views (`extensions-edit.blade.php`, `modules-list.blade.php`, etc.) — unify binary feature flags onto `toggle toggle-sm toggle-success` (ON) / `toggle-neutral` (OFF) and reserve `warning` strictly for Idle.
- **Modify:** `geo-blocking.blade.php`, `protection-settings.blade.php`, `firewall-rules.blade.php`, `block-allow.blade.php`, and 55 un-navigated `route('panel.*')` links (109 internal panel links scanned in total) — normalize loading dimming, scrims, redundant badge classes, `wire:navigate`, secondary text scale, `font-mono` badges, and `aria-label`s.
- **Modify:** `lang/{en,es,fr}/admin.php` and ~50 `app-modules/*/resources/views/*-edit.blade.php` + `app-modules/*/lang/{en,es,fr}/admin.php` — deduplicate root keys and localize all hardcoded English edit/create form copy across `en`, `es`, and `fr`.
- **Create:** `resources/views/components/websocket-health-banner.blade.php`; **Modify:** `resources/js/echo.js` (or `resources/js/app.js`), `OperatorPanelIndex.php`, and the 4 other telephony live-list components + views — bind Echo/Pusher `state_change` events to display a disconnect warning banner and dispatch a one-shot Livewire resync event on reconnect (zero `wire:poll`).

### Phase 4 — Architectural Decomposition & SDD Hygiene
- **Create:** `app/Support/Xml/XmlWriter.php`, `tests/Unit/Support/XmlWriterTest.php`; **Modify:** `app/Services/Xml/DialplanXmlBuilder.php`, `app/Services/Xml/DirectoryXmlBuilder.php`.
- **Create:** `app/Services/Modules/ModuleManifestLoader.php`, `app/Services/Modules/ModuleRouteRegistrar.php`, `app/Services/Modules/ModulePermissionRegistrar.php`, `app/Services/Modules/ModuleProtectionPolicy.php`; **Modify:** `app/Providers/ModuleServiceProvider.php` (498 lines — discovery/priority orchestrator) AND `app/Support/ModuleServiceProvider.php` (643 lines — the actual god class holding views, translations, migrations, permissions, menu, and collision detection), plus `app/Services/ModuleLifecycleService.php`.
- **Create:** `resources/views/components/tabs.blade.php` + extracted Blade partials for the 6 views exceeding 300 lines (`certificate-manager.blade.php`, `firewall-rules.blade.php`, `email-connector-edit.blade.php`, `git-update.blade.php`, `attackers.blade.php`, `block-allow.blade.php`).
- **Create:** `tests/Feature/Architecture/SddLedgerHygieneTest.php`; **Modify:** `AGENTS.md`, `CHANGELOG.md`, and local untracked `.superpowers/sdd/*` ledgers (`/.superpowers` is gitignored, so `SddLedgerHygieneTest.php` skips when `.superpowers/sdd/` is absent in CI and exempts active in-flight SDD workspaces whose plan file exists on disk).

---

## Phase 1 — Immediate Security, Authorization & Hot-Path Fixes

### Task 1: Unified `PanelActor` & Impersonation-Safe Authz Resolver (`M6`, `M8`)

**Files:**
- Create: `app/Support/PanelActor.php`
- Modify: `app/Http/Middleware/AdminAuthorize.php:56-66`
- Modify: `app/Http/Middleware/EnforcePanelLivewireActionPermissions.php:139-160`
- Modify: `app/Http/Middleware/ScopeTenant.php:50-54`
- Modify: `app/Support/BaseEditComponent.php:40-55`
- Modify: `app/Support/BaseListComponent.php:33-36`
- Modify: `app/Support/TenantMutationGuard.php:45`
- Modify: `app/Support/OperationalControlFeedback.php:22-31`
- Modify: `app-modules/admin/src/Livewire/ProfileEdit.php:120-127`
- Modify: `app-modules/admin/src/Livewire/NotificationsList.php:74,100`
- Modify: `app-modules/security/src/Livewire/SecurityManager.php:247`
- Modify: `app-modules/cdr/src/Livewire/CdrDetail.php:51`
- Modify: `app-modules/operator-panel/src/Livewire/OperatorPanelIndex.php:165`
- Test: `tests/Unit/Support/PanelActorTest.php`

**Interfaces:**
- Produces:
  - `App\Support\PanelActor::resolve(): Admin|User|null`
  - `App\Support\PanelActor::isImpersonating(): bool`
  - `App\Support\PanelActor::isAdmin(): bool` (true only when resolved actor is `Admin` and `! isImpersonating()`)
  - `App\Support\PanelActor::isSuperAdmin(): bool` (true only when `isAdmin()` and actor belongs to group `'Super Administrators'` with `tenant_id === null`)
  - `App\Support\PanelActor::can(string $ability): bool`

- [x] **Step 1: Write the failing unit & impersonation boundary tests in `tests/Unit/Support/PanelActorTest.php`**

```php
it('resolves the impersonated tenant user instead of the underlying admin when impersonation session is active', function () {
    $admin = Admin::factory()->create(['enabled' => true]);
    $tenant = Tenant::factory()->create();
    $user = tenantUser($tenant);

    $this->actingAs($admin, 'admin');
    $this->actingAs($user, 'web');
    session()->put('impersonation.original_admin_id', $admin->id);

    expect(PanelActor::isImpersonating())->toBeTrue()
        ->and(PanelActor::resolve()?->getAuthIdentifier())->toBe($user->id)
        ->and(PanelActor::resolve())->toBeInstanceOf(User::class)
        ->and(PanelActor::isAdmin())->toBeFalse()
        ->and(PanelActor::can('admin.users.view'))->toBeFalse();
});

it('resolves the admin actor when not impersonating and detects Super Administrators membership', function () {
    $this->seed(\Database\Seeders\AdminSeeder::class);
    $superGroup = Group::where('name', 'Super Administrators')->whereNull('tenant_id')->firstOrFail();
    $admin = Admin::factory()->create(['enabled' => true]);
    $admin->groups()->attach($superGroup->id);

    $this->actingAs($admin, 'admin');

    expect(PanelActor::resolve())->toBeInstanceOf(Admin::class)
        ->and(PanelActor::isAdmin())->toBeTrue()
        ->and(PanelActor::isSuperAdmin())->toBeTrue();
});
```

Also add impersonation regression assertions covering `ScopeTenant` (an impersonating admin with `selected_tenant_id` pointing at a tenant the impersonated `User` does NOT belong to must be denied/reset rather than allowed by `Auth::guard('admin')->check()`), `NotificationsList` (returns the impersonated `User`'s notifications rather than the underlying `Admin`'s), and `ProfileEdit::currentUser()`.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Support/PanelActorTest.php`
Expected: FAIL with `Class "App\Support\PanelActor" not found`.

- [x] **Step 3: Implement `App\Support\PanelActor` and consolidate all actor/impersonation resolvers**

Implement `PanelActor` checking `session()->has('impersonation.original_admin_id') && Auth::guard('web')->check()` **first** (returning `Auth::guard('web')->user()`), otherwise `Auth::guard('admin')->user() ?? Auth::guard('web')->user()`.
1. **Fix the five live impersonation defects:** delegate `OperationalControlFeedback::authorizeAction()`, `ProfileEdit::currentUser()`, `ScopeTenant::verifyTenantAccess()` (`ScopeTenant.php:50-54`), `NotificationsList` (`NotificationsList.php:74,100`), and `SecurityManager` (`SecurityManager.php:247`) to `PanelActor` so impersonated sessions never bypass tenant membership checks, read/delete underlying admin notifications, or evaluate permissions/profile mutations against the underlying `Admin`.
2. **Behaviour-preserving consolidation:** delegate `AdminAuthorize::panelActor()`, `EnforcePanelLivewireActionPermissions::authenticate()`, and the duplicate `isAdminGuard()` helpers in `BaseEditComponent`, `BaseListComponent`, `TenantMutationGuard`, `CdrDetail`, and `OperatorPanelIndex` to `PanelActor` so one implementation governs the entire panel. Their existing tests must keep passing unmodified.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Unit/Support/PanelActorTest.php tests/Feature/LivewireActionPermissionTest.php tests/Feature/Middleware/ScopeTenantTest.php app-modules/admin/tests/NotificationsListTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add app/Support/PanelActor.php app/Http/Middleware/AdminAuthorize.php app/Http/Middleware/EnforcePanelLivewireActionPermissions.php app/Http/Middleware/ScopeTenant.php app/Support/BaseEditComponent.php app/Support/BaseListComponent.php app/Support/TenantMutationGuard.php app/Support/OperationalControlFeedback.php app-modules/admin/src/Livewire/ProfileEdit.php app-modules/admin/src/Livewire/NotificationsList.php app-modules/security/src/Livewire/SecurityManager.php app-modules/cdr/src/Livewire/CdrDetail.php app-modules/operator-panel/src/Livewire/OperatorPanelIndex.php tests/Unit/Support/PanelActorTest.php
git commit -m "security(auth): unify PanelActor resolver across middleware, edit/list forms, and control traits"
```

---

### Task 2: Fail-Closed `LivewireActionPermissions` & Admin Module Action Gate (`C1`, `H1`)

**Files:**
- Create: `database/migrations/2026_10_28_000001_grant_split_admin_mutation_permissions.php`
- Modify: `app/Http/Middleware/EnforcePanelLivewireActionPermissions.php:122-180`
- Modify: `app/Support/LivewireActionPermissions.php:45-142`
- Modify: `app-modules/admin/src/Providers/ModuleServiceProvider.php:225-260`
- Modify: `database/seeders/AdminSeeder.php:49-56`
- Modify: `app-modules/admin/src/Livewire/*.php` (all 20 classes, including `QueueStatus::authorizeRetry()` and `Monitoring` retry check)
- Modify: `app-modules/tenant/src/Livewire/Dashboard.php`
- Modify: `app-modules/call-broadcast/src/Livewire/BroadcastCreate.php`
- Modify: `app-modules/event-rate-limits/src/Livewire/EventRateLimitsSettings.php`
- Modify: `tests/Feature/LivewireActionPermissionTest.php`
- Modify (Test Impact — 11 existing test files):
  - `tests/Feature/Livewire/GitUpdateTest.php`
  - `tests/Feature/Livewire/SettingsEditTest.php`
  - `tests/Feature/Livewire/UserManagementTest.php`
  - `tests/Feature/Livewire/GroupManagementTest.php`
  - `tests/Feature/Livewire/TenantManagementTest.php`
  - `tests/Feature/Livewire/TenantDomainManagementTest.php`
  - `tests/Feature/Livewire/PermissionsListTest.php`
  - `tests/Feature/Livewire/NotificationsListTest.php`
  - `app-modules/admin/tests/NotificationsListTest.php`
  - `app-modules/admin/tests/QueueStatusTest.php`
  - `app-modules/admin/tests/MonitoringTest.php`

**Test Impact:** Removing the `Modules\Admin\` exemption intercepts `/livewire/update` requests *before* component methods execute. Every existing `Modules\Admin\*` feature test that creates a bare `Admin::factory()->create()` without seeding permissions (files 1–8 and 10–11 above) will fail with `403` until updated to seed `AdminSeeder` / grant the specific `admin.*` permission, and unauthorized-action tests in `app-modules/admin/tests/NotificationsListTest.php:108-112` and `QueueStatusTest.php:111-114` now receive middleware `assertForbidden()` rather than component-level `$actionError`. In `PermissionsListTest.php`, tests that call `app(PermissionService::class)->reset()` must re-register `admin.permissions.view` and grant it to `$this->admin` before mounting `PermissionsList`.

**Interfaces:**
- Consumes: `PanelActor::resolve()`, `PanelActor::can(string $ability): bool`
- Produces:
  - New admin permissions registered in `Modules\Admin\Providers\ModuleServiceProvider` (and granted in-place to existing `4.x` `Super Administrators` via `2026_10_28_000001_grant_split_admin_mutation_permissions.php`):
    - `'admin.settings.update' => 'Create and edit application settings'`
    - `'admin.settings.delete' => 'Delete application settings'`
    - `'admin.git-update.execute' => 'Fetch remote refs and execute git updates'`
    - `'admin.queue.retry' => 'Retry failed queue jobs'`
  - **Guest exemption (`Modules\Auth\*`):** `Modules\Auth\Livewire\{ForgotPassword, Register, ResetPassword}` are unauthenticated guest flows whose module registers 0 permissions; `EnforcePanelLivewireActionPermissions::resolveGatedComponent()` explicitly exempts `str_starts_with($class, 'Modules\\Auth\\')`.
  - `public static function livewireActionAbilities(): array` on admin & non-standard components, preserving the **existing nested ability-group contract** already implemented by `Modules\Extensions\Livewire\ExtensionsBulkCreate` (`['save' => [['extensions.create']]]`): `string $method => array<int, array<int, string>> $requiredAbilityGroups` — every group must be satisfied, any single ability inside a group is sufficient:
    - Wildcard token `[['*']]` (any authenticated `PanelActor`) is permitted **strictly** on `Modules\Admin\Livewire\ProfileEdit` and `Modules\Tenant\Livewire\Dashboard` (`Modules\Tenant` registers 0 permissions and renders for any authenticated tenant user).
    - Actor token `'@tenant-user'` (satisfied when `$actor instanceof \App\Models\User`) is permitted **strictly** on `Modules\Admin\Livewire\NotificationsList`, which serves both `Admin` (via `admin.notifications.view` / `admin.notifications.delete`) and tenant `User` self-notifications (`User::getPermissionNames()` strips all `admin.%` strings): `''`, `markAsRead`, `markAllAsRead`, and pagination helpers map to `[['admin.notifications.view', '@tenant-user']]`, while `deleteNotification` maps to `[['admin.notifications.delete', '@tenant-user']]`.
  - `LivewireActionPermissions::abilitiesFor(string $componentClass, string $method): array<int, array<int, string>>` — returns the fail-closed sentinel **`[['__unmapped_deny__']]`** (nested, matching the declared return type) whenever a gated `Modules\*` component method is neither an allowlisted UI/read helper nor mapped to at least one registered permission.

- [x] **Step 1: Write the failing tests in `tests/Feature/LivewireActionPermissionTest.php`**

Add tests covering:
1. Guest unauthenticated `POST /livewire/update` to `Modules\Auth\Livewire\ForgotPassword` is NOT blocked with 401/403 by `EnforcePanelLivewireActionPermissions`.
2. Authenticated tenant `User` (with 0 permissions) mounting/updating `Modules\Tenant\Livewire\Dashboard` and marking/deleting their own notification on `Modules\Admin\Livewire\NotificationsList` succeeds, while an `Admin` without `admin.notifications.delete` calling `NotificationsList::deleteNotification` receives `assertForbidden()`.
3. Admin with only `admin.settings.view` calling `SettingsEdit::saveSetting` or `deleteSetting` via `postLivewireUpdate()` → `assertForbidden()`.
4. Admin with only `admin.git-update.view` calling `GitUpdate::updateApp` or `fetch` via `postLivewireUpdate()` → `assertForbidden()`.
5. Impersonating Super Admin (`session('impersonation.original_admin_id')` active as tenant `User`) posting an admin snapshot (`AdminsEdit::save`, `SettingsEdit::saveSetting`) → `assertForbidden()` *(Review Focus #1)*.
6. View-only user calling `BroadcastCreate::save` or `EventRateLimitsSettings::save` → `assertForbidden()`.
7. Exhaustive reflection test: iterate every Livewire component class under `app-modules/*/src/Livewire/**/*.php` **excluding `Modules\Auth\Livewire\*`** and every public action method (excluding lifecycle hooks `boot`, `mount`, `render`, `hydrate*`, `updating*`, `updated*`), asserting `LivewireActionPermissions::abilitiesFor($class, $method)` is non-empty, never equals `[['__unmapped_deny__']]`, and every returned ability exists in `app(PermissionService::class)->all()` — except `'*'` (allowed strictly on `ProfileEdit` and `Modules\Tenant\Livewire\Dashboard`) and `'@tenant-user'` (allowed strictly on `NotificationsList`).

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/LivewireActionPermissionTest.php`
Expected: FAIL (`SettingsEdit::saveSetting`, `GitUpdate::updateApp`, and `BroadcastCreate::save` return 200 instead of 403).

- [x] **Step 3: Remove the `Modules\Admin\` exemption, fail closed in `LivewireActionPermissions`, add the `4.x` upgrade migration, and wire ability maps + affected tests**

1. In `EnforcePanelLivewireActionPermissions::resolveGatedComponent()`, replace `str_starts_with($class, 'Modules\\Admin\\')` with `str_starts_with($class, 'Modules\\Auth\\')` so guest auth components stay open while all panel `Modules\*` (including `Modules\Admin\*` and `Modules\Tenant\*`) are gated. In `assertAbilities()`, handle `'*'` (any authenticated `PanelActor`), `'@tenant-user'` (`$actor instanceof \App\Models\User`), and throw `AuthorizationException` whenever a group is `['__unmapped_deny__']`.
2. In `LivewireActionPermissions`, extend edit-verb fallback to check `[$module.'.edit', $module.'.update', $module.'.create', $module.'.manage']` and apply `save*` handling to `*Create` and `*Settings` classes as well as `*Edit`. Replace the empty-array fallback in `viewGroup()` with `[['__unmapped_deny__']]`.
3. Register the 4 new permissions in `Modules\Admin\Providers\ModuleServiceProvider::registerPermissions()`. Exclude `admin.git-update.execute`, `admin.settings.update`, and `admin.settings.delete` from the non-super `Administrators` group in `AdminSeeder` (leaving `admin.queue.retry` granted to both `Super Administrators` and `Administrators`), and change the `admin.git-update.view` description from `"View and trigger git updates"` to `"View git update status"`.
4. Create `database/migrations/2026_10_28_000001_grant_split_admin_mutation_permissions.php` following the `2026_10_18_000002_grant_module_manage_permission_to_super_admins.php` pattern: `firstOrCreate` the 4 permissions, attach all 4 to `Group::where('name', 'Super Administrators')->whereNull('tenant_id')` and `admin.queue.retry` to `Group::where('name', 'Administrators')->whereNull('tenant_id')`, and symmetrically detach + delete those 4 rows in `down()`.
5. Add explicit `livewireActionAbilities()` maps across all 20 `Modules\Admin\Livewire\*` components plus `Modules\Tenant\Livewire\Dashboard`, `BroadcastCreate`, and `EventRateLimitsSettings`. Update `QueueStatus::authorizeRetry()` (`QueueStatus.php:135`) and `Monitoring` retry authorization to `PanelActor::can('admin.queue.retry')`, and add belt-and-braces `abort_unless(PanelActor::can(...), 403)` inside `AdminsEdit::save()`, `UsersEdit::save()`, `SettingsEdit::{saveSetting,deleteSetting}()`, and `GitUpdate::{fetch,updateApp}()`.
6. Update all 11 existing feature test files listed under `Files` so authorized test setups seed/grant the required `admin.*` permissions and unauthorized Livewire call assertions expect HTTP `403` (`assertForbidden()`).

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Feature/LivewireActionPermissionTest.php tests/Feature/Livewire/ app-modules/admin/tests/ app-modules/auth/tests/ app-modules/tenant/tests/`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add database/migrations/2026_10_28_000001_grant_split_admin_mutation_permissions.php app/Http/Middleware/EnforcePanelLivewireActionPermissions.php app/Support/LivewireActionPermissions.php app-modules/admin/ app-modules/tenant/src/Livewire/Dashboard.php database/seeders/AdminSeeder.php app-modules/call-broadcast/src/Livewire/BroadcastCreate.php app-modules/event-rate-limits/src/Livewire/EventRateLimitsSettings.php tests/Feature/LivewireActionPermissionTest.php tests/Feature/Livewire/
git commit -m "security(livewire): enforce fail-closed action permissions across admin and panel modules"
```

---

### Task 3: `#[Locked]` Record Identifiers & `save()` Ownership Re-Assertion (`C2`)

**Files:**
- Modify: All 45 `*Edit.php` components across `app-modules/*/src/Livewire/*Edit.php` (measured count: 45 files; **43** hold a primary record ID/UUID property that receives `#[Locked]`, while `ProfileEdit` and `EmailConnectorEdit` edit singleton/self state and carry no record-ID property)
- Modify: `tests/Feature/Architecture/ModuleArchitectureTest.php` (append `#[Locked]` reflection check — do NOT create a separate `EditComponentLockedIdsTest.php`)
- Modify (Test Impact): `app-modules/provision/tests/ProvisionTest.php` (`beforeEach` creates a bare `Admin::factory()->create()`; seed `AdminSeeder` and attach `Super Administrators` so `TemplateEdit::save()` passes `save()` permission re-assertion)
- Create: `tests/Feature/Security/EditComponentIdorProtectionTest.php`

> **Component-count & property-name breakdown (settled).** The repository contains **45** `*Edit.php` components: **37** extend `BaseEditComponent` and **8** extend plain `Livewire\Component` (the seven admin edits plus `EmailConnectorEdit`). Of those 45:
> - **2** have no record-ID property (`Modules\Admin\Livewire\ProfileEdit` resolves actor via `PanelActor::resolve()`, and `Modules\EmailConnector\Livewire\EmailConnectorEdit` manages global SMTP key-value settings) — exempt ONLY those two FQCNs in the reflection test.
> - **1** uses a UUID primary identifier (`Modules\IvrMenus\Livewire\IvrMenusEdit` → `#[Locked] public ?string $menuUuid = null`).
> - **1** admin workbench uses `$editingId` (`Modules\Admin\Livewire\SettingsEdit` → `#[Locked] public ?int $editingId = null`).
> - **1** admin edit uses `$tenantId` as the primary key of `Tenant` (`Modules\Admin\Livewire\TenantsEdit` → `#[Locked] public ?int $tenantId = null`), whereas foreign-key tenant selectors (`$tenantId`) on `BaseEditComponent` subclasses and `GroupsEdit`/`TenantDomainsEdit` remain unlocked so admins can select a tenant on create while `resolveTenantId()` + `assertCanAccessTenantRecord()` guard mutations.
> - **40** remaining `*Edit.php` components lock their primary `$*Id` property (`$adminId`, `$userId`, `$groupId`, `$domainId`, `$extensionId` on `ExtensionsEdit`, `$deviceId`, `$templateId`, etc.).

**Interfaces:**
- Consumes: `Livewire\Attributes\Locked`, `PanelActor::can()`, `PanelActor::isSuperAdmin()`, `BaseEditComponent::assertCanAccessTenantRecord()`
- Produces:
  - Every record-identifying public property (`$*Id` / `$*Uuid`) across the 43 record-backed `*Edit` Livewire components carries `#[Locked]`.
  - `AdminsEdit::save()`, `UsersEdit::save()`, `GroupsEdit::save()`, `TenantsEdit::save()`, `TenantDomainsEdit::save()` re-verify exact create-vs-update ability (`*.create` when ID is `null`, `*.update` when ID is non-`null`) and enforce `Super Administrators` escalation guards.

- [x] **Step 1: Write the failing IDOR, privilege-escalation, and architecture tests**

In `tests/Feature/Security/EditComponentIdorProtectionTest.php`:
1. Admin holding ONLY `admin.users.create` renders `/panel/admins/create`, posts `updates: ['adminId' => $superAdmin->id]` + `calls: [['method' => 'save']]` → assert two deterministic invariants: (a) `$superAdmin->fresh()->email` is unchanged, and (b) the `/livewire/update` response is NOT successful. Assert one exact status — run the probe once, then pin `assertStatus(403)` or the 500 that Livewire's `CannotUpdateLockedPropertyException` produces; never accept a status range such as `403|500`, which would also pass for an unrelated crash. The presence of `#[Locked]` itself is proven separately by the attribute reflection test, not by the HTTP status.
2. Admin holding `admin.users.create` and `admin.users.update` (but NOT in `Super Administrators`) attempts to assign `selectedGroupIds: [$superAdminGroup->id]` on create or edit an existing Super Admin on `AdminsEdit` → `assertForbidden()` *(Review Focus #2)*.
3. Tenant user / restricted admin on `UsersEdit` cannot attach `selectedTenantIds` outside tenants the actor is permitted to manage.

In `tests/Feature/Architecture/ModuleArchitectureTest.php`:
- Reflect over every `*Edit` class in `app-modules/*/src/Livewire/` (exempting only `ProfileEdit` and `EmailConnectorEdit` by FQCN); assert each class declares at least one primary record-identifier property (`$*Id` or `$*Uuid`, including `SettingsEdit::$editingId`, `TenantsEdit::$tenantId`, and `IvrMenusEdit::$menuUuid`, while excluding foreign-key selectors such as `$tenantId` on non-`TenantsEdit` classes or `$extensionId` on `SipAccountsEdit`) carrying `#[Locked]`.

- [x] **Step 2: Run tests to verify they fail**

Run: `./vendor/bin/pest tests/Feature/Security/EditComponentIdorProtectionTest.php tests/Feature/Architecture/ModuleArchitectureTest.php`
Expected: FAIL (0 `#[Locked]` attributes currently exist on `*Edit` classes; `AdminsEdit` allows `Super Administrators` group attachment).

- [x] **Step 3: Add `#[Locked]` across the 43 record-backed edit components and harden `save()` authorization across all 45**

1. Add `use Livewire\Attributes\Locked;` and `#[Locked]` to the primary record identifier property on all 43 record-backed `*Edit` Livewire components (including `IvrMenusEdit::$menuUuid`, `SettingsEdit::$editingId`, and `TenantsEdit::$tenantId`).
2. In every `*Edit::save()`, re-assert create vs update permission (`*.create` when primary ID/UUID is `null`, `*.edit` or `*.update` when non-`null`; `email-connector.edit` on `EmailConnectorEdit::save()`) and call `$this->assertCanAccessTenantRecord($record)` before mutating on update branches (including `TemplateEdit`).
3. In `AdminsEdit::save()` and `GroupsEdit::save()`, forbid non-`PanelActor::isSuperAdmin()` actors from editing the `Super Administrators` group, editing an existing Super Admin, or syncing `Super Administrators` in `selectedGroupIds`. In `UsersEdit::save()`, restrict `selectedTenantIds` for tenant-scoped actors to their accessible tenant.
4. Update `app-modules/provision/tests/ProvisionTest.php` `beforeEach` to seed `AdminSeeder` and attach `$this->admin` to `Super Administrators`.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Feature/Security/EditComponentIdorProtectionTest.php tests/Feature/Architecture/ModuleArchitectureTest.php tests/Feature/CrossTenantMutationGuardTest.php app-modules/provision/tests/ProvisionTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add app-modules/ tests/Feature/Security/EditComponentIdorProtectionTest.php tests/Feature/Architecture/ModuleArchitectureTest.php
git commit -m "security(livewire): lock edit record IDs and block Super Admin privilege escalation"
```

---

### Task 4: Provisioning Template Allowlist, View-Only Rendering & Tenant Scoping (`C3`, `M7`)

**Files:**
- Modify: `app-modules/provision/src/Services/ProvisionServiceInterface.php:18-40`
- Modify: `app-modules/provision/src/Services/ProvisionService.php:75-165`
- Modify: `app-modules/provision/src/Livewire/TemplateEdit.php:25-100`
- Modify: `app-modules/provision/tests/ProvisionTest.php`

**Interfaces:**
- Consumes: `BaseEditComponent::resolveTenantId()`, `BaseEditComponent::assertCanAccessTenantRecord()`
- Produces:
  - `ProvisionService::TEMPLATE_PATH_PATTERN = '/^[A-Za-z0-9_-]+\/[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)?(\.blade\.php)?$/'`
  - `ProvisionServiceInterface::resolveTemplate(string $vendor, ?string $model = null, ?int $tenantId = null): ?string`
  - View-only rendering via `view('provision::templates.'.$normalizedViewName, [...])->render()` (zero `Blade::render(file_get_contents(...))` calls).

- [x] **Step 1: Write the failing security and tenant-isolation tests in `app-modules/provision/tests/ProvisionTest.php`**

Add tests asserting:
1. `ProvisionTemplate` with `file_path` set to `'../../.env'`, `'/etc/passwd'`, or `'storage/logs/laravel.log'` fails validation in `TemplateEdit::save()` AND fails closed (`InvalidArgumentException` / HTTP `404` or `500`, never disclosing file contents or executing `@php`) if persisted directly in DB *(Review Focus #3)*.
2. Existing relative paths WITH `.blade.php` suffix (`'grandstream/default.blade.php'`, `'yealink/t46s/default.blade.php'`) and WITHOUT suffix (`'grandstream/default'`) both normalize cleanly and render from `provision::templates.*` *(Review Focus #3)*.
3. Cross-tenant isolation (`M7`): Tenant A creates an enabled `ProvisionTemplate` for vendor `'polycom'`; Tenant B provisions a `'polycom'` device on the public endpoint → Tenant A's template record is NOT matched unless a global (`tenant_id === null`) or Tenant B (`tenant_id === $device->tenant_id`) template exists.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest app-modules/provision/tests/ProvisionTest.php`
Expected: FAIL (arbitrary file path is read/rendered by `file_get_contents`, and Tenant A's template resolves for Tenant B).

- [x] **Step 3: Harden `ProvisionService` and `TemplateEdit`**

1. In `ProvisionService`, add `public const TEMPLATE_PATH_PATTERN = '/^[A-Za-z0-9_-]+\/[A-Za-z0-9_-]+(\/[A-Za-z0-9_-]+)?(\.blade\.php)?$/';`.
2. Update `resolveTemplate(string $vendor, ?string $model = null, ?int $tenantId = null): ?string` (and pass `(int) $device->tenant_id` from `provision()`): filter `ProvisionTemplate::withoutGlobalScope('tenant')` by `->where('enabled', true)->where('vendor', strtolower($vendor))` and `->when($tenantId !== null, fn ($q) => $q->where(fn ($sub) => $sub->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->orderByRaw('tenant_id IS NULL ASC'))` so tenant-owned templates take precedence over global templates and never leak across tenants.
3. In `renderTemplate()`, remove `file_exists()` + `Blade::render(file_get_contents(...))`. Validate `$templatePath` against `self::TEMPLATE_PATH_PATTERN` (unless `$templatePath` is an exact path under `realpath(base_path('app-modules/provision/resources/views/templates'))` registered via `registerTemplate()`), strip optional `.blade.php`, convert `/` to `.`, verify `View::exists('provision::templates.'.$viewKey)` (aborting 404 when missing), and render strictly via `view('provision::templates.'.$viewKey, [...])->render()`.
4. In `TemplateEdit`, validate `filePath` with `'regex:'.ProvisionService::TEMPLATE_PATH_PATTERN`, replace `$this->tenantId` with `$this->resolveTenantId()`, and call `$this->assertCanAccessTenantRecord($template)` on update.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest app-modules/provision/tests/`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add app-modules/provision/
git commit -m "security(provision): enforce view-namespace allowlist and tenant-scoped template lookup"
```

---

### Task 5: Decouple `PermissionService::syncToDatabase()` from HTTP Requests & Fast-Path XML Handler (`C4`, `L6`)

**Files:**
- Create: `app/Support/PermissionSyncPolicy.php` (pure, unit-testable sync decision)
- Create: `app/Console/Commands/SyncPermissionsCommand.php`
- Create: `tests/Unit/Support/PermissionSyncPolicyTest.php`
- Create: `tests/Feature/Performance/XmlHandlerZeroSyncQueriesTest.php` (regression guard, not the RED driver)
- Modify: `app/Services/PermissionService.php:180-245`
- Modify: `app/Providers/AppServiceProvider.php:345-375`
- Modify: `app-modules/admin/src/Livewire/PermissionsList.php:52` (unconditional `syncToDatabase()` in `mount()`)
- Modify: `app-modules/admin/src/Livewire/GroupsEdit.php:77` (unconditional `syncToDatabase()` in `mount()`)
- Modify: `app/Services/GitUpdateService.php` (ensure `permissions:sync` runs after `migrate --force` during an update)
- Modify (Test Impact): `tests/Feature/Services/GitUpdatePipelineTest.php:56-66` (`$order` step list and exact `$result->steps` count assertion must include `'permissions:sync'`)

**Interfaces:**
- Produces:
  - `App\Support\PermissionSyncPolicy::shouldSync(?string $requestPath, bool $runningInConsole, ?string $cachedHash, string $currentHash): bool` — `false` for `api/v1/xml-handler*` requests, `false` when the hashes match, `true` otherwise; console callers are decided by hash only, never by path.
  - `php artisan permissions:sync` (`SyncPermissionsCommand`)
  - `PermissionService::registryHash(): string` (deterministic `sha1` of registered module permission names + descriptions)
  - `PermissionService::syncIfStale(): bool` — runs `syncToDatabase()` only when `PermissionSyncPolicy` reports a stale hash; used by the two admin `mount()` call sites so those pages stay correct without an unconditional upsert on every page load.
  - Per-request in-memory memoization on `PermissionService::all()` and DB-backed lookups (`L6`), cleared on `syncToDatabase()` / `register()` and after any direct `Permission` model write that a UI action performs.

- [x] **Step 1: Write the failing `PermissionSyncPolicy` unit tests (the RED driver) plus the hot-path regression test**

Create `tests/Unit/Support/PermissionSyncPolicyTest.php` first — it is the only test in this task that can legitimately start red:

```php
it('refuses to sync on the mod_xml_curl hot path', function () {
    expect((new PermissionSyncPolicy)->shouldSync('api/v1/xml-handler', false, null, 'hash-a'))->toBeFalse();
});

it('refuses to sync when the cached registry hash still matches', function () {
    expect((new PermissionSyncPolicy)->shouldSync('panel/extensions', false, 'hash-a', 'hash-a'))->toBeFalse();
});

it('syncs when the cached hash is missing or stale', function () {
    expect((new PermissionSyncPolicy)->shouldSync('panel/extensions', false, null, 'hash-a'))->toBeTrue()
        ->and((new PermissionSyncPolicy)->shouldSync('panel/extensions', false, 'hash-a', 'hash-b'))->toBeTrue();
});

it('decides console runs by hash only, never by path', function () {
    expect((new PermissionSyncPolicy)->shouldSync(null, true, 'hash-a', 'hash-b'))->toBeTrue();
});
```

Then create `tests/Feature/Performance/XmlHandlerZeroSyncQueriesTest.php` as a permanent regression guard. **It is green on today's unfixed code and must not be used as the RED driver** — see the lifecycle note in Step 2.

```php
it('executes zero permission sync writes and zero Gate definitions on mod_xml_curl requests', function () {
    $this->seed(\Database\Seeders\AdminSeeder::class);
    config()->set('freeswitch.xml_handler.auth', false);

    // Warm permission sync stamp once
    $this->artisan('permissions:sync')->assertExitCode(0);

    DB::enableQueryLog();
    DB::flushQueryLog();

    $response = $this->post('/api/v1/xml-handler', [
        'section' => 'dialplan',
        'Caller-Context' => 'nonexistent_context',
        'Caller-Destination-Number' => '1000',
    ]);

    $response->assertOk();

    $permissionQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql): bool => str_contains(strtolower($sql), 'permissions'));

    expect($permissionQueries)->toBeEmpty();
});
```

- [x] **Step 2: Run the test suite to verify the RED**

Run: `./vendor/bin/pest tests/Unit/Support/PermissionSyncPolicyTest.php`
Expected: FAIL with `Class "App\Support\PermissionSyncPolicy" not found`.

**Lifecycle note — why the query-log test cannot be the RED driver.** `AppServiceProvider::booted()` callbacks are flushed once during `createApplication()` in `setUp()` — *before* the test body calls `DB::enableQueryLog()` and *before* `RefreshDatabase` creates the `permissions` table. The permission upsert therefore never executes inside a feature-test request, so the query log is empty on both the fixed and the unfixed code. The policy unit test (Step 1) plus the delegation assertion in Step 3 carry the proof; the query-log test exists only to catch a future regression that reintroduces a per-request write on the hot path.

- [x] **Step 3: Gate `syncToDatabase()` behind a `PermissionSyncPolicy` hash check, add `permissions:sync`, and fast-path `api/v1/xml-handler*`**

1. Create `App\Support\PermissionSyncPolicy` as a pure class (`shouldSync()` takes the values it needs; it never touches the request, cache, or container) so it is unit-testable without booting Laravel.
2. Create `App\Console\Commands\SyncPermissionsCommand` (`signature = 'permissions:sync'`) calling `$permissionService->syncToDatabase()` and writing `Cache::forever('permissions.sync.hash', $permissionService->registryHash())`. Laravel 13 auto-discovers every command class under `app/Console/Commands`, so **do not** add a manual registration to `AppServiceProvider`.
3. In `PermissionService`, memoize `all()` in a per-instance array invalidated whenever `register()` or `syncToDatabase()` runs (`L6`). Add `registryHash(): string` computing `sha1(json_encode($this->all()))` and `syncIfStale(): bool` delegating to `PermissionSyncPolicy`.
4. In `AppServiceProvider::booted()`, delegate the decision to `PermissionSyncPolicy`: return before both `Schema::hasTable()`/`syncToDatabase()` **and** the `Gate::define()` loop when the incoming request `is('api/v1/xml-handler*')` (`C4`); otherwise sync only when the policy reports a stale hash, and replace the empty `catch (\Throwable)` with `Log::warning(...)`. Keep `Gate::define()` registering from `$permissionService->all()` so fresh in-memory test boots still resolve Gate abilities.
5. Replace the unconditional `$this->permissionService->syncToDatabase()` calls in `PermissionsList::mount()` and `GroupsEdit::mount()` with `syncIfStale()`, so C4 is closed on **every** HTTP path rather than only in the provider.
6. Add `php artisan permissions:sync` after `migrate --force` in `GitUpdateService` and update `tests/Feature/Services/GitUpdatePipelineTest.php` (`$order` + step count).

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Unit/Support/PermissionSyncPolicyTest.php tests/Feature/Performance/XmlHandlerZeroSyncQueriesTest.php tests/Feature/Services/GitUpdatePipelineTest.php tests/Feature/LivewireActionPermissionTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add app/Console/Commands/SyncPermissionsCommand.php app/Support/PermissionSyncPolicy.php app/Services/PermissionService.php app/Providers/AppServiceProvider.php app/Services/GitUpdateService.php app-modules/admin/src/Livewire/PermissionsList.php app-modules/admin/src/Livewire/GroupsEdit.php tests/Unit/Support/PermissionSyncPolicyTest.php tests/Feature/Performance/XmlHandlerZeroSyncQueriesTest.php tests/Feature/Services/GitUpdatePipelineTest.php
git commit -m "perf(permissions): remove per-request DB upsert from HTTP and mod_xml_curl hot path"
```

---

### Task 6: Uniform PHP 8.5 Floor Alignment & Dead Firewall Method Removal (`C5`, `M2`, `L1`, `L5`)

**Files:**
- Modify: `composer.json` (`"php": "^8.5"`)
- Modify: All 60 `app-modules/*/module.json` manifests (`"requirements": { "php": ">=8.5" }`)
- Modify: `resources/schemas/module.json:81` (update `"examples"` from `[">=8.3"]` to `[">=8.5"]`)
- Modify: `app-modules/security/src/Services/Firewall/SecurityConfigGenerator.php:308-330`
- Modify: `tests/Feature/Architecture/ModuleArchitectureTest.php` (append PHP `8.5` floor, conference-scoping, and dead-method assertions — do NOT create a separate `PhpFloorCompatibilityTest.php`)

**Interfaces:**
- Produces:
  - Uniform `"php": "^8.5"` root constraint and `"php": ">=8.5"` across all 60 built-in module manifests, matching `scripts/resources/config.sh` (`php_version=8.5`), `composer.lock`, and `.github/workflows/tests.yml` (native PHP 8.4/8.5 `array_any()` in `TenantEslScoping::filterConferencesByTenant()` remains valid as written).
  - `SecurityConfigGenerator` without the dead, unguarded `writeActive()` method.

- [x] **Step 1: Append failing PHP 8.5 floor & firewall guard assertions to `tests/Feature/Architecture/ModuleArchitectureTest.php`**

1. Assert `composer.json` `require.php` equals `'^8.5'` and every `app-modules/*/module.json` declares `requirements.php === '>=8.5'`.
2. Assert `! method_exists(SecurityConfigGenerator::class, 'writeActive')`.
3. Unit-test `TenantEslScoping::filterConferencesByTenant()` matching a conference via `members_list[*]['caller_id']` belonging to the tenant's extension list and rejecting conferences belonging only to another tenant.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Architecture/ModuleArchitectureTest.php`
Expected: FAIL (`composer.json` declares `^8.3`, all 60 `module.json` files declare `>=8.3`, and `SecurityConfigGenerator::writeActive` exists).

- [x] **Step 3: Bump PHP floor to `8.5` across root & module manifests and delete `SecurityConfigGenerator::writeActive()`**

1. Update `"php"` in `composer.json` from `"^8.3"` to `"^8.5"`, update `"requirements": { "php": ">=8.5", ... }` across all 60 `app-modules/*/module.json` manifests (and `resources/schemas/module.json:81`), and run `composer update --lock` (or verify `composer validate --strict` passes cleanly with zero lockfile diff beyond the content-hash).
2. Delete `SecurityConfigGenerator::writeActive()` from `app-modules/security/src/Services/Firewall/SecurityConfigGenerator.php` so all live ruleset writes must flow through `FirewallApplier`.

- [x] **Step 4: Run verification gate**

Run: `composer validate --strict && php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Feature/Architecture/ModuleArchitectureTest.php tests/Feature/ModuleManifestTest.php app-modules/security/tests/`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add composer.json composer.lock app-modules/*/module.json resources/schemas/module.json app-modules/security/src/Services/Firewall/SecurityConfigGenerator.php tests/Feature/Architecture/ModuleArchitectureTest.php
git commit -m "chore(php,security): align declared PHP floor to 8.5 and remove unguarded writeActive()"
```

---

### Task 7: Tenant-Scoped Allowlist Enforcement on ESL Conference, Agent & Active-Call Control Actions (`C6`)

**Files:**
- Modify: `app/Services/FreeSwitchControlService.php:70-175`
- Modify: `app-modules/active-conferences/src/Livewire/ActiveConferencesList.php:145-205`
- Modify: `app-modules/call-center-active/src/Livewire/CallCenterActiveList.php:155-215`
- Modify: `app-modules/active-calls/src/Livewire/ActiveCallsList.php:33,147-178`
- Create: `tests/Feature/Telephony/CrossTenantEslControlTest.php`

**Interfaces:**
- Consumes: `TenantEslScoping::filterConferencesByTenant()`, `TenantEslScoping::filterAgentsByTenant()`, `TenantEslScoping::filterChannelsByContext()`, `PanelActor::isAdmin()`
- Produces:
  - `FreeSwitchControlService::conferenceMute(string $conference, string $memberId, bool $muted, ?array $allowedConferences = null): array{success: bool, message: string}` — **the existing `bool $muted` parameter must be preserved.** Mute and unmute share this one method (`ActiveConferencesList::unmuteMember()` calls it with `$muted = false`), so replacing it with the allowlist parameter breaks unmute. Live signature today: `conferenceMute(string $conference, string $memberId, bool $muted)` at `FreeSwitchControlService.php:74`.
  - `FreeSwitchControlService::conferenceKick(string $conference, string $memberId, ?array $allowedConferences = null): array{success: bool, message: string}`
  - `FreeSwitchControlService::agentPause(string $agent, ?array $allowedAgents = null): array{success: bool, message: string}`
  - `FreeSwitchControlService::agentUnpause(string $agent, ?array $allowedAgents = null): array{success: bool, message: string}`
  - `FreeSwitchControlService::agentLogout(string $agent, ?array $allowedAgents = null): array{success: bool, message: string}`
  - `ActiveCallsList::allowedChannelUuids(): array<int, string>` — server-computed UUID allowlist used in place of `array_column($this->calls, 'uuid')`.
  - `#[Locked] public array $calls` on `ActiveCallsList`.
  *(Note: when `$allowedConferences` / `$allowedAgents` is an `array` — even `[]` — membership is strictly required via `in_array(..., true)`; passing `null` is permitted only for unscoped global `PanelActor::isAdmin()` callers without an active tenant filter.)*

> **In-scope defect beyond the source review's `C6` (`H` severity, same class).** `ActiveCallsList::hangupCall()` (`:158`) and `transferCall()` (`:176`) pass `array_column($this->calls, 'uuid')` as the ESL allowlist, and `public array $calls` carries no `#[Locked]`. Livewire serialises that property into the snapshot and re-hydrates it from the client, so a POST to `/livewire/update` injecting another tenant's channel UUID makes `FreeSwitchControlService::validateUuid()` pass — cross-tenant call termination and transfer. The source review scored `active-calls` as "Good" because it revalidates against "the current list", but that list is client-controlled. Remediate it in this task rather than deferring: the fix is the same shape as the conference/agent work.

> **Standing note for implementation — Task 7 execution order.** This task now carries **two** independent security remediations (`C6` conference/agent allowlists, and the `active-calls` channel allowlist) behind a single Phase-1 gate. Execute it in this order, and do not reorder:
> 1. Write **all five** RED cases from Step 1 first — cases 1–3 for conferences/agents, cases 4–5 for active-calls — and confirm every one fails in Step 2. If case 4 passes immediately the test is wrong, not the code: it is most likely asserting *after* `refreshCalls()` has re-filtered `$calls`, or the ESL mock is not actually returning Tenant B's channel. Fix the test until it fails for the right reason.
> 2. Only then edit Step 3's four items. The `#[Locked]` attribute and `allowedChannelUuids()` must not land before the failing assertion that proves they are needed exists.
> 3. Step 4 is the **Phase 1 phase gate** and runs the full parallel suite. Tasks 1–6 must already be committed and green before it runs.
> 4. Commit **once** in Step 5. Do not commit the conference/agent half separately — the gate and the commit must describe the same verified state, or the phase gate runs against a half-remediated codebase.

- [x] **Step 1: Write the failing cross-tenant ESL control tests in `tests/Feature/Telephony/CrossTenantEslControlTest.php`**

Test both normal and cold-hydrated Livewire payloads *(Review Focus #4)*:
1. Mock ESL output returning two active conferences: `'3001@tenant-a.test'` (Tenant A) and `'3002@tenant-b.test'` (Tenant B). Authenticate as Tenant A user with `active-conferences.view`, `active-conferences.mute`, `active-conferences.kick`. Calling `kickMember('3002@tenant-b.test', '1')` or `muteMember('3002@tenant-b.test', '1')` (even before `loadConferences()` populates `$this->conferences`) must NOT send an ESL `bgapi`/`api` kick/mute command and must set `$this->actionError`.
2. Mock ESL `callcenter_config agent list` with `'1001@tenant-a.test'` and `'2001@tenant-b.test'`. Authenticate as Tenant A user with `call-center-active.view` and `call-center-active.control`. Calling `pauseAgent('2001@tenant-b.test')`, `unpauseAgent('2001@tenant-b.test')`, or `logoutAgent('2001@tenant-b.test')` must NOT execute the ESL command and must return an error message.
3. Calling the same actions against Tenant A's own room `'3001@tenant-a.test'` / agent `'1001@tenant-a.test'` succeeds and dispatches the ESL command.
4. `ActiveCallsList` cross-tenant hangup/transfer *(the `H`-severity defect pinned above)*: mock `show channels as json` returning Tenant A's and Tenant B's channels. As a Tenant A user holding `active-calls.view` + `active-calls.hangup`, post a snapshot whose `calls` array contains Tenant B's UUID and call `hangupCall('<tenant-b-uuid>')` → no `uuid_kill` is sent and `$actionError` is set. Repeat for `transferCall('<tenant-b-uuid>', '2000')` with `active-calls.transfer` → no `uuid_transfer` is sent.
5. The same test posts `updates: ['calls' => [...]]` through `/livewire/update` and asserts the mutation is rejected because `$calls` is `#[Locked]`.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Telephony/CrossTenantEslControlTest.php`
Expected: FAIL (ESL commands execute for Tenant B's conference and agent, and `ActiveCallsList` accepts a client-supplied channel UUID from a tampered snapshot).

- [x] **Step 3: Enforce allowlists in `FreeSwitchControlService`, `ActiveConferencesList`, `CallCenterActiveList`, and `ActiveCallsList`**

1. In `FreeSwitchControlService`, **append** optional `?array $allowedConferences = null` / `?array $allowedAgents = null` parameters after the existing ones — do not remove or reorder `conferenceMute`'s `bool $muted`. When the allowlist is non-null, verify `in_array($conference, $allowedConferences, true)` / `in_array($agent, $allowedAgents, true)` before token validation and ESL execution, returning `['success' => false, 'message' => 'Target not found in active tenant.']` on mismatch.
2. In `ActiveConferencesList` (`muteMember`, `unmuteMember`, `kickMember`) and `CallCenterActiveList` (`pauseAgent`, `unpauseAgent`, `logoutAgent`), resolve the allowed list server-side via a helper (`allowedConferenceNames(): ?array` / `allowedAgentNames(): ?array`) that queries fresh ESL state filtered through `TenantEslScoping` whenever a tenant scope applies (`! PanelActor::isAdmin()` or `TenantManager::hasTenant()`), never trusting client-hydrated state or skipping when `$this->conferences === []`.
3. In `ActiveCallsList`, add `#[Locked]` to `public array $calls`, add `allowedChannelUuids(): array` that re-reads `show channels as json` and filters through `TenantEslScoping::filterChannelsByContext()` (the same path `refreshCalls()` uses), and replace both `array_column($this->calls, 'uuid')` call sites in `hangupCall()` and `transferCall()` with it. Rule: a control allowlist is never derived from a public Livewire property.
4. Also improve `ActiveConferencesList` catch block so non-ESL `Throwable` errors are logged via `Log::error(...)` rather than silently masked as "FreeSWITCH is not connected".

- [x] **Step 4: Run Phase 1 verification gate**

Run:
```bash
php artisan optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest tests/Feature/Telephony/CrossTenantEslControlTest.php app-modules/active-conferences/ app-modules/call-center-active/ app-modules/active-calls/
php artisan test --compact --parallel
```
Expected: All Phase 1 and existing suite tests PASS.

- [x] **Step 5: Commit**

```bash
git add app/Services/FreeSwitchControlService.php app-modules/active-conferences/src/Livewire/ActiveConferencesList.php app-modules/call-center-active/src/Livewire/CallCenterActiveList.php app-modules/active-calls/src/Livewire/ActiveCallsList.php tests/Feature/Telephony/CrossTenantEslControlTest.php
git commit -m "security(esl): enforce tenant allowlists on conference, agent, and active-call control actions"
```

---

## Phase 2 — Performance, Manifest Integrity & Tenancy Hardening

### Task 8: XML Handler Cache TTL & Directory/ExtensionSetting Cache Invalidation (`H8`)

**Files:**
- Modify: `config/freeswitch.php:354-359`
- Modify: `app/Services/Xml/DirectoryXmlBuilder.php:165-178`
- Modify: `app/Providers/AppServiceProvider.php:380-419`
- Modify: `app/Observers/RoutingCacheObserver.php:72-114`
- Modify: `tests/Feature/Observers/RoutingCacheObserverTest.php`

**Interfaces:**
- Consumes: `RoutingCacheVersion::get(int $tenantId): int`, `RoutingCacheVersion::bump(int $tenantId): void`
- Produces:
  - `config('freeswitch.xml_handler.cache_ttl')` default `60` seconds (propagating to `dialplan_cache_ttl`, `dialplan_contributor_cache_ttl`, `directory_cache_ttl`, `acl_cache_ttl`).
  - `DirectoryXmlBuilder::directoryCacheKey()` embedding `(string) RoutingCacheVersion::get((int) $tenantId)`.
  - `ExtensionSetting` observed by `RoutingCacheObserver` for instant directory XML invalidation.

- [x] **Step 1: Write the failing cache invalidation tests in `tests/Feature/Observers/RoutingCacheObserverTest.php`**

1. Assert the **fallback literal** in `config/freeswitch.php` is `60` by matching `env('FS_XML_HANDLER_CACHE_TTL', 60)` in the file source, and separately assert `config('freeswitch.xml_handler.cache_ttl') === 60`. The source-literal check is environment-independent; the config check is the behavioural one and only holds where the variable is genuinely unset, so CI must not define `FS_XML_HANDLER_CACHE_TTL`. Do not rely on the developer's `.env` being clean.
2. Render cached directory XML for a `SipAccount` with TTL `60`, rotate `$sipAccount->update(['auth_password' => 'NewSecret999'])` (and test updating an `ExtensionSetting`), then immediately call `buildCachedDirectoryXml(...)` again → assert the response contains `'NewSecret999'` without waiting for TTL expiry *(Review Focus #5)*.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Observers/RoutingCacheObserverTest.php`
Expected: FAIL (default TTL is `5`, and cached directory XML serves the stale password because `directoryCacheKey()` omits `RoutingCacheVersion`).

- [x] **Step 3: Update `config/freeswitch.php`, `DirectoryXmlBuilder`, and `RoutingCacheObserver`**

1. Change default fallback in `config/freeswitch.php:354-358` from `5` to `60`.
2. Append `(string) RoutingCacheVersion::get((int) $tenantId)` to `DirectoryXmlBuilder::directoryCacheKey()`.
3. Register `Modules\ExtensionSettings\Models\ExtensionSetting::class` in `AppServiceProvider::registerRoutingCacheObservers()`.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Feature/Observers/RoutingCacheObserverTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add config/freeswitch.php app/Services/Xml/DirectoryXmlBuilder.php app/Providers/AppServiceProvider.php app/Observers/RoutingCacheObserver.php tests/Feature/Observers/RoutingCacheObserverTest.php
git commit -m "perf(xml-handler): raise default XML cache TTL to 60s with versioned directory invalidation"
```

---

### Task 9: High-Volume List Pagination, `#[Computed]` Refactoring & Compound Indexing (`H2`, `§5f`)

**Files:**
- Create: `database/migrations/2026_10_28_000002_add_tenant_created_at_indexes_to_high_volume_tables.php`
- Modify: `app-modules/call-recordings/src/Livewire/CallRecordingsList.php` + `app-modules/call-recordings/resources/views/call-recordings-list.blade.php`
- Modify: Top 9 remaining high-volume unbounded list components + Blade views (`voicemail-messages`, `file-stores`, `recordings`, `fax` [`app-modules/fax/src/Livewire/FaxInbox.php` — note `fax` has no `*List.php`], `devices`, `extensions`, `sip-accounts`, `inbound-routes`, `outbound-routes`)
- Create: `app-modules/call-recordings/tests/CallRecordingsListPaginationTest.php`
- Verify / Modify if needed (Test Impact — 7 existing `assertViewHas` module tests):
  - `app-modules/call-recordings/tests/CallRecordingTest.php:22`
  - `app-modules/voicemail-messages/tests/VoicemailMessageTest.php:22`
  - `app-modules/recordings/tests/RecordingTest.php:24`
  - `app-modules/fax/tests/FaxTest.php:25`
  - `app-modules/extensions/tests/ExtensionsComponentTest.php:36`
  - `app-modules/inbound-routes/tests/InboundRoutesComponentTest.php:30`
  - `app-modules/outbound-routes/tests/OutboundRoutesComponentTest.php:31`

**Test Impact:** Those 7 existing module tests call `->assertViewHas('<items>')`. Passing the `#[Computed]` paginator explicitly into `view('...', ['recordings' => $this->recordings])` inside `render()` keeps `wire:snapshot['data']` completely free of serialized Eloquent collections while keeping `assertViewHas(...)` green (amend any closure inside `assertViewHas` only if it explicitly type-hints `Collection` instead of `LengthAwarePaginator`).

**Interfaces:**
- Produces:
  - Compound index `(tenant_id, created_at)` on `call_recordings` and `media_assets` (plus `voicemail_messages` / `xml_cdrs` where lacking).
  - `#[Computed] public function recordings(): LengthAwarePaginator` on `CallRecordingsList` using `WithPagination` (`perPage = 25`), eliminating `public Collection $recordings` from the Livewire snapshot payload.

- [x] **Step 1: Write the failing snapshot-bloat and pagination test in `app-modules/call-recordings/tests/CallRecordingsListPaginationTest.php`**

Seed 60 `CallRecording` rows for a tenant. Render `CallRecordingsList`:
1. Assert only 25 rows render on page 1 and pagination links appear.
2. Extract the Livewire `wire:snapshot` JSON and assert `data` does NOT contain a serialized `recordings` Eloquent collection (`expect($decoded['data'])->not->toHaveKey('recordings')`).

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest app-modules/call-recordings/tests/CallRecordingsListPaginationTest.php`
Expected: FAIL (all 60 rows load into `public Collection $recordings` inside the snapshot).

- [x] **Step 3: Add compound indexes and migrate high-volume lists to `WithPagination` + `#[Computed]`**

1. Check existing indexes via `database-schema`, then create `2026_10_28_000002_add_tenant_created_at_indexes_to_high_volume_tables.php` adding `(tenant_id, created_at)` indexes where absent.
2. Refactor `CallRecordingsList` and the remaining 9 high-volume list components (including `FaxInbox`) to use `Livewire\WithPagination` and `#[Computed]` paginators (`->paginate(25)`), removing public `Collection` properties, passing the `#[Computed]` paginator to `view(...)` in `render()`, and rendering pagination links in the card footer. Ensure `updatedSearch()` / filter hooks call `$this->resetPage()`.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest app-modules/call-recordings/tests/ app-modules/voicemail-messages/tests/ app-modules/file-stores/tests/ app-modules/recordings/tests/ app-modules/fax/tests/ app-modules/devices/tests/ app-modules/extensions/tests/ app-modules/sip-accounts/tests/ app-modules/inbound-routes/tests/ app-modules/outbound-routes/tests/`
Expected: PASS across both the new pagination test and all 10 affected module test suites.

- [x] **Step 5: Commit**

```bash
git add database/migrations/2026_10_28_000002_add_tenant_created_at_indexes_to_high_volume_tables.php app-modules/
git commit -m "perf(lists): paginate high-volume Livewire lists with Computed properties and compound tenant indexes"
```

---

### Task 10: Module Manifest Topological Load Order, Schema Validation & Core Invariants (`H5`, `H6`, `H7`, `L2`, `L3`)

**Files:**
- Create: `app/Support/ModuleDependencySorter.php`
- Modify: `tests/Feature/ModuleManifestTest.php` (extend this existing 4-test file — do NOT create a competing `ModuleManifestSchemaTest.php`)
- Modify: `app/Providers/ModuleServiceProvider.php:110-140` (`discoverModules()` / `uasort` by priority)
- Modify: `app/Services/ModuleLifecycleService.php`
- Modify: all 60 `app-modules/*/module.json` manifests for the `priority` recomputation, with semantic changes required in `app-modules/file-stores`, `auth`, `backups`, `tenant`, `hot-desking`, `voicemails`, `gateways`, `extensions`, `sip-profiles`, and `admin` (the five inverted modules, the two duplicate-priority pairs, the three `protected: false` modules, and the schema-shape offender). Do not treat the ten as the complete edit set — every manifest receives a regenerated priority.

**Interfaces:**
- Produces:
  - `App\Support\ModuleDependencySorter::sort(array $manifests): array` — deterministic Kahn topological sort over the **existing `requirements.modules` declarations**, using `priority` as tie-breaker, throwing `LogicException` on dependency cycles or on a dependency that names an unknown module.
  - Runtime & CI invariant: `required: true` implies `protected: true`, zero duplicate priorities across the 60 built-in manifests, and 100% conformance of all 60 `app-modules/*/module.json` files against `resources/schemas/module.json`.
- Note: **no `dependencies` key is introduced.** `requirements.modules` already exists in the schema and is already declared by 23 manifests (`admin → backups`, `backups → file-stores`, `extensions → sip-accounts, voicemails`, `hot-desking → devices, extensions`, `sip-profiles → gateways`, `devices → sip-accounts`, `provision → devices, extensions`, and 16 more). `resources/schemas/module.json` therefore needs **no structural change** in this task, and no manifest needs a new key.

- [x] **Step 1: Extend `tests/Feature/ModuleManifestTest.php` with the failing integrity tests**

1. Validate all 60 `app-modules/*/module.json` files against the schema constraints — required keys, types, the `name`/`version`/`namespace` patterns, the `category` enum, and `requirements.packages` item shape `{name, version}`. No JSON-Schema library is installed (`opis/json-schema` is absent from `composer.json`), so implement the checks explicitly and add one guard asserting your required-key list equals `resources/schemas/module.json`'s `required` array — that guard is what stops the validator drifting from the schema.
2. Assert unique `priority` integers across the 60 built-in manifests (scope to `app-modules/*`; custom and vendor modules may share priorities).
3. Assert every manifest with `"required": true` also declares `"protected": true`.
4. Assert every entry in `requirements.modules` names an existing module directory (dangling-reference guard) and that no cycle exists.
5. Assert `ModuleDependencySorter::sort($manifests)` places every module in `requirements.modules` strictly before its dependent module — specifically `voicemails` before `extensions`, `gateways` before `sip-profiles`, `file-stores` before `backups`, `backups` before `admin`, `devices` and `extensions` before `hot-desking`.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/ModuleManifestTest.php`
Expected: FAIL (`file-stores/module.json` `requirements.packages` are plain strings, not `{name, version}` objects; duplicate priorities `0` (`hot-desking`, `logs`) and `210` (`outbound-routes`, `voicemails`); `auth`/`backups`/`tenant` have `protected: false`; 5 priority inversions — `extensions` (120) before `voicemails` (210), `sip-profiles` (100) before `gateways` (140), `backups` (40) before `file-stores` (91), `admin` (-100) before `backups`, `hot-desking` (0) before `devices` (130) and `extensions` (120)).

- [x] **Step 3: Implement `ModuleDependencySorter`, fix manifests, and wire provider registration**

1. Rewrite `app-modules/file-stores/module.json` `requirements.packages` as objects, preserving **all four** currently declared packages (a single-package replacement would silently drop three runtime requirements):
   ```json
   "packages": [
       {"name": "league/flysystem-aws-s3-v3", "version": "^3.35"},
       {"name": "league/flysystem-ftp", "version": "^3.35"},
       {"name": "league/flysystem-sftp-v3", "version": "^3.36"},
       {"name": "spatie/flysystem-dropbox", "version": "^3.1"}
   ]
   ```
2. Set `"protected": true` in `auth/module.json`, `backups/module.json`, and `tenant/module.json`; make `ModuleLifecycleService` derive protection from `$manifest['protected'] || $manifest['required']` so the invariant cannot regress through a future manifest edit.
3. Recompute the `priority` column for the 60 built-in manifests **from the topological order** — unique integers, increments of 10, preserving the current relative order among modules with no dependency relationship. Do not hand-tune the five inverted modules individually: the integer scale is already dense (60 modules over −100…720) with two duplicate pairs, so five local fixes cascade into new collisions. Generate the table from the sorted output, apply it, and let Step 1's uniqueness + ordering assertions prove it.
4. Wire `ModuleDependencySorter::sort()` into `App\Providers\ModuleServiceProvider::discoverModules()` in place of the bare `uasort(... priority ...)` at `:135`, so runtime registration order is guaranteed topological even for third-party modules whose priorities collide.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Feature/ModuleManifestTest.php tests/Feature/Architecture/ModuleArchitectureTest.php`
Expected: PASS (including the pre-existing manifest and module-architecture assertions, which must not regress).

- [x] **Step 5: Commit**

```bash
git add app/Support/ModuleDependencySorter.php app/Providers/ModuleServiceProvider.php app/Services/ModuleLifecycleService.php app-modules/*/module.json tests/Feature/ModuleManifestTest.php
git commit -m "fix(modules): enforce topological load order, schema validation, and required-implies-protected"
```

---

### Task 11: Standardize `resolveTenantId()` Across All 36 Livewire Form Components (`M1`)

**Files:**
- Modify: All 36 Livewire form components in `app-modules/*/src/Livewire/` currently writing `'tenant_id' => $this->tenantId` (**33** `*Edit.php` files + **3** non-`*Edit.php` form classes: `BroadcastCreate.php`, `ExtensionsBulkCreate.php`, and `FaxSend.php`)
- Modify: `tests/Feature/Architecture/ModuleArchitectureTest.php` (append raw `$this->tenantId` payload scan — do NOT create a separate `EditTenantResolutionTest.php`)

> **Scope & inheritance nuances:**
> - `Modules\EmailConnector\Livewire\EmailConnectorEdit` manages global SMTP key-value settings and has no `$tenantId` property — leave it untouched on `Livewire\Component`.
> - `Modules\Admin\Livewire\GroupsEdit` allows `$tenantId === null` for global system groups (`Super Administrators`, `Administrators`). Add a local `protected function resolveTenantId(): ?int` helper on `GroupsEdit` (and `TenantDomainsEdit` if kept on `Livewire\Component`) rather than forcing `GroupsEdit` onto `BaseEditComponent`'s default-tenant preselection, which would overwrite `null` on global system groups. All 34 remaining tenant-resource form components extend `BaseEditComponent` and call `$this->resolveTenantId()`.

**Interfaces:**
- Consumes: `BaseEditComponent::resolveTenantId(): ?int`
- Produces: Zero raw `'tenant_id' => $this->tenantId` assignments anywhere in `app-modules/*/src/Livewire/`.

- [x] **Step 1: Append the failing raw-`$this->tenantId` architecture check to `tests/Feature/Architecture/ModuleArchitectureTest.php`**

Scan every PHP file in `app-modules/*/src/Livewire/` and assert zero matches for `/['"]tenant_id['"]\s*=>\s*(?:\(int\)\s*)?\$this->tenantId\b/`.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Architecture/ModuleArchitectureTest.php`
Expected: FAIL listing the 36 Livewire form components using raw `$this->tenantId`.

- [x] **Step 3: Replace all 36 raw `$this->tenantId` writes with `$this->resolveTenantId()`**

Replace every `'tenant_id' => $this->tenantId` (and `(int) $this->tenantId`) assignment across the 33 `*Edit.php` files plus `BroadcastCreate.php`, `ExtensionsBulkCreate.php`, and `FaxSend.php` with `$this->resolveTenantId()`, preserving nullable global-group support on `GroupsEdit`.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Feature/Architecture/ModuleArchitectureTest.php tests/Feature/CrossTenantMutationGuardTest.php tests/Feature/Livewire/GroupManagementTest.php tests/Feature/Livewire/TenantDomainManagementTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add app-modules/ tests/Feature/Architecture/ModuleArchitectureTest.php
git commit -m "refactor(tenancy): standardize resolveTenantId() across all 36 module form components"
```

---

### Task 12: Explicit `TenantContext` Entry Points & Fail-Closed `BelongsToTenant` Scope (`H3`, `M5`)

**Files:**
- Create: `app/Exceptions/TenantContextMissingException.php`
- Modify: `app/Traits/BelongsToTenant.php:28-69`
- Modify: `app/Services/TenantContext.php:60-177`
- Create: `tests/Feature/Tenancy/BelongsToTenantFailClosedTest.php`
- Modify (Test Impact):
  - `tests/Feature/Tenancy/CrossTenantIsolationTest.php:103-108` (`'throws RuntimeException when tenant model queried without context'` currently asserts `toThrow(\RuntimeException::class)` on `Extension::all()` — update in the same commit to assert `Extension::all()` returns `0` rows fail-closed on reads and `Extension::create([...])` throws `TenantContextMissingException` on writes)
  - `tests/Feature/Services/TenantContextTest.php:182-239` (add coverage for `resolve($user, allowSingleTenantFallback: false)`, `resolveStrict()`, and `assertResolved()` while keeping all existing `resolve()` assertions green)

**Interfaces:**
- Produces:
  - `App\Exceptions\TenantContextMissingException extends \RuntimeException` (renders HTTP `503` when uncaught on web requests).
  - `BelongsToTenant` global `'tenant'` read scope applies `$builder->whereRaw('1 = 0')` (returning zero rows quietly, fail-closed) when `TenantManager::getTenantId() === null`, while `creating`/`saving`/`deleting` hooks throw `TenantContextMissingException` if `tenant_id` is still null on write paths.
  - `TenantContext::resolve(?User $user = null, bool $allowSingleTenantFallback = true): ?Tenant` (pure deterministic method with **zero `runningInConsole()` or `runningUnitTests()` branching**, forwarding `$allowSingleTenantFallback` into `resolveForUser()`), `TenantContext::resolveStrict(?User $user = null): ?Tenant` (convenience wrapper passing `allowSingleTenantFallback: false` for CLI/queue jobs), and `TenantContext::assertResolved(): Tenant` (throwing `TenantContextMissingException` when `TenantManager::getTenantId() === null` or the active tenant record does not exist).

- [x] **Step 1: Write the failing read/write/strict-resolution matrix tests in `tests/Feature/Tenancy/BelongsToTenantFailClosedTest.php` and `tests/Feature/Services/TenantContextTest.php`**

1. Clear `TenantManager`; create two tenants with `Extension` rows via `withoutGlobalScope('tenant')`. Query `Extension::query()->get()` with no active tenant context → returns an empty collection (`0` rows) instead of throwing `RuntimeException` (`H3`).
2. Attempt `Extension::create([...])` with no `tenant_id` and no active `TenantManager` context → throws `TenantContextMissingException`.
3. Call `app(TenantContext::class)->assertResolved()` when unconfigured → throws `TenantContextMissingException`; when `TenantManager` holds a valid tenant ID → returns that `Tenant`.
4. With a single enabled tenant in the DB and no session/user attachment, `resolve()` returns that single tenant (`allowSingleTenantFallback: true`), whereas `resolve(allowSingleTenantFallback: false)` and `resolveStrict()` return `null`.
5. Update `tests/Feature/Tenancy/CrossTenantIsolationTest.php:103-108` to assert the new fail-closed zero-row read + `TenantContextMissingException` write contract.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Tenancy/BelongsToTenantFailClosedTest.php tests/Feature/Tenancy/CrossTenantIsolationTest.php tests/Feature/Services/TenantContextTest.php`
Expected: FAIL (`Extension::query()->get()` throws `RuntimeException`, and `resolveStrict()` / `assertResolved()` do not exist yet).

- [x] **Step 3: Implement `TenantContextMissingException`, quiet fail-closed read scope, and `TenantContext` strict helpers**

1. Create `TenantContextMissingException` with a `render()` method returning HTTP `503` (JSON `503` when `$request->expectsJson()`) when unhandled.
2. In `BelongsToTenant`, change the global `'tenant'` scope on `null` `$tenantId` to `$builder->whereRaw('1 = 0'); return;`, and throw `TenantContextMissingException` in `creating`/`saving` when `empty($model->tenant_id)`.
3. In `TenantContext`, add `bool $allowSingleTenantFallback = true` to `resolve()` and `resolveForUser()` (gating step 3's single-tenant query strictly on `$allowSingleTenantFallback`, never on `runningUnitTests()`), add `resolveStrict(?User $user = null): ?Tenant`, and add `assertResolved(): Tenant`.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Feature/Tenancy/BelongsToTenantFailClosedTest.php tests/Feature/Tenancy/CrossTenantIsolationTest.php tests/Feature/Services/TenantContextTest.php tests/Feature/CrossTenantMutationGuardTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add app/Exceptions/TenantContextMissingException.php app/Traits/BelongsToTenant.php app/Services/TenantContext.php tests/Feature/Tenancy/BelongsToTenantFailClosedTest.php tests/Feature/Tenancy/CrossTenantIsolationTest.php tests/Feature/Services/TenantContextTest.php
git commit -m "fix(tenancy): fail closed with empty set on unscoped reads and add TenantContext::assertResolved()"
```

---

### Task 13: Argv-Array `SystemProcessRunner` Refactoring (`H4`)

**Files:**
- Modify: `app/Contracts/ProcessRunner.php` (the interface is at `app/Contracts/ProcessRunner.php`; there is no `app/Support/ProcessRunner.php`)
- Modify: `app/Support/SystemProcessRunner.php`
- Modify: `app/Services/GitUpdateService.php`
- Modify: `tests/Feature/Services/FakeProcessRunner.php` (implements `ProcessRunner` — must migrate in the same commit)
- Modify: `tests/Feature/Services/GitUpdateServiceTest.php`, `tests/Feature/Services/GitUpdatePipelineTest.php`

**Interfaces:**
- Produces:
  - `ProcessRunner::run(array $argv, ?string $cwd = null, ?int $timeoutSeconds = null, array $env = []): ProcessResult` backed by `Symfony\Component\Process\Process` (passing `$argv` directly without `/bin/sh -c` or `exec()`). `$timeoutSeconds = null` resolves to `config('git-update.step_timeout_seconds', 600)` — **not** a hard-coded `120`, which would silently cut the existing 600-second step budget by 80%.
- Preserves (these are behaviour the update pipeline depends on, not incidental details):
  - **Timeout → exit `124`.** The current runner wraps every command in `timeout -k 30s <n>s`, so a hung step returns `124` and the pipeline rolls back instead of blocking. Replicate by catching `ProcessTimedOutException` and returning `new ProcessResult(124, ...)`. `Process::setTimeout()` alone throws instead of returning, which would change the failure path.
  - **Environment assembly.** `PATH` (with `/usr/local/bin`, `/usr/bin`, `/bin`, `/usr/local/sbin`, `/usr/sbin` prepended), `COMPOSER_HOME=storage_path('framework/composer')`, `COMPOSER_NO_INTERACTION=1`, `COMPOSER_ALLOW_SUPERUSER=1`, and `GIT_SSH_COMMAND='ssh -o StrictHostKeyChecking=accept-new'`, with `$cwd = base_path()`.
  - **Merged output.** `ProcessResult::output` is today `stdout` + `stderr` combined via `2>&1`; return `$process->getOutput().$process->getErrorOutput()` because `GitUpdateService` parses that single string for step reporting and error messages.

- [x] **Step 1: Write the failing argv-runner tests in `tests/Feature/Services/GitUpdateServiceTest.php`**

Assert `SystemProcessRunner` executes `['git', 'status', '--porcelain']` with `$cwd` via `Symfony\Component\Process\Process` without invoking a shell, preserving literal metacharacters (`;`, `$()`, backticks) as inert arguments rather than shell commands. Also assert the three preservation behaviours above: a timed-out command yields `ProcessResult::exitCode === 124`, the child process receives the PATH/COMPOSER_*/GIT_SSH_COMMAND environment, and `output` contains both streams.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Services/GitUpdateServiceTest.php`
Expected: FAIL (`ProcessRunner::run` currently declares `string $command` and `SystemProcessRunner` calls `exec()`).

- [x] **Step 3: Migrate `SystemProcessRunner`, `GitUpdateService`, and `FakeProcessRunner` to `array $argv`**

1. Replace the `exec()` string assembly in `SystemProcessRunner` with `(new Process($argv, $cwd, $env, null, $timeout))->run()`, translating `ProcessTimedOutException` to `ProcessResult(124, ...)` and concatenating stdout with stderr.
2. Convert every `GitUpdateService` call site to structured argument arrays — `composer validate --no-check-publish`, the git fetch/checkout/pull commands, the post-update `php artisan ...` steps, and `git -C <repoPath> <command>` at `:710`, where `$command` is a runtime-composed string and therefore needs to be built as an array rather than split from a string. Remove all manual `escapeshellarg()` string building.
3. Update `tests/Feature/Services/FakeProcessRunner.php` to the new signature so the pipeline tests keep binding a fake instead of executing real `composer`/`npm`/`git`.

- [x] **Step 4: Run Phase 2 verification gate**

Run:
```bash
php artisan optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest tests/Feature/Services/GitUpdateServiceTest.php tests/Feature/Services/GitUpdatePipelineTest.php
```
Expected: All targeted tests PASS.

- [x] **Step 5: Commit**

```bash
git add app/Contracts/ProcessRunner.php app/Support/SystemProcessRunner.php app/Services/GitUpdateService.php tests/Feature/Services/FakeProcessRunner.php tests/Feature/Services/GitUpdateServiceTest.php tests/Feature/Services/GitUpdatePipelineTest.php
git commit -m "security(process): migrate SystemProcessRunner and GitUpdateService from shell strings to argv arrays"
```

---

## Phase 3 — UI Consistency, Localization & Accessibility Sweep

> **Executed as three sub-phases, each with its own gate and commit series.** **3A** = Tasks 14–15 (shared primitives + reconciliation of the pre-existing `UiArchitectureTest` assertions). **3B** = Tasks 16–17 (control semantics, dimming, `wire:navigate`, accessibility). **3C** = Tasks 18–19 (localization sweep + realtime health banner, then `npm run build` as the final step).
>
> **Do not create a new conventions test file.** `tests/Feature/Architecture/UiArchitectureTest.php` already polices page-title typography, toggle sizing and loading locks, `x-form-submit-button` presence, localized table headers, and the arbitrary-font-size allowlist across the same 445 views. Append new `it(...)` blocks to that file; a parallel conventions file would create two sources of truth that disagree the moment one is updated.

### Task 14: `<x-form-error>` Shared Blade Component & 362 `@error` Migration (`U4`)

**Files:**
- Create: `resources/views/components/form-error.blade.php`
- Modify: All `@error(...)` blocks across `app-modules/*/resources/views/**/*.blade.php` — **362 occurrences across 64 files** (305 of them in `*edit*`/`*create*` views; `resources/views/` contains 0 raw `@error` blocks)
- Modify: `tests/Feature/Architecture/UiArchitectureTest.php` (append assertions — the file already exists and must not be duplicated)

**Test Impact:** `UiArchitectureTest`'s `x-form-submit-button` assertion scans the same edit/create views — the `@error` replacement must not disturb the `<x-form-submit-button>` element or its surroundings. `TelephonyPushBroadcastingTest` scans every Blade file for `wire:poll`; the new component must not introduce one.

**Interfaces:**
- Produces:
  - `<x-form-error name="field_name" />` rendering:
    ```blade
    @props(['name'])
    @error($name)
        <p {{ $attributes->merge(['class' => 'mt-1 text-xs text-error']) }} role="alert">{{ $message }}</p>
    @enderror
    ```

- [x] **Step 1: Write the failing UI convention test in `tests/Feature/Architecture/UiArchitectureTest.php`**

Assert:
1. `<x-form-error name="email" />` renders `<p class="mt-1 text-xs text-error" role="alert">` when validation error `'email'` is present in `$errors` and renders empty string when absent.
2. Zero raw `@error(` directives remain in `app-modules/*/resources/views/**/*.blade.php` (only allowed inside `resources/views/components/form-error.blade.php`).

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Architecture/UiArchitectureTest.php`
Expected: FAIL (362 raw `@error` blocks found across 64 module view files).

- [x] **Step 3: Create `form-error.blade.php` and migrate all `@error` blocks**

Create `resources/views/components/form-error.blade.php` and replace the 8 inconsistent `@error(...) ... @enderror` class variants in all 64 module view files with `<x-form-error name="..." />`. Work module-by-module and keep `optimize:clear` out of the inner loop — run it once per batch.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pest tests/Feature/Architecture/UiArchitectureTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add resources/views/components/form-error.blade.php app-modules/ resources/views/ tests/Feature/Architecture/UiArchitectureTest.php
git commit -m "ui(forms): standardize all validation errors onto shared <x-form-error> component"
```

---

### Task 15: `<x-page-header>` Component, Layout Quick Wins & `settings-edit.blade.php` Modernization (`U6`, `U8`, `§2.4`)

**Files:**
- Create: `resources/views/components/page-header.blade.php`
- Modify: `app-modules/admin/resources/views/settings-edit.blade.php`
- Modify: `resources/views/layouts/app.blade.php:65-130`
- Modify: `resources/views/components/dashboard/stats.blade.php:170-176`
- Modify: 127 list/edit Blade views repeating `text-2xl font-bold tracking-tight` header markup
- Modify: `tests/Feature/Architecture/UiArchitectureTest.php` (append assertions)

**Test Impact (Phase 3A — read before starting):** `UiArchitectureTest` currently counts raw `<h1|h2 class="…text-2xl…">` markup and asserts `>= 125` matches; the live count is exactly **127 headings across 127 files**. Replacing those 127 headers with `<x-page-header>` drops the count to ~1 and turns that test red. The same commit MUST convert the assertion to count `<x-page-header` usages (plus the single heading inside the component) instead of raw markup. Failing to do this makes Phase 3A unshippable.

**Interfaces:**
- Produces:
  - `<x-page-header :title="__('admin.extensions')" :tooltip="__('admin.extensions_tooltip')" :subtitle="null">` with named `actions` slot for right-aligned toolbar buttons (`btn btn-primary btn-sm`, etc.).

- [x] **Step 1: Extend `UiArchitectureTest.php` with failing assertions for `settings-edit`, `layouts/app`, and `<x-page-header>`**

Assert:
1. `settings-edit.blade.php` uses `<x-page-header>`, `btn-sm` on its primary action, `<x-icon-button>` (or explicit `aria-label` + `w-4 h-4`) on row actions, `text-xs` table cells, and translated empty-state copy (`__('admin.settings_empty')`).
2. `resources/views/layouts/app.blade.php` contains zero raw `class="tooltip` divs (uses `<x-tooltip>` only) and translates `aria-label="{{ __('admin.close_sidebar') }}"`. Match on the **prefix** `class="tooltip` — the file currently holds two raw divs at `:92` and `:123` written as `class="tooltip tooltip-right"`, so an exact-match assertion for `class="tooltip"` passes today and would never fail.
3. `resources/views/components/dashboard/stats.blade.php` uses `text-lg font-semibold` instead of `text-xl font-semibold`.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Architecture/UiArchitectureTest.php`
Expected: FAIL on checks 1 and 3, and on check 2 once the match is corrected to the `class="tooltip` prefix. Check 2 as originally written (exact `class="tooltip"`) already passes — do not "fix" a non-existent defect by inventing work, and do not weaken the assertion to keep it green.

- [x] **Step 3: Implement `<x-page-header>`, overhaul `settings-edit.blade.php`, fix layout quick wins, and migrate page headers**

Create `resources/views/components/page-header.blade.php` matching the established `flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between` + `h1.text-2xl.font-bold.tracking-tight` + optional `<x-tooltip>` pattern, add `close_sidebar` / `settings_*` keys across `en`/`es`/`fr`, modernize `settings-edit.blade.php`, fix `layouts/app.blade.php` and `dashboard/stats.blade.php`, and migrate the 127 copy-pasted page headers.

- [x] **Step 4: Run Phase 3A verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pest tests/Feature/Architecture/UiArchitectureTest.php tests/Feature/Architecture/TranslationParityTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add resources/views/ app-modules/ lang/ tests/Feature/Architecture/UiArchitectureTest.php
git commit -m "ui(layout): introduce <x-page-header> and bring settings-edit and app layout onto standard conventions"
```

---

### Task 16: Binary Feature State Control Unification & 3-Tier Amber Semantics (`U1`, `U2`)

**Files:**
- Modify: `app-modules/admin/resources/views/modules-list.blade.php:95-120`
- Modify: ~52 Blade views using `checkbox` for binary feature flags (`extensions-edit.blade.php`, `acl-edit.blade.php`, `conferences-edit.blade.php`, `ring-groups-edit.blade.php`, `sip-profiles-edit.blade.php`, etc.) and any `toggle-primary` / `toggle-warning` binary switches
- Modify: `tests/Feature/Architecture/UiArchitectureTest.php`

**Interfaces:**
- Produces:
  - Every binary feature enable/disable state uses `toggle toggle-sm toggle-success` when ON (`toggle-neutral` or base neutral when OFF) with state adjective labels (`__('admin.enabled')` / `__('admin.disabled')`).
  - `checkbox` (`class="checkbox..."`) appears ONLY in an explicit allowlist of multi-select list views (e.g., `groups-edit.blade.php` permissions matrix, `admins-edit.blade.php` group checkboxes, `users-edit.blade.php` tenant/group checkboxes, bulk-selection tables). Measured baseline: **63** `class="checkbox` occurrences.
  - `toggle-warning` is confined to a **one-file allowlist**: `app-modules/security/resources/views/partials/firewall-switches.blade.php` (the global observe-mode switch). Observe mode is not an enabled/disabled pair — it is the "rules loaded but not enforcing" dimension of an already-enabled firewall, and the amber toggle is the deliberate "you are not currently protected" affordance. Convert the two other live sites: `app-modules/admin/resources/views/modules-list.blade.php:105` (OFF state must be `toggle-neutral`) and `app-modules/security/resources/views/tabs/firewall-rules.blade.php:528` (TFTP defense is a plain binary flag → `toggle-success` / `toggle-neutral`).

**Test Impact (Phase 3B):** `UiArchitectureTest` already has a toggle test requiring every `toggle` input to carry an explicit size token AND `wire:loading.attr="disabled"`. Every one of the ~52 checkbox→toggle conversions must therefore ship with `toggle-sm` plus a `wire:loading.attr="disabled"` lock (and the `wire:loading.class="opacity-70 pointer-events-none"` wrapper per Pattern 2), or the existing toggle test goes red. Add this requirement to the per-view conversion checklist, not as a later cleanup pass.

- [x] **Step 1: Add failing `checkbox` allowlist and zero-`toggle-warning` assertions to `UiArchitectureTest.php`**

Scan all Blade files asserting that `toggle-warning` appears only inside `partials/firewall-switches.blade.php` and that `checkbox` appears only in the explicit multi-select allowlist.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Architecture/UiArchitectureTest.php`
Expected: FAIL (`modules-list.blade.php:105` and `firewall-rules.blade.php:528` use `toggle-warning`; 63 `class="checkbox` sites exist outside the multi-select allowlist).

- [x] **Step 3: Migrate binary checkboxes to `toggle toggle-sm toggle-success` and restrict `toggle-warning` to the observe-mode allowlist**

Update `modules-list.blade.php` so enabled modules render `toggle toggle-sm toggle-success` and disabled modules render `toggle toggle-sm toggle-neutral` (never `toggle-warning`), convert the TFTP-defense toggle at `firewall-rules.blade.php:528` to `toggle-success` / `toggle-neutral`, and leave the observe-mode switch in `firewall-switches.blade.php` on `toggle-warning` as the documented exception. Convert the remaining binary state checkboxes across edit/settings views to `toggle toggle-sm toggle-success` — each conversion must include `wire:loading.attr="disabled"` so the pre-existing toggle test keeps passing.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pest tests/Feature/Architecture/UiArchitectureTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add app-modules/ resources/views/ tests/Feature/Architecture/UiArchitectureTest.php
git commit -m "ui(controls): unify binary feature states onto toggle-success and reserve warning for idle states"
```

---

### Task 17: Loading Dimming, `wire:navigate`, Scrims, Badges, Typography & Accessible Labels (`U5`, `U7`, `U9`–`U13`, `§2.4`)

**Files:**
- Modify: `app-modules/security/resources/views/tabs/geo-blocking.blade.php:170` (`opacity-60` → `opacity-70` or `opacity-40` per element role)
- Modify: `app-modules/security/resources/views/tabs/protection-settings.blade.php:15` (`bg-black/40` → `bg-black/50`)
- Modify: `app-modules/security/resources/views/tabs/firewall-rules.blade.php` (remove 21 redundant `h-auto py-0.5 leading-tight` badge overrides; add `aria-label` / `<x-icon-button>` to icon-only actions at `:682,685,761`)
- Modify: `app-modules/security/resources/views/tabs/block-allow.blade.php` (add accessible labels on `btn-square` controls)
- Modify: 55 internal `route('panel.*')` `<a>` links lacking `wire:navigate` across `app-modules/*/resources/views/` (measured: 109 internal panel links total, 55 without `wire:navigate` and without a `download`/`target="_blank"` exemption)
- Modify: Secondary helper text (51 `text-sm text-base-content/60` sites → `text-xs`), icon-size outliers (8 `w-2`/`w-8` replaced, 25 `w-3`/`w-6` reviewed and allowlisted), input class ordering (`input input-bordered input-sm`), and `font-mono` on technical table-cell badges (visual review)
- Modify: `tests/Feature/Architecture/UiArchitectureTest.php`

**Test Impact (Phase 3B):** `SecurityManagerLivewireTest` asserts the 35 existing `<tr>` `opacity-40 pointer-events-none` usages — they must be preserved exactly. `UiArchitectureTest`'s font-size allowlist forbids any `text-[NNpx]` class, so the secondary-text normalization must use `text-xs` plus an opacity modifier rather than a new arbitrary size.

**Interfaces:**
- Produces:
  - Two-tier loading dimming strictly enforced: `opacity-40 pointer-events-none` on `<tr>` rows ONLY; `opacity-70 pointer-events-none` on switches/buttons ONLY; zero `opacity-60 pointer-events-none`.
  - 100% of internal `<a href="{{ route('panel.*') }}">` links carry `wire:navigate` (unless explicitly marked `download` or `target="_blank"`).
  - Every `btn-square` / icon-only `<button>` carries either `<x-icon-button>` or a non-empty `aria-label`; every binary `toggle`/`checkbox` input has an accessible name (its own `aria-label`/`aria-labelledby`, or an enclosing `<label>` with text).
  - Modal/drawer backdrop scrims standardized on `bg-black/50`. **Explicit exemption:** the `bg-black/15` value is a toast *progress track*, not a scrim — it stays and is recorded in the test's allowed list so the third value from U9 is dispositioned rather than forgotten.
  - **Secondary-text scale (U7):** helper copy uses `text-xs text-base-content/60`; the 51 `text-sm text-base-content/60` sites are migrated down.
  - **Icon sizing (U11):** `w-2 h-2` and `w-8 h-8` are eliminated; the 13 `w-3 h-3` and 12 `w-6 h-6` occurrences survive only inside a reviewed literal allowlist.
- **Review-checked, not test-gated:** `font-mono` on table-cell badges and the absence of skeleton loaders on static pages cannot be asserted reliably by regex against positional Blade markup. They are verified by visual review in Step 3 and recorded in the task's completion note. §2.4's "0 skeleton loaders" is **not** treated as a defect for static pages — see Task 19 for the live-list case, which is the only place a skeleton is required.

- [x] **Step 1: Add failing convention checks for dimming, navigation, scrims, typography, icon sizing, and accessible names in `UiArchitectureTest.php`**

Assert across all Blade templates in `app-modules/` and `resources/views/`:
1. Zero occurrences of `opacity-60 pointer-events-none`; `opacity-40 pointer-events-none` appears only on `<tr` elements; `opacity-70 pointer-events-none` appears on non-`<tr>` interactive wrappers/buttons.
2. Zero occurrences of `bg-black/40` on modal/drawer backdrops and zero occurrences of redundant `h-auto py-0.5 leading-tight` on `.badge` elements in `firewall-rules.blade.php`. The assertion's allowed list explicitly records `bg-black/15` as the exempt toast progress track.
3. Every `<a` tag containing `route('panel.` without `target="_blank"` or `download` includes `wire:navigate`.
4. Every `<button` with `btn-square` (outside `<x-icon-button>`) carries `aria-label=`.
5. **(U7)** Zero occurrences of `text-sm text-base-content/60`, and the opacity modifiers used with `text-xs` are limited to `{60, 70}`. Before writing this assertion, run `grep -rn 'text-xs text-base-content/[0-9]' app-modules resources/views`, review every `/40`, `/50`, and `/80` hit, and record the accepted exceptions as an **explicit literal allowlist array in the test** — do not leave the exception set implicit or it becomes unmaintainable.
6. **(U11)** Zero `w-2 h-2` and zero `w-8 h-8`. `w-3 h-3` and `w-6 h-6` are confined to a literal allowlist generated from the pre-migration `grep -rln 'w-3 h-3\|w-6 h-6'`, with each entry reviewed against its context (badge-internal compact icon vs page-level icon). Because the list lives in the test, any *new* outlier fails even though the reviewed 25 are accepted.
7. **(U13)** Every `<input type="checkbox">` whose class contains `toggle` or `checkbox` either carries `aria-label`/`aria-labelledby`, or sits inside an enclosing `<label>` element containing non-empty text (`__('…')` or a literal word). Resolve "enclosing" by scanning backwards to the nearest `<label` and forwards to its `</label>`.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Architecture/UiArchitectureTest.php`
Expected: FAIL on all seven assertions — `opacity-60` in `geo-blocking`; `bg-black/40` in `protection-settings`; 55 links missing `wire:navigate`; unlabeled `btn-square` buttons in `block-allow` / `firewall-rules`; 51 `text-sm text-base-content/60` sites; 8 indefensible icon sizes plus 25 unrecorded `w-3`/`w-6` occurrences; unlabelled toggle/checkbox inputs in `block-allow`.

- [x] **Step 3: Apply the mechanical UI normalizations across views**

Keep all 35 `<tr>` `opacity-40 pointer-events-none` usages intact (preserving Pattern 3 and `SecurityManagerLivewireTest`), fix `geo-blocking.blade.php:170`, standardize drawer scrim to `bg-black/50`, strip the 21 dead badge overrides in `firewall-rules.blade.php`, add `wire:navigate` to all 55 internal panel links, normalize secondary helper copy to `text-xs text-base-content/60` (the 51 `text-sm` sites), replace `w-2 h-2`/`w-8 h-8` icons with `w-4 h-4` (or `w-3 h-3` where the icon sits inside a `badge-xs`), add `font-mono` to technical identifier badges in tables, and add translated `aria-label`s to every icon-only button and standalone toggle/checkbox.

Work module-by-module and keep `optimize:clear` out of the inner loop — one run per batch. In the completion note, list the files where `font-mono` badges were added and confirm the static pages intentionally have no skeleton loaders.

- [x] **Step 4: Run Phase 3B verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pest tests/Feature/Architecture/UiArchitectureTest.php app-modules/security/tests/`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add app-modules/ resources/views/ lang/ tests/Feature/Architecture/UiArchitectureTest.php
git commit -m "ui(polish): normalize wire:navigate links, scrims, badges, dimming outliers, and accessible labels"
```

---

### Task 18: Edit/Create View Localization Sweep & Root Dictionary Deduplication (`U3`)

**Files:**
- Modify: `lang/en/admin.php`, `lang/es/admin.php`, `lang/fr/admin.php` (remove duplicate declarations of `active_calls`, `email`, `tenants`, `groups`, `administrator` while keeping all 527+ unique keys in 100% parity)
- Modify: `app-modules/extensions/resources/views/extensions-list.blade.php:29` (replace literal `'Voicemail'` tooltip with `__('admin.extensions_voicemail_tooltip')`)
- Modify: ~50 `app-modules/*/resources/views/*-edit.blade.php` views and corresponding `app-modules/*/lang/{en,es,fr}/admin.php` dictionaries
- Modify: `tests/Feature/Architecture/TranslationParityTest.php`

**Test Impact (Phase 3C):** `TranslationParityTest` already imports `App\Support\ModuleServiceProvider` and calls `clearRootTranslationKeysCache()` / `clearModuleTranslationKeysCache()` in its setup — keep those hooks working (they belong to the base provider that Task 21 decomposes, so this task and Task 21 must not land in conflicting states). `UiArchitectureTest`'s localized-table-header assertion scans the same views; a newly localized `<th>` must remain a `__()` call, not a literal.

**Interfaces:**
- Produces:
  - Zero duplicate array keys inside `lang/{en,es,fr}/admin.php` and `app-modules/*/lang/{en,es,fr}/admin.php` (parsed via AST / token scan, since `require` silently overwrites duplicates in PHP).
  - Zero hardcoded English `<span class="label-text...">Literal</span>`, `'Create ...' : 'Edit ...'` ternaries, or `<option value="">Select ...</option>` strings in `app-modules/*/resources/views/*-edit.blade.php`.

- [x] **Step 1: Add failing duplicate-key AST check and edit-view hardcoded-label scan to `tests/Feature/Architecture/TranslationParityTest.php`**

1. Tokenize every `lang/*/*.php` and `app-modules/*/lang/*/*.php` file with `token_get_all()` to detect duplicate string keys in returned arrays (`active_calls`, `email`, `tenants`, `groups`, `administrator` currently fail in `en` and `es`).
2. Scan `app-modules/*/resources/views/*-edit.blade.php` asserting `<span class="label-text` and `<option value="">` contents invoke `{{ __(...) }}` rather than raw English words.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php`
Expected: FAIL (duplicate keys in `lang/en/admin.php` and `lang/es/admin.php`; ~50 edit views contain hardcoded English labels).

- [x] **Step 3: Deduplicate root dictionaries and localize all ~50 edit/create views across `en`, `es`, and `fr`**

1. Remove the 5 duplicate key lines in `lang/en/admin.php` and `lang/es/admin.php`.
2. Reuse shared root keys where they already exist (`__('admin.tenant')`, `__('admin.enabled')`, `__('admin.disabled')`, `__('admin.description')`, `__('admin.name')`, `__('admin.save')`, `__('admin.cancel')`) — NEVER redeclare root keys in module dictionaries.
3. Add module-specific field labels and placeholders to `app-modules/<module>/lang/{en,es,fr}/admin.php` prefixed by entity slug (e.g. `sip_profile_name`, `acl_select_tenant`) with the mandatory collision-warning PHP header, and update the ~50 edit views plus `extensions-list.blade.php:29`.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php`
Expected: PASS (100% parity across `en`, `es`, `fr`, zero root or cross-module collisions, zero duplicate keys, zero hardcoded form labels).

- [x] **Step 5: Commit**

```bash
git add lang/ app-modules/ tests/Feature/Architecture/TranslationParityTest.php
git commit -m "i18n(panel): localize module edit forms across en/es/fr and deduplicate root language keys"
```

---

### Task 19: Push-Compliant Echo WebSocket Health Banner & Auto-Resync on Reconnect (`U14`)

> **Constraint Warning:** Per `Global Constraints` and `TelephonyPushBroadcastingTest`, **DO NOT** add `wire:poll` or manual refresh buttons to `active-calls`, `active-conferences`, `call-center-active`, `registrations`, `operator-panel`, or dashboard views.

**Files:**
- Create: `resources/views/components/websocket-health-banner.blade.php`
- Modify: `resources/js/echo.js` (or `resources/js/app.js` where `window.Echo` is initialized)
- Modify: `app-modules/active-calls/src/Livewire/ActiveCallsList.php`, `app-modules/active-conferences/src/Livewire/ActiveConferencesList.php`, `app-modules/call-center-active/src/Livewire/CallCenterActiveList.php`, `app-modules/registrations/src/Livewire/RegistrationsList.php`, `app-modules/operator-panel/src/Livewire/OperatorPanelIndex.php` (+ include `<x-websocket-health-banner />` in their Blade views)
- Modify: `tests/Feature/Telephony/TelephonyPushBroadcastingTest.php`

**Test Impact (Phase 3C) — two hard constraints:**
1. `TelephonyPushBroadcastingTest` asserts an **exact sorted array** of every Blade file containing `wire:poll`, currently three entries (`admin/git-update`, `backups/restore-archive`, `logs/log-viewer`). Any new file that mentions `wire:poll` — including the banner component or a partial — makes that exact-match assertion fail.
2. Task 2's exhaustive reflection test iterates every public action method of every `Modules\*` Livewire component and asserts `LivewireActionPermissions::abilitiesFor()` returns at least one ability group. The new `resyncAfterReconnect()` is a public method, so it MUST be added to each component's `livewireActionAbilities()` map (e.g. `'resyncAfterReconnect' => [['<module>.view']]`) or classified as an allowlisted UI/read helper — otherwise Task 2's fail-closed gate turns the reconnect resync into a 403 and the reflection test fails.

**Interfaces:**
- Produces:
  - Browser custom events `tallpbx:ws-disconnected` and `tallpbx:ws-reconnected` emitted from `window.Echo.connector.pusher.connection.bind('state_change', ...)` whenever connection transitions out of `'connected'` (`unavailable`, `failed`, `disconnected`) and when transitioning back to `'connected'` after a drop.
  - Alpine-driven `<x-websocket-health-banner />` (`alert alert-warning text-xs` hidden while connected, visible during disconnect, dispatching `Livewire.dispatch('echo-reconnected')` once upon recovery) plus subtle skeleton shimmer rows (`animate-pulse`) during initial mount/resync (`§2.4`).
  - `#[On('echo-reconnected')] public function resyncAfterReconnect(): void` on live telephony components.

- [x] **Step 1: Add failing tests in `tests/Feature/Telephony/TelephonyPushBroadcastingTest.php`**

Assert:
1. All live telephony components listen to `#[On('echo-reconnected')]` and reload their live state when `Livewire::test(...)->dispatch('echo-reconnected')` is triggered.
2. All live telephony views render `<x-websocket-health-banner` (connection-state indicator) and STILL contain zero `wire:poll` directives and zero manual refresh buttons.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Telephony/TelephonyPushBroadcastingTest.php`
Expected: FAIL (`echo-reconnected` listener and `<x-websocket-health-banner>` missing).

- [x] **Step 3: Implement Echo state-change bridge, `<x-websocket-health-banner />`, and `#[On('echo-reconnected')]` handlers**

Wire Pusher/Reverb `state_change` listener in `resources/js/echo.js`, build `resources/views/components/websocket-health-banner.blade.php` with translated `en`/`es`/`fr` warning copy (`__('admin.realtime_reconnecting')`), add `#[On('echo-reconnected')]` to the 5 live telephony components, and run `npm run build` so compiled Vite assets stay in sync.

- [x] **Step 4: Run Phase 3C verification gate**

Run:
```bash
npm run build
php artisan optimize:clear
./vendor/bin/pint --dirty --test
./vendor/bin/pest tests/Feature/Telephony/TelephonyPushBroadcastingTest.php tests/Feature/Architecture/TranslationParityTest.php tests/Feature/Architecture/UiArchitectureTest.php tests/Feature/LivewireActionPermissionTest.php
php artisan test --compact --parallel
```
Expected: All Phase 3C, Phase 3A/3B and full parallel suite tests PASS.

- [x] **Step 5: Commit**

```bash
git add resources/ app-modules/ lang/ public/build/ tests/Feature/Telephony/TelephonyPushBroadcastingTest.php
git commit -m "feat(realtime): add push-compliant WebSocket disconnect banner and reconnect resync"
```

---

## Phase 4 — Architectural Decomposition & SDD Hygiene

### Task 20: Shared `XmlWriter` Support Class for `DialplanXmlBuilder` & `DirectoryXmlBuilder` (`M4`)

**Files:**
- Create: `app/Support/Xml/XmlWriter.php`
- Create: `tests/Unit/Support/XmlWriterTest.php`
- Modify: `app/Services/Xml/DialplanXmlBuilder.php`
- Modify: `app/Services/Xml/DirectoryXmlBuilder.php`

**Interfaces:**
- Produces:
  - `App\Support\Xml\XmlWriter`:
    - `public function startDocument(): self`
    - `public function openTag(string $tag, array $attributes = [], int $indent = 0): self`
    - `public function selfClosingTag(string $tag, array $attributes = [], int $indent = 0): self`
    - `public function closeTag(string $tag, int $indent = 0): self`
    - `public function rawTrustedFragment(string $xml): self`
    - `public function isAllowedTag(string $tag, array $allowlist): bool`
    - `public function toString(): string`

- [x] **Step 1: Write unit tests in `tests/Unit/Support/XmlWriterTest.php`**

Verify `ENT_XML1 | ENT_QUOTES` escaping of `<`, `>`, `&`, `"`, `'`, null/empty attribute omission, indentation, and tag allowlist rejection of invalid element names.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Unit/Support/XmlWriterTest.php`
Expected: FAIL (`Class "App\Support\Xml\XmlWriter" not found`).

- [x] **Step 3: Implement `XmlWriter` and refactor `DialplanXmlBuilder` & `DirectoryXmlBuilder` to use it**

Ensure byte-for-byte identical FreeSWITCH XML output on all existing dialplan and directory feature tests.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Unit/Support/XmlWriterTest.php tests/Feature/XmlHandler* tests/Feature/Pbx/`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add app/Support/Xml/XmlWriter.php app/Services/Xml/DialplanXmlBuilder.php app/Services/Xml/DirectoryXmlBuilder.php tests/Unit/Support/XmlWriterTest.php
git commit -m "refactor(xml): extract shared XmlWriter across DialplanXmlBuilder and DirectoryXmlBuilder"
```

---

### Task 21: Decompose `ModuleServiceProvider` & `ModuleLifecycleService` into Collaborators (`M3`)

**Files:**
- Create: `app/Services/Modules/ModuleManifestLoader.php`
- Create: `app/Services/Modules/ModuleRouteRegistrar.php`
- Create: `app/Services/Modules/ModulePermissionRegistrar.php`
- Create: `app/Services/Modules/ModuleProtectionPolicy.php`
- Modify: `app/Providers/ModuleServiceProvider.php` (**498 lines** — discovery, manifest scanning, name-collision rejection, `uasort` by priority. This is the orchestrator; it shrinks by delegating discovery to `ModuleManifestLoader` and ordering to `ModuleDependencySorter`.)
- Modify: `app/Support/ModuleServiceProvider.php` (**643 lines** — the actual god class: `boot()`, `registerViews()`, `registerTranslations()`, `registerMigrations()`, menu registration, permission registration, listener/command registration, and the root/module translation-collision detectors. This is the file the "~644 lines" figure in earlier drafts referred to, and it is the primary decomposition target.)
- Modify: `app/Services/ModuleLifecycleService.php` (**696 lines** — delegate manifest loading and protection policy)
- Test: `tests/Feature/Services/ModuleCollaboratorsTest.php` (create)

> **File-target correction:** earlier drafts named only `app/Providers/ModuleServiceProvider.php` and called it "~644 lines". It is 498 lines; the 643-line class is the base provider in `app/Support/`. Decomposing the wrong file would leave the real god class untouched.

**Interfaces:**
- Consumes: `ModuleDependencySorter::sort()`
- Produces:
  - `ModuleManifestLoader::discoverAll(): array<string, array>`
  - `ModuleProtectionPolicy::isProtected(array $manifest): bool` & `assertCanUninstall(array $manifest): void`
  - `ModuleRouteRegistrar::registerForModule(string $moduleName, string $modulePath): void` (owning route-collision detection)
  - `ModulePermissionRegistrar::registerForModule(string $moduleName, array $permissions): void`

- [x] **Step 1: Write unit/feature tests for the 4 extracted classes in `tests/Feature/Services/ModuleCollaboratorsTest.php`**

Cover manifest discovery, route-name collision guard, translation collision guard preservation, and `ModuleProtectionPolicy::isProtected()` returning `true` whenever `protected: true` OR `required: true`.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Services/ModuleCollaboratorsTest.php`
Expected: FAIL (collaborator classes do not exist yet).

- [x] **Step 3: Extract the 4 focused collaborators and delegate from both module providers**

Preserve every existing public/protected method signature on `App\Support\ModuleServiceProvider` — including the static `clearRootTranslationKeysCache()` and `clearModuleTranslationKeysCache()` that `tests/Feature/Architecture/TranslationParityTest.php:10-20` calls, and the overridable hooks (`moduleName()`, `moduleNamespace()`, `menuItems()`, `permissions()`, `listeners()`, `consoleCommands()`) that every one of the 60 module providers implements. Preserve the public API of `App\Providers\ModuleServiceProvider` and `ModuleLifecycleService` as well. Move code; do not redesign signatures — every module's `ModuleServiceProvider` subclass must keep booting unmodified.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pint --dirty --test && ./vendor/bin/pest tests/Feature/Services/ModuleCollaboratorsTest.php tests/Feature/Services/ModuleLifecycleServiceTest.php tests/Feature/Commands/ModuleLifecycleCommandsTest.php tests/Feature/Architecture/TranslationParityTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add app/Services/Modules/ app/Providers/ModuleServiceProvider.php app/Support/ModuleServiceProvider.php app/Services/ModuleLifecycleService.php tests/Feature/Services/ModuleCollaboratorsTest.php
git commit -m "refactor(modules): decompose ModuleServiceProvider and ModuleLifecycleService into focused collaborators"
```

---

### Task 22: Shared `<x-tabs>` Component & Split Oversized Blade Views >300 Lines (`U15`, `U16`)

**Files:**
- Create: `resources/views/components/tabs.blade.php`
- Create: Extracted sub-partials under `app-modules/certificates/resources/views/partials/`, `app-modules/security/resources/views/tabs/partials/`, `app-modules/email-connector/resources/views/partials/`, `app-modules/admin/resources/views/partials/`
- Modify: All 6 Blade views currently exceeding 300 lines (`app-modules/certificates/resources/views/certificate-manager.blade.php` [827], `app-modules/security/resources/views/tabs/firewall-rules.blade.php` [772], `app-modules/email-connector/resources/views/email-connector-edit.blade.php` [363], `app-modules/admin/resources/views/git-update.blade.php` [354], `app-modules/security/resources/views/tabs/attackers.blade.php` [343], `app-modules/security/resources/views/tabs/block-allow.blade.php` [322])
- Modify: `tests/Feature/Architecture/UiArchitectureTest.php`

**Test Impact (Phase 4):** the `<= 300` line rule only inspects **top-level** files directly under `app-modules/*/resources/views/` and `app-modules/*/resources/views/tabs/`; the extracted `views/partials/*` files are deliberately exempt, so partials cannot re-trigger the rule. `TelephonyPushBroadcastingTest` scans every Blade file for `wire:poll` and asserts an exact three-file list — a new partial must not mention it. `SecurityManagerLivewireTest` and `GitUpdateTest` assert behaviour rendered through `firewall-rules.blade.php` and `git-update.blade.php`, so `@include` boundaries must not move `wire:key` values or Livewire property bindings.

**Interfaces:**
- Produces:
  - `<x-tabs :tabs="$tabs" :active="$activeTab" wire-method="setTab" />` with accessible `role="tablist"` / `role="tab"` / `aria-selected`.
  - Architecture assertion that every top-level Blade template under `app-modules/*/resources/views/` is `<= 300` lines.

- [x] **Step 1: Add failing `<= 300` line-count check and `<x-tabs>` render test in `UiArchitectureTest.php`**

Assert `Blade::render('<x-tabs :tabs="[\'general\' => \'General\']" active="general" wire-method="setTab" />')` renders `role="tablist"`, `role="tab"`, and `aria-selected="true"`, and assert every top-level `.blade.php` file directly under `app-modules/*/resources/views/` and `app-modules/*/resources/views/tabs/` has `<= 300` lines.

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Architecture/UiArchitectureTest.php`
Expected: FAIL (6 Blade files exceed 300 lines).

- [x] **Step 3: Create `<x-tabs>` and extract cohesive sub-partials from the 6 oversized views**

Keep Livewire `wire:key` and parent component property bindings intact (`@include('...::partials....')`) so zero Livewire snapshot or event boundaries change.

- [x] **Step 4: Run verification gate**

Run: `php artisan optimize:clear && ./vendor/bin/pest tests/Feature/Architecture/UiArchitectureTest.php app-modules/security/tests/ app-modules/certificates/tests/ tests/Feature/Livewire/GitUpdateTest.php`
Expected: PASS.

- [x] **Step 5: Commit**

```bash
git add resources/views/components/tabs.blade.php app-modules/ tests/Feature/Architecture/UiArchitectureTest.php
git commit -m "refactor(ui): introduce shared <x-tabs> component and split Blade views over 300 lines"
```

---

### Task 23: `.superpowers/sdd/` Ledger Hygiene, Transaction Convention & `CHANGELOG.md` Update (`L4`, `§1.7`, `§1.8`)

**Files:**
- Delete (local untracked): `.superpowers/sdd/one-line-installer-implementation-plan/` (the empty directory — note the real name carries the `-implementation-plan` suffix; a path without it does not exist)
- Modify (local untracked): `.superpowers/sdd/admin-panel-authorization-implementation-plan/progress.md` (document the full 20/20 component remediation completed in Task 2)
- Modify (local untracked): `.superpowers/sdd/progress.md` (**complete rewrite** — the file is 3 lines: a stray title `# File Stores And Full Backup Restore Execution Ledger` plus `Task 3.1: in progress.` It has no index to edit, so replace it wholesale)
- Modify: `AGENTS.md` (document the explicit `DB::transaction()` boundary convention for multi-service orchestration flows per `§1.7`)
- Modify: `CHANGELOG.md` (summarize security, performance, UI, and localization improvements under `## [Unreleased]` — **do NOT cut `## [4.2.0]`, create a `v4.2.0` tag, or `git push` yet**)
- Create: `tests/Feature/Architecture/SddLedgerHygieneTest.php`

> **Gitignore & transient SDD workspace note.** `/.superpowers` is ignored in `.gitignore:9` (and `.superpowers/sdd/.gitignore` ignores `*`), so `.superpowers/` must NEVER be passed to `git add` (which fails with `exit 1`). Additionally, `SddLedgerHygieneTest.php` must (a) skip cleanly when `! is_dir(base_path('.superpowers/sdd'))` so CI passes on fresh checkouts, and (b) exempt any active in-flight SDD workspace directory whose `# SDD ledger — plan: <path>` target file currently exists on disk (such as `docs/comprehensive-code-review-implementation-plan.md` during SDD execution) from requiring a static row in `.superpowers/sdd/progress.md`, so removing the transient workspace at SDD completion does not break the test.

> **Source documents already removed.** `docs/comprehensive-code-and-ui-review.md` and `docs/comprehensive-code-and-ui-review-update.md` were deleted when this plan was committed, and this plan became the tracked remediation record. Task 23 must therefore keep the `CHANGELOG.md` entry **self-contained**: describe administrator-visible behaviour in plain language and do not cite finding IDs, section numbers, or review-document passages that no reader can open.

**Interfaces:**
- Produces: an accurate local `.superpowers/sdd/progress.md` index with zero empty directories, and a defined archive convention so historical ledgers stop asserting an auditable plan that no longer exists.
- **Data-model correction:** there is no `plan-path` field anywhere in `.superpowers/sdd/`. The pointer is the first line of each ledger directory's `progress.md`, formatted `# SDD ledger — plan: docs/<name>.md`. **Measured: 18 ledgers carry such a line and all 18 resolve to a missing file** — not three, as earlier drafts claimed. The archive convention must therefore cover 18 directories, not a handful.

- [x] **Step 1: Write the failing hygiene check in `tests/Feature/Architecture/SddLedgerHygieneTest.php`**

Guard with `if (! is_dir(base_path('.superpowers/sdd'))) { $this->markTestSkipped('No local .superpowers/sdd ledger directory present.'); }`, then assert:
1. `.superpowers/sdd/` contains no empty subdirectories.
2. For every `<dir>/progress.md` whose first line matches `/^# SDD ledger — plan: (\S+)/`, the referenced path either exists on disk **or** the ledger is explicitly archived by a line containing `> **Archived:**` in its first 10 lines.
3. `.superpowers/sdd/progress.md` lists every archived/completed ledger directory whose referenced plan file no longer exists on disk, and contains zero references to non-existent directories (active in-flight SDD workspaces whose plan file exists on disk are exempt so transient SDD runs can be cleaned up without breaking the index).

- [x] **Step 2: Run test to verify it fails**

Run: `./vendor/bin/pest tests/Feature/Architecture/SddLedgerHygieneTest.php`
Expected: FAIL (`one-line-installer-implementation-plan` is empty; 18 ledgers point at deleted `docs/` plans; `progress.md` lists nothing).

- [x] **Step 3: Clean `.superpowers/sdd/`, update `AGENTS.md` & `CHANGELOG.md`, and run final verification**

1. Remove the empty `one-line-installer-implementation-plan` directory.
2. Add a standard archive annotation (`> **Archived:** source plan removed from docs/; execution summary retained.`) beneath the H1 of each of the 18 ledgers whose plan document is gone, including `admin-panel-authorization-implementation-plan` — which additionally gets the corrected 20/20 component status, replacing the misleading "Sweep: admin 12…" summary that made the admin panel look authorized while 19 of 20 components were open (C1).
3. Rewrite `.superpowers/sdd/progress.md` as an index: one row per archived/historical ledger directory, with status (`shipped` / `archived`) and a note when the source plan was removed.
4. Document the explicit `DB::transaction()` boundary convention for multi-service orchestration flows in `AGENTS.md`.
5. Record all user/admin-facing remediations under `## [Unreleased]` (`### Security`, `### Changed`, `### Fixed`) in `CHANGELOG.md`, noting that upgrading requires `php artisan migrate` (admin permission grants + compound index migrations), `php artisan permissions:sync`, and `npm run build` (handled automatically by the web Git Updater). Keep entries under `## [Unreleased]`; `v4.2.0` will be dated and tagged only when the user explicitly authorizes pushing the release.

- [x] **Step 4: Run final full-branch verification gate**

Run:
```bash
php artisan optimize:clear
./vendor/bin/pint --dirty --test
php artisan test --compact --parallel
```
Expected: 100% PASS across the entire test suite with zero Pint errors.

- [x] **Step 5: Commit (local `4.x` only — DO NOT tag or push)**

```bash
git add AGENTS.md CHANGELOG.md tests/Feature/Architecture/SddLedgerHygieneTest.php
git commit -m "chore(sdd,changelog): reconcile SDD ledgers and document code/UI review remediations"
```
