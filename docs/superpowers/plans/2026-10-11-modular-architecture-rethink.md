# TallPBX 4.x Modular Architecture Modernization Implementation Plan

> **Independent review**: [2026-10-12 review](../reviews/2026-10-12-modular-architecture-rethink-review.md) — baseline findings register (F1–F12) covering this plan, the spec, and the Task 1–2 implementation state. Read before amending or executing tasks.

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Modernize TallPBX's modular architecture on branch `4.x` by removing the 60 Composer path repositories in favor of direct PSR-4 autoloading, establishing a safe `custom-modules/` extensibility boundary with collision guards, cleaning up Backups as an autonomous system-superadmin utility, and organizing `/panel/modules` by UI navigation subcategories.

**Architecture:** Domain-Driven Modular Monolith where all 60 core modules in `app-modules/` are updated atomically via Git Updater and autoloaded via PSR-4; 3rd-party modules live in `custom-modules/` or `vendor/` and are protected with pre-flight collision guards; Backups is self-contained under Core Administration; and `/panel/modules` mirrors sidebar subcategories with DaisyUI switches.

**Tech Stack:** PHP 8.3+, Laravel 13, Livewire 4, DaisyUI 5, Tailwind v4, Pest 4.

**Spec:** `docs/superpowers/specs/2026-10-11-modular-architecture-rethink-design.md`

## Global Constraints

- Target branch is `4.x`.
- Built-in modules in `app-modules/` must NEVER be deleted from disk or have their git working tree dirtied by lifecycle operations.
- Third-party extensions in `custom-modules/` must be git-ignored (`.gitignore`).
- Every PHP file edit must pass `./vendor/bin/pint --dirty --test`.
- Full Pest test suite must pass via `php artisan test --compact --parallel`.

## Review Focus

- Custom module naming collision: an uploaded module naming itself `extensions` or `admin` must fail discovery with a descriptive error and not register.
- Route prefix collision: a custom module attempting to bind `/panel/login` or `/panel/dashboard` must be rejected before route compilation.
- Language key collision: a custom module colliding with `lang/en/admin.php` keys must have its cascading path revoked and fallback to its explicit namespace.
- Core module uninstall attempt: calling `uninstall()` on an `app-modules/` module must throw a ValidationException refusing file deletion.
- Superadmin backups permission: non-superadmin administrators must be forbidden from running `/panel/backups/restore`.

---

### Task 1: Composer & Autoloading Modernization (COMPLETED)

**Files:**
- Modify: `composer.json`
- Test: `tests/Feature/ModuleAutoloadTest.php`

