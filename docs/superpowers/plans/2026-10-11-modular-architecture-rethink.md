# TallPBX 4.x Modular Architecture Modernization — Master Implementation Plan

> **Authoritative Specification & Execution Plan**  
> **Branch**: `4.x` (Primary active development series)  
> **Status**: Approved for Implementation  
> **Merged**: 2026-10-12 (Consolidates design spec, implementation plan, and peer review register F1–F12 into a single source of truth)

---

## 1. Executive Architecture & Design Specification

### 1.1 The Domain-Driven Modular Monolith
TallPBX organizes its telecommunications, administrative, and PBX capabilities into 60 distinct domain packages in `app-modules/` following an idiomatic **Domain-Driven Modular Monolith**:

1. **Core Appliance Monorepo (`app-modules/`)**: All 60 built-in modules are recognized as first-party domain modules of the appliance. They are versioned, tested, and updated together atomically via the central Git Updater. Core modules are permanent monorepo source code and must **never** be deleted or uninstalled from disk.
2. **Elimination of Composer Path Repositories**: The 60 path repository declarations and `tallpbx/module-*` requirements in root `composer.json` are completely removed. Autoloading is handled directly by optimized PSR-4 autoloading (`"Modules\\{Domain}\\": "app-modules/{domain}/src/"`), dropping Composer execution time to < 2 seconds and slashing memory usage.
3. **Clean Extensibility Boundary (`custom-modules/`)**: Third-party and community extensions are supported via an untracked, git-ignored `custom-modules/` directory (drop-in directories or git clones) and standard Composer packages in `vendor/`. External plugins never touch or dirty the core Git repository.
4. **System Superadmin Backups Architecture**: Full system backup and restore (`app-modules/backups/`) is explicitly designated as an autonomous **System Superadmin** utility responsible for the complete appliance (database, configuration, storage, FreeSWITCH state, and `custom-modules/`), organized under Core Administration with strict access controls.
5. **UI Navigation Parity on `/panel/modules`**: The module management workbench is restructured to mirror the sidebar navigation subcategories exactly (Core Administration, Accounts, Connectivity, Call Routing, Features, Media, Advanced, Monitoring, and Custom Extensions) using standardized DaisyUI 5 reactive toggle switches.
6. **Pre-flight & Runtime Collision Guards**: Third-party extensions are validated before boot to prevent name collisions, reserved route hijacking, table schema collisions, permission conflicts, and language key collisions. Missing dependencies fail-soft without bricking the appliance.

---

### 1.2 Core vs. Custom vs. Vendor Boundary

| Dimension | Built-in Modules (`app-modules/`) | Custom Extensions (`custom-modules/`) | Vendor Packages (`vendor/`) |
| :--- | :--- | :--- | :--- |
| **Location** | `app-modules/{name}/` | `custom-modules/{name}/` | `vendor/{vendor}/{package}/` |
| **Git Tracking** | Tracked in Core Git (`4.x` branch) | Ignored in `.gitignore` | Ignored in `.gitignore` |
| **Update Mechanism** | Core **Git Updater** (`git pull` via `GitUpdateService`) | Manual Git pull or filesystem placement | `composer update` |
| **Lifecycle Actions** | **Enable / Disable** (Feature Toggle) | **Enable, Disable, Delete** | **Require, Update, Enable, Disable, Remove** |
| **File Deletion** | **Strictly Forbidden** (Preserves clean working tree) | Safe to delete folder on uninstall with typed confirmation | Removed via Composer |
| **Autoloading** | Static root PSR-4 in `composer.json` | Dynamic runtime `CustomModuleAutoloader` | Composer vendor classmap |
| **Source Type** | `core` | `custom` | `vendor` |

---

### 1.3 Filesystem Structure Decision: Flat Structure with Metadata Grouping
The directory structure for `app-modules/` remains flat (`app-modules/{module-name}/`) rather than nested into category subfolders (`app-modules/{category}/{module-name}/`).
* **Rationale**: Decouples physical disk paths and PHP namespaces from UI navigation taxonomy. If a feature's UI grouping evolves, only metadata changes, with zero `git mv` operations, broken paths, or namespace refactoring.
* **Category Mapping**: Categorization is declared in each module's `module.json` under `category`, synced into the `modules` database table, and rendered dynamically in both the sidebar and the `/panel/modules` management workbench.

---

### 1.4 Composer & Direct PSR-4 Autoloading Architecture
Composer’s PSR-4 specification requires exact mapping between a namespace prefix and a base directory (wildcards like `app-modules/*/src/` are prohibited by Composer).

Each core module maintains an isolated package layout:
```
app-modules/call-forwards/
├── database/migrations/
├── resources/views/
├── routes/
├── src/                  <-- PHP classes isolated here
└── tests/
```

To support this layout without path-repo symlinks, root `composer.json` defines direct PSR-4 mappings for all 60 core modules:
```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Database\\Factories\\": "database/factories/",
        "Database\\Seeders\\": "database/seeders/",
        "Modules\\Acl\\": "app-modules/acl/src/",
        "Modules\\ActiveCalls\\": "app-modules/active-calls/src/",
        "Modules\\ActiveConferences\\": "app-modules/active-conferences/src/",
        "Modules\\Admin\\": "app-modules/admin/src/",
        "Modules\\Auth\\": "app-modules/auth/src/",
        "Modules\\Backups\\": "app-modules/backups/src/",
        "...": "..."
    }
}
```
* **Performance**: Composer compiles all 60 lines into a single static array in `vendor/composer/autoload_classmap.php` (`composer dump-autoload -o`). Lookups execute in $O(1)$ with zero runtime filesystem I/O.
* **Extensibility**: Custom extensions in `custom-modules/` use `CustomModuleAutoloader` (`spl_autoload_register`) for `CustomModules\{Name}` mapping to `custom-modules/{name}/src/` without mutating root `composer.json`.

---

### 1.5 Multi-Source Discovery Engine & Hybrid Boot Sequence

`App\Providers\ModuleServiceProvider` and `App\Support\ModuleServiceProvider` enforce an intentional **hybrid registration and boot sequence**:

1. **Multi-Source Manifest Discovery (`discoverModules()`)**:
   - Scans `app-modules/*/module.json` &rarr; tagged as `source: 'core'`.
   - Scans `custom-modules/*/module.json` &rarr; tagged as `source: 'custom'`.
   - Scans `vendor/*/*/module.json` &rarr; tagged as `source: 'vendor'`.
   - Caches parsed manifests to `bootstrap/cache/modules.php`.
2. **Container Registration Phase (`register()`)**:
   - For all discovered modules: register custom autoloader namespaces.
   - For all discovered modules: dynamically register each declared service provider with Laravel's application container:
     ```php
     foreach ($modules as $name => $manifest) {
         $providers = $manifest['providers'] ?? [];
         // Fallback by convention if manifest omitted providers:
         if (empty($providers) && class_exists($fallback = $manifest['namespace'].'\\Providers\\ModuleServiceProvider')) {
             $providers = [$fallback];
         }
         foreach ($providers as $providerClass) {
             if (class_exists($providerClass)) {
                 $this->app->register($providerClass);
             }
         }
     }
     ```
   - **Rationale**: Service bindings, singletons, and container resolution remain available across the entire platform even if a module is toggled off (e.g. storage driver bindings).
3. **Application Boot Phase (`boot()`) in `App\Support\ModuleServiceProvider`**:
   - **Always loaded** (regardless of enabled/disabled state):
     - `registerViews()`: View templates registered under `{module}::`.
     - `registerTranslations()`: Translation paths loaded.
     - `registerMigrations()`: Schema migrations registered (guarantees schema integrity and foreign keys).
     - `registerConfig()`: Optional configuration merged.
   - **Gated by `ModuleState::isEnabled($name)`**:
     - `registerRoutes()`: HTTP routes exposed.
     - `registerLivewire()`: Livewire components exposed.
     - `registerMenu()`: Navigation items added to sidebar.
     - `registerPermissions()`: Permissions exposed to RBAC.
     - `registerListeners()`: Domain event listeners subscribed.
     - `registerCommands()`: Artisan commands registered.

---

### 1.6 Canonical 60/60 Navigation Taxonomy Mapping

Every built-in module is assigned to exactly one of the 8 core navigation groups (+1 for Custom Extensions). This mapping is 100% complete and verified across all 60 core modules:

1. **Core Administration (`core`)** (5 modules)
   - `admin`, `auth`, `tenant`, `backups`, `logs`
2. **Accounts (`accounts`)** (5 modules)
   - `extensions`, `devices`, `gateways`, `sip-accounts`, `extension-settings`
3. **Connectivity (`connectivity`)** (2 modules)
   - `sip-profiles`, `sip-trunks`