**Interfaces:**
- Consumes: Existing 60 modules in `app-modules/`
- Produces: Direct PSR-4 autoload mapping in `composer.json` for all `Modules\{Name}\` namespaces; root `composer.json` with 0 path repositories and 0 `tallpbx/module-*` requirements.

- [x] **Step 1: Write tests verifying direct PSR-4 autoloading for all 60 modules** (Completed in `c8d31284`)
- [x] **Step 2: Run test to observe baseline state** (Completed)
- [x] **Step 3: Update `composer.json` to remove 60 path repositories and add 60 PSR-4 mappings** (Completed in `c8d31284`)
- [x] **Step 4: Verify composer dump-autoload performance and test suite** (Completed: 10 tests, 132 assertions pass; dump completes in ~1.5s)
- [x] **Step 5: Commit** (Committed in `c8d31284`: `refactor: replace 60 composer path repos with direct psr-4 autoloading`)

---

### Task 2: Unified Discovery Engine, Dynamic Booting & Custom Module Scaffolding (COMPLETED)

**Files:**
- Modify: `app/Providers/ModuleServiceProvider.php`
- Modify: `app/Console/Commands/ModuleSyncCommand.php`
- Modify: `app/Console/Commands/ModuleCacheCommand.php`
- Modify: `app/Console/Commands/MakeModuleCommand.php`
- Create: `app/Support/CustomModuleAutoloader.php`
- Modify: `.gitignore`
- Test: `tests/Feature/Providers/ModuleServiceProviderTest.php`
- Test: `tests/Feature/Commands/NativeModuleCommandTest.php`

**Interfaces:**
- Consumes: `app-modules/*/module.json`, `custom-modules/*/module.json`, `vendor/*/*/module.json`
- Produces: `modules.manifest` array tagged with `source` (`core`, `custom`, `vendor`); dynamic provider registration for enabled modules; runtime autoloader for `CustomModules\`; streamlined `make:module` scaffolding exclusively into `custom-modules/`.

- [x] **Step 1: Write tests for multi-source module discovery, dynamic provider registration, and custom module scaffolding** (Completed in `728b1816`)
- [x] **Step 2: Run test to verify it fails** (Observed expected failures on missing source tag and missing custom-modules path)
- [x] **Step 3: Implement multi-source discovery, custom autoloader, and custom make:module** (Completed in `728b1816`)
  1. Added `/custom-modules/` to `.gitignore` with tracked `.gitkeep`.
  2. Implemented `app/Support/CustomModuleAutoloader.php` (`spl_autoload_register` for `CustomModules\` namespace).
  3. Updated `app/Providers/ModuleServiceProvider.php` with multi-source discovery (`core`, `custom`, `vendor`) and dynamic registration.
  4. Updated `app/Console/Commands/ModuleSyncCommand.php` and `ModuleCacheCommand.php` to scan `custom-modules/` and assign sources.
  5. Updated `app/Console/Commands/MakeModuleCommand.php` to scaffold exclusively into `custom-modules/{name}/` without `composer.json` and with core module naming collision prevention.
- [x] **Step 4: Run targeted tests to verify they pass** (Completed: 17 tests, 91 assertions pass)
- [x] **Step 5: Commit with documentation** (Committed in `728b1816`: `feat: implement multi-source module discovery, dynamic booting, and custom module scaffolding with documentation`)

---

### Task 3: Custom Module Pre-Flight & Runtime Collision Guards

**Files:**
- Create: `app/Services/ModuleCollisionGuard.php`
- Modify: `app/Support/ModuleServiceProvider.php`
- Modify: `app/Services/PermissionService.php`
- Test: `tests/Feature/Modules/CustomModuleCollisionGuardTest.php`

**Interfaces:**
- Consumes: Discovered manifests, registered routes, database schema, permissions.
- Produces: `ModuleCollisionGuard::validate($manifest)` throws `ModuleCollisionException` on name, route, or table conflicts; safe fallback for language key collisions.

- [ ] **Step 1: Write failing test for collision guards**

In `tests/Feature/Modules/CustomModuleCollisionGuardTest.php`:
1. Test that a custom module with `name: 'extensions'` or `name: 'admin'` is rejected with `ModuleCollisionException`.
2. Test that a custom module attempting to bind reserved route `/panel/login` is rejected.
3. Test that a custom module attempting to create an existing table is rejected.
4. Test that colliding translation keys revoke cascading loader and fallback to namespaced strings.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Modules/CustomModuleCollisionGuardTest.php`
Expected: FAIL (`ModuleCollisionGuard` class does not exist).

- [ ] **Step 3: Implement `ModuleCollisionGuard` and integrate with providers**

1. Create `app/Services/ModuleCollisionGuard.php` with validation methods:
   - `assertNoNameCollision(array $manifest, array $coreModules)`
   - `assertNoRouteCollision(string $route, array $reservedRoutes)`
   - `assertNoTableCollision(string $table, array $reservedTables)`
2. In `app/Support/ModuleServiceProvider.php`:
   - Check translation collisions not just against root dictionary, but against all core modules.
   - If a collision occurs on a custom module, fall back to `loadTranslationsFrom()` without calling `addPath()`.
3. In `app/Services/PermissionService.php`:
   - Ensure a custom module cannot register a permission that already belongs to another module.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Modules/CustomModuleCollisionGuardTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ModuleCollisionGuard.php app/Support/ModuleServiceProvider.php app/Services/PermissionService.php tests/Feature/Modules/CustomModuleCollisionGuardTest.php
git commit -m "feat: implement pre-flight and runtime collision guards for custom modules"
```

---

### Task 4: Backups Module Autonomous Superadmin Cleanup

**Files:**
- Modify: `app-modules/admin/routes/web.php`
- Modify: `app-modules/admin/src/Providers/ModuleServiceProvider.php`
- Create: `app-modules/backups/routes/web.php`
- Modify: `app-modules/backups/src/Providers/ModuleServiceProvider.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `app-modules/backups/tests/Feature/BackupsRouteIndependenceTest.php`