4. **Call Routing (`routing`)** (16 modules)
   - `inbound-routes`, `outbound-routes`, `dialplans`, `dialplan-tools`, `ring-groups`, `ivr-menus`, `time-conditions`, `call-flows`, `call-forwards`, `follow-me`, `call-blocks`, `bridges`, `feature-codes`, `number-translations`, `emergency`, `destinations`
5. **Features (`features`)** (11 modules)
   - `call-centers`, `call-broadcast`, `conferences`, `conference-centers`, `fax`, `hot-desking`, `operator-panel`, `provision`, `pin-numbers`, `voicemails`, `click-to-call`
6. **Media (`media`)** (5 modules)
   - `recordings`, `music-on-hold`, `call-recordings`, `speech`, `transcribe`
7. **Advanced & System (`advanced`)** (9 modules)
   - `security`, `certificates`, `file-stores`, `email-connector`, `email-queue`, `email-templates`, `acl`, `tenant-limits`, `event-rate-limits`
8. **Monitoring & Diagnostics (`monitoring`)** (7 modules)
   - `active-calls`, `active-conferences`, `registrations`, `sip-status`, `call-center-active`, `voicemail-messages`, `xml-cdr`
9. **Custom Extensions (`custom`)**
   - Untracked 3rd-party modules in `custom-modules/` or external packages in `vendor/`.

---

### 1.7 Custom Module Pre-Flight & Runtime Collision Guards

To prevent untrusted or poorly written third-party extensions from compromising the PBX:

1. **Module Name Collisions**: Custom modules declaring a name that matches any core module (`extensions`, `admin`, `dialplans`, etc.) are rejected with an explicit logged error and skipped from registration.
2. **Route Prefix & Name Hijacking**: Custom modules cannot register route names matching core routes or bind reserved core URLs (`/panel/login`, `/panel/setup`, `/panel/dashboard`, `/panel/settings`, `/panel/modules`, `/panel/git-update`).
3. **Database Schema & Table Collision**: `SafeMigrator` inspects pending migrations. Custom migrations attempting to create existing tables or alter protected core tables (`users`, `admins`, `tenants`, `groups`, `permissions`, `modules`, `menus`) are halted with `MigrationCollisionException`.
4. **Language Dictionary Collisions**: If a custom module's translation keys collide with core dictionaries, its global cascading path is revoked and it falls back to explicit namespacing (`__('{module-name}::{group}.{key}')`).
5. **Permission Ownership**: `PermissionService` blocks custom modules from overwriting or hijacking permissions owned by other modules.
6. **Fail-Soft Dependency Handling**: If a module has unmet requirements, it is marked disabled in the registry and logs a critical alert—it **never** throws a boot-time `RuntimeException` that crashes the PBX.

---

### 1.8 Scaffolding Engine (`make:module`) Exclusively for Custom Modules

Core `app-modules/` are part of the version-controlled monorepo. `php artisan make:module <name>` is dedicated **exclusively** to generating third-party extensions in `custom-modules/`:
* Scaffolds directly into `custom-modules/{name}/` (untracked, git-ignored).
* Emits `module.json` tagged with `source: "custom"` and namespace `CustomModules\{Studly}`.
* Leaves root `composer.json` completely untouched.
* Pre-generates boilerplate Livewire components, Blade views, migration templates, and language files.
* Automatically invalidates `bootstrap/cache/modules.php` so the extension is discoverable immediately.

---

## 2. Review Findings & Architectural Resolutions Register

The independent review evaluated Tasks 1–2 and the codebase on `4.x`. All 12 findings (F1–F12) are resolved within this master plan:

| Finding | Severity | Validated Resolution in Master Plan |
| :--- | :--- | :--- |
| **F1** | **Critical** | **Lockfile & Installed Metadata Cleanup**: Run `composer update --lock` to purge 60 path packages from `composer.lock` and `installed.json`. Run `optimize:clear` to purge legacy package discovery from `packages.php`. Added `composer validate --strict` to the verification gate. *(Amends Task 1)* |
| **F2** | **Critical** | **Missing Manifest `providers` Keys**: Add `"providers"` array to `backups` and `email-connector` manifests. Add convention fallback in discovery (`{namespace}\Providers\ModuleServiceProvider`). Add a Pest completeness test ensuring all 60 core modules declare their provider. *(Amends Task 2 / Task 5)* |
| **F3** | **High** | **Missing Database Columns & 60/60 Taxonomy**: Migration adds `source` and `category` to `modules` table. Schema and manifests updated with canonical `category` values. Spec §6.1 updated to cover all 60 modules (adding `click-to-call`, `destinations`, `extension-settings`). *(Addressed in Task 3 & Task 7)* |
| **F4** | **High** | **Cache Reconciliation Engine**: Hook `module:clear` into `optimize:clear`. Ensure `module:sync` updates both the database and `bootstrap/cache/modules.php`. Make `make:module` invalidate the manifest cache. *(Addressed in Task 3)* |
| **F5** | **High** | **Hybrid Boot Documentation**: Document the real, superior hybrid boot architecture: all providers register in `register()`, while `boot()` gates routes/menus/permissions via `ModuleState::isEnabled()`, preserving migrations, views, and container bindings. *(Addressed in Spec §1.5)* |
| **F6** | **High** | **Robust Collision Guards & Fail-Soft Policy**: Replace empty `validateModuleNames()` with explicit rejection logging. Change dependency check to fail-soft (disable module + log error, never throw `RuntimeException` at boot). Integrate table guards into `SafeMigrator`. Unify name regex to `^[a-z][a-z0-9-]*$`. *(Addressed in Task 4)* |
| **F7** | **High** | **Module Lifecycle Path-Repo Elimination**: Rewrite `ModuleLifecycleService` to completely remove all path-repo Composer manipulation. Strictly forbid uninstall/deletion on `source === 'core'`. For `source === 'custom'`, perform safe directory deletion with typed confirmation (`UNINSTALL {name}`). *(Addressed in Task 6)* |
| **F8** | **Medium** | **Scope Realism & Translation Parity**: Explicitly defer complex out-of-scope items (ZIP web uploader, monolithic `RoutingCacheObserver` refactor across 29 models). Add 100% translation parity (`en`, `es`, `fr`) for new UI category strings in Task 7. Add "Delete Extension" UI action for custom modules. *(Addressed in Tasks 6 & 7)* |
| **F9** | **Medium** | **Command Docblock Modernization**: Sweep docblocks in `ModuleCacheCommand`, `ModuleClearCommand`, and `ModuleSyncCommand` to remove outdated path-repo claims. *(Addressed in Task 3)* |
| **F10** | **Medium** | **Disaster Recovery Custom Modules Scope**: Add `custom-modules/` directory to the filesystem backup paths in `app-modules/backups/` so custom extensions survive full-appliance restores. *(Addressed in Task 5)* |
| **F11** | **Low** | **Manifest Completeness & Cold-State Tests**: Add tests verifying that every module shipping a provider declares it in `module.json`, plus cold-state discovery tests without cache files. *(Addressed in Task 2 & Task 8)* |
| **F12** | **Low** | **Vestigial File Removal & Schema Modernization**: Delete all 60 unused `app-modules/*/composer.json` files. Update `resources/schemas/module.json` to model `source` and the 9 canonical UI categories. *(Addressed in Task 3)* |

---

## 3. Step-by-Step Implementation Tasks

### Task 1: Composer & Autoloading Modernization (COMPLETED + F1 Lockfile Cleanup)

**Status:** Completed in `c8d31284` (root `composer.json` cleaned, tests green).  
**Remaining Step:** Reconcile Composer lockfile and installed metadata to resolve F1.

**Files:**
- Modify: `composer.lock`
- Update: `vendor/composer/installed.json`
- Verify: `bootstrap/cache/packages.php`
- Test: `tests/Feature/ModuleAutoloadTest.php`

- [x] **Step 1: Write tests verifying direct PSR-4 autoloading for all 60 modules** (Completed in `c8d31284`)
- [x] **Step 2: Update `composer.json` to remove 60 path repositories and add 60 PSR-4 mappings** (Completed in `c8d31284`)
- [x] **Step 3: Verify composer dump-autoload performance and test suite** (Completed in `c8d31284`)
- [ ] **Step 4 (F1 Amendment): Reconcile `composer.lock` and purge legacy path metadata**
  1. Run `COMPOSER_ALLOW_SUPERUSER=1 composer update --lock` to update `composer.lock` and `installed.json` without path packages.
  2. Run `php artisan optimize:clear` to purge legacy package discovery from `bootstrap/cache/packages.php`.
  3. Verify `composer validate --strict` passes with 0 warnings or lockfile errors.
  4. Verify `grep -c '"name": "tallpbx/module' composer.lock` returns 0.
  5. Run targeted test: `php artisan test tests/Feature/ModuleAutoloadTest.php`.
- [ ] **Step 5: Commit**
  ```bash
  git add composer.lock
  git commit -m "refactor: update composer lockfile removing legacy module path packages"
  ```

---