**Interfaces:**
- Consumes: Livewire components `BackupsList`, `BackupsEdit`, `RestoreArchive` in `Modules\Backups\Livewire\`
- Produces: Autonomous Backups route file; Backups menu item and permissions registered in its own provider; zero Backups routes in Admin module.

- [ ] **Step 1: Write failing test verifying Backups routes are registered autonomously**

In `app-modules/backups/tests/Feature/BackupsRouteIndependenceTest.php`:
Assert that `/panel/backups`, `/panel/backups/create`, and `/panel/backups/restore` are accessible and named `panel.backups.*` when Backups module is enabled, without requiring any route definition in `admin/routes/web.php`.

- [ ] **Step 2: Run test to observe baseline state**

Run: `php artisan test app-modules/backups/tests/Feature/BackupsRouteIndependenceTest.php`
Expected: Passes on current legacy routing.

- [ ] **Step 3: Move routes and menus to Backups module and clean AppServiceProvider**

1. Create `app-modules/backups/routes/web.php` defining `/panel/backups` routes with `auth.panel` and `admin.can:backups.*` middleware.
2. In `app-modules/backups/src/Providers/ModuleServiceProvider.php`:
   - Remove `protected function routeGroups(): array { return []; }`.
   - Implement `menuItems()` returning the `'backups'` entry (order: 85, parent: null / Core Administration).
   - Implement `permissions()` returning the 6 backup permissions (`backups.view`, `backups.create`, `backups.update`, `backups.delete`, `backups.run`, `backups.restore`).
3. In `app-modules/admin/routes/web.php`:
   - Remove lines 26-28 (Backups imports) and lines 98-103 (Backups routes).
4. In `app-modules/admin/src/Providers/ModuleServiceProvider.php`:
   - Remove `'backups'` from `registerMenus()`.
   - Remove `'backups.*'` from `registerPermissions()`.
5. In `app/Providers/AppServiceProvider.php`:
   - Remove lines 176-186 (`loadViewsFrom` and `BackupServiceInterface` manual registrations).

- [ ] **Step 4: Run Backups tests and route list verification**

Run: `php artisan route:list --name=panel.backups`
Expected: Lists `panel.backups.index`, `create`, `edit`, `restore`.
Run: `php artisan test app-modules/backups/`
Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
git add app-modules/admin/routes/web.php app-modules/admin/src/Providers/ModuleServiceProvider.php app-modules/backups/ app/Providers/AppServiceProvider.php
git commit -m "refactor: decouple backups routing and menus into autonomous system superadmin module"
```

---

### Task 5: Refactor Module Lifecycle Service for Non-Destructive Core Toggling

**Files:**
- Modify: `app/Services/ModuleLifecycleService.php`
- Modify: `app/Console/Commands/ModuleUninstallCommand.php`
- Test: `tests/Feature/Services/ModuleLifecycleServiceTest.php`

**Interfaces:**
- Consumes: Module manifests and `source` attribute (`core`, `custom`, `vendor`)
- Produces: `previewUninstall()` and `uninstall()` refuse destructive operations on `source === 'core'`; destructive deletion allowed only on `custom` (untracked filesystem) and `vendor` (composer).

- [ ] **Step 1: Write failing test verifying core modules cannot be destructively uninstalled**

In `tests/Feature/Services/ModuleLifecycleServiceTest.php`:
Test that calling `previewUninstall('extensions')` or `previewUninstall('security')` returns `can_uninstall: false` with reason: `"Built-in core modules cannot be uninstalled. Use the disable toggle instead."`
Test that `uninstall()` throws `ValidationException`.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test tests/Feature/Services/ModuleLifecycleServiceTest.php`
Expected: FAIL (currently allows uninstalling if uninstaller exists).

- [ ] **Step 3: Update `ModuleLifecycleService.php`**

1. In `previewUninstall(string $name)`:
   - Check `$manifest['source'] ?? 'core'`. If `'core'`, refuse uninstallation:
     `return $this->refusal('Built-in core modules cannot be uninstalled. Use the disable toggle instead.');`
2. In `uninstall(string $name, string $confirmation)`:
   - Disallow stripping composer entries or deleting files for core modules.
   - For custom modules (`source === 'custom'`), safely remove only the `custom-modules/{name}` directory.
3. Ensure `git status` is never dirtied by module lifecycle operations.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Services/ModuleLifecycleServiceTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/ModuleLifecycleService.php app/Console/Commands/ModuleUninstallCommand.php tests/Feature/Services/ModuleLifecycleServiceTest.php
git commit -m "feat: disallow destructive file removal on core modules in lifecycle service"
```

---