### Task 2: Multi-Source Discovery Engine, Dynamic Booting & Scaffolding (COMPLETED + F2 Manifest Corrections)

**Status:** Completed in `728b1816`.  
**Remaining Step:** Add missing manifest `providers` keys, convention fallback, and completeness test to resolve F2 & F11.

**Files:**
- Modify: `app-modules/backups/module.json`
- Modify: `app-modules/email-connector/module.json`
- Modify: `app/Providers/ModuleServiceProvider.php`
- Test: `tests/Feature/Providers/ModuleServiceProviderTest.php`

- [x] **Step 1: Implement multi-source discovery, custom autoloader, and custom make:module** (Completed in `728b1816`)
- [ ] **Step 2 (F2/F11 Amendment): Fix manifests, add discovery convention fallback, and add completeness test**
  1. In `app-modules/backups/module.json`, add:
     ```json
     "providers": [
         "Modules\\Backups\\Providers\\ModuleServiceProvider"
     ]
     ```
  2. In `app-modules/email-connector/module.json`, add:
     ```json
     "providers": [
         "Modules\\EmailConnector\\Providers\\ModuleServiceProvider"
     ]
     ```
  3. In `app/Providers/ModuleServiceProvider.php`:
     - If `$manifest['providers']` is empty, fallback to `{namespace}\Providers\ModuleServiceProvider` when that class exists.
  4. In `tests/Feature/Providers/ModuleServiceProviderTest.php`:
     - Add test asserting that all 60 core modules declare their service provider in `module.json` and that providers boot in cold state.
- [ ] **Step 3: Run targeted test to verify it passes**
  ```bash
  php artisan test tests/Feature/Providers/ModuleServiceProviderTest.php
  ```
- [ ] **Step 4: Commit**
  ```bash
  git add app-modules/backups/module.json app-modules/email-connector/module.json app/Providers/ModuleServiceProvider.php tests/Feature/Providers/ModuleServiceProviderTest.php
  git commit -m "fix: declare missing service providers in backups and email-connector manifests with discovery fallback"
  ```

---

### Task 3: Database Schema, Module Taxonomy & Cache Reconciliation Engine (Resolves F3, F4, F9, F12)

**Files:**
- Create: `database/migrations/2026_10_13_000001_add_source_and_category_to_modules_table.php`
- Modify: `app/Models/Module.php`
- Modify: `resources/schemas/module.json`
- Modify: `app-modules/*/module.json` (canonicalize categories across all 60 modules)
- Modify: `app/Console/Commands/ModuleSyncCommand.php`
- Modify: `app/Console/Commands/ModuleCacheCommand.php`
- Modify: `app/Console/Commands/ModuleClearCommand.php`
- Modify: `app/Console/Commands/MakeModuleCommand.php`
- Modify: `app/Providers/AppServiceProvider.php` (wire `module:clear` into `optimize:clear`)
- Delete: `app-modules/*/composer.json` (60 vestigial files)
- Test: `tests/Feature/Commands/ModuleSyncTaxonomyTest.php`

- [ ] **Step 1: Write migration and update Module Eloquent model**
  1. Create migration adding `source` (`string`, default `'core'`) and `category` (`string`, nullable) to `modules` table.
  2. Add `$fillable = [..., 'source', 'category']` to `app/Models/Module.php`.
- [ ] **Step 2: Update `module.json` schema and all 60 core manifests**
  1. Update `resources/schemas/module.json` to define `source` (`core`, `custom`, `vendor`) and `category` (enum: `core`, `accounts`, `connectivity`, `routing`, `features`, `media`, `advanced`, `monitoring`, `custom`).
  2. Update all 60 `app-modules/*/module.json` manifests to declare their canonical `category` matching the 60/60 navigation mapping (including `click-to-call` &rarr; `features`, `destinations` &rarr; `routing`, `extension-settings` &rarr; `accounts`, `backups` &rarr; `core`, `email-connector` &rarr; `advanced`).
- [ ] **Step 3: Update commands & wire cache reconciliation**
  1. In `ModuleSyncCommand`:
     - Sync `source` and `category` from manifest into the `Module` database record.
     - Automatically regenerate `bootstrap/cache/modules.php` after successful sync.
  2. In `MakeModuleCommand`:
     - Automatically delete or rebuild `bootstrap/cache/modules.php` after scaffolding a custom module.
  3. In `AppServiceProvider`:
     - Listen for `CommandFinished` on `optimize:clear` and invoke `module:clear` to ensure `bootstrap/cache/modules.php` is purged during cache clears.
  4. Modernize docblocks in `ModuleCacheCommand`, `ModuleClearCommand`, and `ModuleSyncCommand` (removing path-repo references).