### Task 6: Modernize `/panel/modules` UI with Navigation Subcategories & DaisyUI Toggles

**Files:**
- Modify: `app-modules/admin/src/Livewire/ModulesList.php`
- Modify: `app-modules/admin/resources/views/modules-list.blade.php`
- Test: `tests/Feature/Livewire/ModulesListTest.php`

**Interfaces:**
- Consumes: `Module` records with `source`, `category`, `enabled`, `protected`, `required`
- Produces: Categorized table grouped by UI navigation subcategories; DaisyUI 5 reactive toggle switches; loading indicators.

- [ ] **Step 1: Write test for categorized module listing and toggle behavior**

In `tests/Feature/Livewire/ModulesListTest.php`:
Test that `ModulesList`:
1. Renders modules grouped into subcategories matching navigation (Core Administration, Accounts, Connectivity, Call Routing, Features, Media, Advanced, Monitoring, Custom).
2. Toggling a non-required module flips `enabled` and calls `optimize:clear`.
3. Required/protected modules (`admin`, `auth`, `tenant`) cannot be toggled off.

- [ ] **Step 2: Run test to observe baseline state**

Run: `php artisan test tests/Feature/Livewire/ModulesListTest.php`
Expected: Baseline passes on old flat table.

- [ ] **Step 3: Update `ModulesList.php` and `modules-list.blade.php`**

1. In `ModulesList.php`:
   - Compute categorized groups based on each module's declared `category` / menu parent:
     - `core`: Admin, Auth, Tenant, Backups, Logs
     - `accounts`: Extensions, Devices, Gateways, Sip Accounts
     - `connectivity`: Sip Profiles, Sip Trunks
     - `routing`: Inbound Routes, Outbound Routes, Dialplans, Ring Groups, IVRs, etc.
     - `features`: Call Centers, Conferences, Fax, etc.
     - `media`: Recordings, Music on Hold, Speech, etc.
     - `advanced`: Security, Certificates, File Stores, Email Connector, etc.
     - `monitoring`: Active Calls, Registrations, CDR, etc.
     - `custom`: Untracked 3rd-party extensions
2. In `modules-list.blade.php`:
   - Render structured category accordions/sections or filter tabs matching DaisyUI conventions.
   - Use `input type="checkbox" class="toggle toggle-success toggle-sm"` with `wire:click="toggleEnabled('{{ $module->id }}')"` and `wire:loading.attr="disabled"`.
   - Add inline DaisyUI loading spinner during toggle operations.
   - Display `<x-tooltip>` for required/protected modules.
   - Show `badge-neutral` for "Built-in" and `badge-info` for "Custom".

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test tests/Feature/Livewire/ModulesListTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app-modules/admin/src/Livewire/ModulesList.php app-modules/admin/resources/views/modules-list.blade.php tests/Feature/Livewire/ModulesListTest.php
git commit -m "feat: modernize /panel/modules with navigation subcategories and daisyui toggles"
```

---

### Task 7: Authoritative Documentation & Pre-Commit Verification Gate

**Files:**
- Modify: `docs/modular-architecture.md`

**Interfaces:**
- Consumes: 4.x Modular Monolith architecture, discovery engine, collision guards, and developer workflows.
- Produces: Complete, updated authoritative guide for administrators and developers.

- [ ] **Step 1: Update `docs/modular-architecture.md`**

1. Document the **Modular Monolith (DDD)** architecture and why path repositories were replaced with direct PSR-4 autoloading.
2. Detail the directory structure of first-party modules (`app-modules/`) vs. third-party drop-ins (`custom-modules/`).
3. Document the **Pre-flight & Runtime Collision Guards** (Section 8 of the spec).
4. Document the **System Superadmin Backups** role and configuration.
5. Provide developer examples for scaffolding, route auto-registration, menu cascading, and FreeSWITCH dialplan XML hooks.

- [ ] **Step 2: Run the Mandatory Pre-Commit Verification Checklist**

Run the complete verification gate:
```bash
# 1. Clear application optimization caches
php artisan optimize:clear

# 2. Verify Pint code style on modified files
./vendor/bin/pint --dirty --test

# 3. Run full parallel Pest test suite
php artisan test --compact --parallel

# 4. Verify Composer autoload dump is fast and clean
composer dump-autoload -o
```

- [ ] **Step 3: Commit**

```bash
git add docs/modular-architecture.md
git commit -m "docs: update modular architecture guide for 4.x modular monolith"
```