- [ ] **Step 4: Remove 60 vestigial `app-modules/*/composer.json` files**
  ```bash
  rm -f app-modules/*/composer.json
  ```
- [ ] **Step 5: Write and run targeted test**
  1. Create `tests/Feature/Commands/ModuleSyncTaxonomyTest.php` asserting that `module:sync` populates all 60 modules with correct `source` and `category` and that cache is rebuilt.
  2. Run: `php artisan test tests/Feature/Commands/ModuleSyncTaxonomyTest.php`
- [ ] **Step 6: Commit**
  ```bash
  git add database/migrations/ app/Models/Module.php resources/schemas/module.json app-modules/ app/Console/Commands/ app/Providers/AppServiceProvider.php tests/Feature/Commands/ModuleSyncTaxonomyTest.php
  git commit -m "feat: establish module database taxonomy, cache reconciliation, and prune vestigial composer files"
  ```

---

### Task 4: Custom Module Pre-Flight & Runtime Collision Guards (Resolves F6)

**Files:**
- Create: `app/Services/ModuleCollisionGuard.php`
- Modify: `app/Providers/ModuleServiceProvider.php`
- Modify: `app/Support/ModuleServiceProvider.php`
- Modify: `app/Services/PermissionService.php`
- Modify: `app/Services/SafeMigrator.php` (or migration safety checks)
- Test: `tests/Feature/Modules/CustomModuleCollisionGuardTest.php`

- [ ] **Step 1: Write failing test for collision guards**
  In `tests/Feature/Modules/CustomModuleCollisionGuardTest.php`:
  1. Assert that a custom module declaring `name: 'extensions'` or `name: 'admin'` is rejected with a descriptive logged error and omitted from registration.
  2. Assert that missing dependencies fail-soft (module marked disabled and critical error logged, without throwing `RuntimeException` at boot).
  3. Assert that a custom module attempting to bind reserved route `/panel/login` or `/panel/dashboard` is rejected.
  4. Assert that a custom migration attempting to create an existing table or alter core tables is halted.
  5. Assert that colliding translation keys revoke cascading loader and fallback to namespaced strings.
  6. Assert that a custom module cannot register permissions owned by an existing module.
- [ ] **Step 2: Implement `ModuleCollisionGuard` and integrate with providers**
  1. Create `app/Services/ModuleCollisionGuard.php` with:
     - `assertNoNameCollision(string $name, array $coreModules)`: Logs error and returns false if custom module clashes with core.
     - `assertNoRouteCollision(array $routes, array $reservedRoutes)`
     - `assertNoTableCollision(string $table, array $reservedTables)`
  2. In `app/Providers/ModuleServiceProvider.php`:
     - Replace empty `validateModuleNames()` with explicit `ModuleCollisionGuard` inspection.
     - In `checkDependencies()`, implement fail-soft: mark module disabled and log a critical error instead of throwing `RuntimeException`.
     - Unify regex to `^[a-z][a-z0-9-]*$`.
  3. In `app/Support/ModuleServiceProvider.php`:
     - Check translation collisions against core modules; on custom module collision, revoke cascading path and fall back to namespaced `loadTranslationsFrom()`.
  4. In `app/Services/PermissionService.php`:
     - Prevent custom modules from claiming permissions owned by another module.
  5. In `SafeMigrator`:
     - Guard custom module migrations against existing tables.
- [ ] **Step 3: Run targeted test to verify it passes**
  ```bash
  php artisan test tests/Feature/Modules/CustomModuleCollisionGuardTest.php
  ```
- [ ] **Step 4: Commit**
  ```bash
  git add app/Services/ModuleCollisionGuard.php app/Providers/ModuleServiceProvider.php app/Support/ModuleServiceProvider.php app/Services/PermissionService.php app/Services/SafeMigrator.php tests/Feature/Modules/CustomModuleCollisionGuardTest.php
  git commit -m "feat: implement pre-flight collision guards and fail-soft dependency handling for custom modules"
  ```

---

### Task 5: Backups Module Autonomous Superadmin Cleanup (Resolves F2, F10)

**Files:**
- Modify: `app-modules/admin/routes/web.php`
- Modify: `app-modules/admin/src/Providers/ModuleServiceProvider.php`
- Create: `app-modules/backups/routes/web.php`
- Modify: `app-modules/backups/src/Providers/ModuleServiceProvider.php`
- Modify: `app-modules/backups/src/Services/BackupService.php` (or backup config)
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `app-modules/backups/tests/Feature/BackupsRouteIndependenceTest.php`

- [ ] **Step 1: Write test verifying Backups route and menu independence**
  In `app-modules/backups/tests/Feature/BackupsRouteIndependenceTest.php`:
  1. Assert `/panel/backups` routes are registered exclusively by Backups provider.
  2. Assert Backups menu item is registered under Core Administration (parent: null, order: 85).
  3. Assert `custom-modules/` directory is included in backup filesystem paths.
- [ ] **Step 2: Move routes, menus, and permissions to Backups module**
  1. Create `app-modules/backups/routes/web.php` defining `/panel/backups` routes with `auth.panel` and `admin.can:backups.*`.
  2. In `app-modules/backups/src/Providers/ModuleServiceProvider.php`:
     - Remove `routeGroups() => []`.
     - Implement `menuItems()` returning the `'backups'` entry (order: 85, parent: null).
     - Implement `permissions()` returning `backups.*`.
  3. In `app-modules/admin/routes/web.php`:
     - Remove Backups imports and routes.
  4. In `app-modules/admin/src/Providers/ModuleServiceProvider.php`:
     - Remove `'backups'` menu and permission entries.
  5. In `app/Providers/AppServiceProvider.php`:
     - Remove lines 176–186 (`loadViewsFrom` and `BackupServiceInterface` manual registrations).
  6. In `app-modules/backups/src/Services/BackupService.php`:
     - Include `custom-modules/` in backup filesystem paths so user extensions survive restore.
- [ ] **Step 3: Run targeted tests**
  ```bash
  php artisan route:list --name=panel.backups
  php artisan test app-modules/backups/
  ```
- [ ] **Step 4: Commit**
  ```bash
  git add app-modules/admin/ app-modules/backups/ app/Providers/AppServiceProvider.php
  git commit -m "refactor: decouple backups routes and menus into autonomous system superadmin module"
  ```

---

### Task 6: Refactor Module Lifecycle Service for Non-Destructive Core Toggling (Resolves F7, F8)

**Files:**
- Modify: `app/Services/ModuleLifecycleService.php`
- Modify: `app/Console/Commands/ModuleUninstallCommand.php`
- Test: `tests/Feature/Services/ModuleLifecycleServiceTest.php`

- [ ] **Step 1: Write failing test verifying non-destructive core toggling & safe custom deletion**
  In `tests/Feature/Services/ModuleLifecycleServiceTest.php`:
  1. Assert calling `previewUninstall()` or `uninstall()` on an `app-modules/` core module returns `can_uninstall: false` and throws `ValidationException`: `"Built-in core modules cannot be uninstalled. Use the disable toggle instead."`
  2. Assert calling `uninstall()` on a `custom-modules/` extension deletes only its untracked directory and database record, requiring typed confirmation (`UNINSTALL {name}`).
  3. Assert no Composer path-repository manipulation is attempted.
- [ ] **Step 2: Refactor `ModuleLifecycleService.php`**
  1. Purge dead path-repo helpers: `stripRepositoryEntry()`, `stripRequireEntry()`, `addRepositoryEntry()`, `addRequireEntry()`.
  2. In `previewUninstall()` and `uninstall()`:
     - Check `$manifest['source'] ?? 'core'`. If `'core'`, strictly refuse uninstallation.
     - For `source === 'custom'`, remove only `custom-modules/{name}` directory with irreversible-deletion warnings.
     - For `source === 'vendor'`, remove via Composer package removal.
  3. Update `previewRestore()` and `restore()`:
     - Core modules cannot be uninstalled, so restore is not applicable; custom modules cannot be restored from Git.
- [ ] **Step 3: Run targeted test to verify it passes**
  ```bash
  php artisan test tests/Feature/Services/ModuleLifecycleServiceTest.php
  ```
- [ ] **Step 4: Commit**
  ```bash
  git add app/Services/ModuleLifecycleService.php app/Console/Commands/ModuleUninstallCommand.php tests/Feature/Services/ModuleLifecycleServiceTest.php
  git commit -m "feat: refactor module lifecycle service to disallow core module deletion and eliminate composer path-repo code"
  ```

---

### Task 7: Modernize `/panel/modules` UI with Navigation Subcategories & DaisyUI Toggles (Resolves F3, F8)

**Files:**
- Modify: `app-modules/admin/src/Livewire/ModulesList.php`
- Modify: `app-modules/admin/resources/views/modules-list.blade.php`
- Modify: `app-modules/admin/lang/en/admin.php`
- Modify: `app-modules/admin/lang/es/admin.php`
- Modify: `app-modules/admin/lang/fr/admin.php`
- Test: `tests/Feature/Livewire/ModulesListTest.php`

- [ ] **Step 1: Write tests for categorized module listing, toggle behavior, and custom deletion**
  In `tests/Feature/Livewire/ModulesListTest.php`:
  1. Test that `ModulesList` groups all modules into the 8 navigation subcategories (+1 for Custom Extensions) with 60/60 core coverage.
  2. Test that toggling an enabled module flips `enabled` and invalidates caches.
  3. Test that core protected modules (`admin`, `auth`, `tenant`) cannot be toggled off.
  4. Test that custom modules show a "Delete Extension" action that calls `ModuleLifecycleService::uninstall()`.
- [ ] **Step 2: Update `ModulesList.php` and `modules-list.blade.php`**
  1. In `ModulesList.php`:
     - Load modules from database and group by `category`:
       - `core`: Core Administration
       - `accounts`: Accounts
       - `connectivity`: Connectivity
       - `routing`: Call Routing
       - `features`: Features
       - `media`: Media
       - `advanced`: Advanced & System
       - `monitoring`: Monitoring & Diagnostics
       - `custom`: Custom Extensions
     - Add `deleteCustomModule(string $id, string $confirmation)` method.
  2. In `modules-list.blade.php`:
     - Render category sections/accordions matching DaisyUI conventions.
     - Use `input type="checkbox" class="toggle toggle-success toggle-sm"` with `wire:click="toggleEnabled('{{ $module->id }}')"` and `wire:loading.attr="disabled"`.
     - Add inline DaisyUI loading spinner during toggle operations.
     - Display `<x-tooltip>` for required/protected modules.
     - Show `badge-neutral` for "Built-in" and `badge-info` for "Custom".
     - Add dropdown menu for custom modules with "Delete Extension" opening a confirmation dialog.
  3. Add translation keys with 100% parity across `app-modules/admin/lang/{en,es,fr}/admin.php`:
     - Navigation category headers, "Built-in", "Custom", "Delete Extension", confirmation prompts.
- [ ] **Step 3: Run targeted test to verify it passes**
  ```bash
  php artisan test tests/Feature/Livewire/ModulesListTest.php
  ```
- [ ] **Step 4: Commit**
  ```bash
  git add app-modules/admin/src/Livewire/ModulesList.php app-modules/admin/resources/views/modules-list.blade.php app-modules/admin/lang/ tests/Feature/Livewire/ModulesListTest.php
  git commit -m "feat: modernize /panel/modules with navigation subcategories, daisyui toggles, and i18n parity"
  ```

---

### Task 8: Authoritative Documentation & Pre-Commit Verification Gate (Resolves F11)

**Files:**
- Modify: `docs/modular-architecture.md`
- Modify: `README.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Update `docs/modular-architecture.md`**
  1. Document the **Modular Monolith (DDD)** architecture and why path repositories were replaced with direct PSR-4 autoloading.
  2. Detail the directory structure of first-party modules (`app-modules/`) vs. third-party drop-ins (`custom-modules/`).
  3. Document the **Pre-flight & Runtime Collision Guards**.
  4. Document the **System Superadmin Backups** role and configuration.
  5. Document developer examples for `make:module`, route auto-registration, and dialplan XML hooks.
- [ ] **Step 2: Update `README.md` and `CHANGELOG.md`**
  1. Record the complete architectural modernization under `## [Unreleased]` in `CHANGELOG.md`.
  2. Update module architecture overview in `README.md`.
- [ ] **Step 3: Run the Mandatory Pre-Commit Verification Checklist**
  Run the complete verification gate:
  ```bash
  # 1. Clear application optimization caches
  php artisan optimize:clear

  # 2. Verify Pint code style on dirty/modified files
  ./vendor/bin/pint --dirty --test

  # 3. Run full parallel Pest test suite
  php artisan test --compact --parallel

  # 4. Verify Composer autoload dump is fast and clean
  composer dump-autoload -o

  # 5. Verify Composer lockfile is 100% strict and clean
  composer validate --strict
  ```
- [ ] **Step 4: Commit**
  ```bash
  git add docs/modular-architecture.md README.md CHANGELOG.md
  git commit -m "docs: finalize modular architecture documentation and update changelog for 4.x modular monolith"
  ```
