# TallPBX 4.x Modular Architecture Modernization Spec
## Domain-Driven Modular Monolith & Unified Extensibility Engine

> **Independent review**: [2026-10-12 review](../reviews/2026-10-12-modular-architecture-rethink-review.md) — baseline findings register (F1–F12) covering this spec, the plan, and the Task 1–2 implementation state. Read before amending this document.

- **Branch**: `4.x` (Primary active development series)
- **Status**: Draft Spec for Review
- **Date**: 2026-10-11
- **Author**: Antigravity & TallPBX Core Architecture Team

---

## 1. Executive Summary & Goals

TallPBX organizes its telecommunications, administrative, and PBX capabilities into 60 distinct domain packages in `app-modules/` following an idiomatic **Domain-Driven Modular Monolith**:
1. **Core Appliance Monorepo (`app-modules/`)**: All 60 built-in modules are recognized as first-party domain modules of the appliance. They are versioned, tested, and updated together atomically via the central Git Updater.
2. **Elimination of Composer Path Repositories**: The 60 path repository declarations in root `composer.json` are completely removed. Autoloading is handled directly by optimized PSR-4 autoloading, dropping Composer execution time to < 5 seconds and slashing memory usage.
3. **Clean Extensibility Boundary (`custom-modules/`)**: Third-party and community extensions are supported via an untracked, git-ignored `custom-modules/` directory (drop-in zips or git clones) and standard Composer packages in `vendor/`. External plugins never touch or dirty the core Git repository.
4. **System Superadmin Backups Architecture**: Full system backup and restore (`app-modules/backups/`) is explicitly designated as a **System Superadmin** utility responsible for the entire appliance (database, configuration, storage, FreeSWITCH state), organized under Core Administration with strict access controls.
5. **UI Navigation Parity on `/panel/modules`**: The module management interface is restructured to mirror the sidebar navigation subcategories exactly (Core Administration, Accounts, Connectivity, Call Routing, Features, Media, Advanced, Monitoring, and Custom Extensions) using standardized DaisyUI 5 reactive toggle switches.
6. **Authoritative Documentation**: Complete architectural documentation of the Modular Monolith and developer guides in `docs/modular-architecture.md`.

---

## 2. Architecture & Domain Separation

### 2.1 The Modular Monolith Concept
Each module inside `app-modules/{module-name}/` remains a strictly encapsulated bounded domain:
* **Domain Models & Queries**: Located in `src/Models/`.
* **Business Logic & Telephony Services**: Located in `src/Services/`.
* **Livewire Components**: Located in `src/Livewire/`.
* **Database Migrations**: Located in `database/migrations/`.
* **Blade Views**: Registered under the module view namespace `{module-name}::`.
* **Translation Dictionaries**: Located in `lang/{locale}/` with cascading resolution.
* **Dialplan Contributor Hooks**: Implement `DialplanXmlContributor` and tag `dialplan.xml`.

### 2.2 Distinction: Built-in Core vs. Custom Extensions

| Dimension | Built-in Modules (`app-modules/`) | Custom Extensions (`custom-modules/`) | Vendor Packages (`vendor/`) |
| :--- | :--- | :--- | :--- |
| **Location** | `app-modules/{name}/` | `custom-modules/{name}/` | `vendor/{vendor}/{package}/` |
| **Git Tracking** | Tracked in Core Git (`4.x` branch) | Ignored in `.gitignore` | Ignored in `.gitignore` |
| **Update Mechanism** | Core **Git Updater** (`git pull` via `GitUpdateService`) | Manual Git pull, ZIP upload, or admin updater | `composer update` |
| **Lifecycle Actions** | **Enable / Disable** (Feature Toggle) | **Install, Update, Enable, Disable, Delete** | **Require, Update, Enable, Disable, Remove** |
| **File Deletion** | **Strictly Forbidden** (Preserves clean working tree) | Safe to delete folder on uninstall | Removed via Composer |
| **Source Type** | `core` | `custom` | `vendor` |

### 2.3 Filesystem Structure Decision: Flat Structure with Metadata Grouping
The directory structure for `app-modules/` remains flat (`app-modules/{module-name}/`) rather than nested into category subfolders (`app-modules/{category}/{module-name}/`).
* **Rationale**: Decouples physical disk paths and PHP namespaces from UI navigation taxonomy. If a feature's UI grouping evolves (e.g. moving a module from "Advanced" to "Features"), only metadata changes, with zero `git mv` operations, broken paths, or namespace refactoring.
* **Category Mapping**: Categorization is declared in each module's `module.json` and `ModuleServiceProvider::menuItems()`, and rendered dynamically in both the sidebar and the `/panel/modules` management workbench.

---

## 3. Composer & Autoloading Modernization

### 3.1 Removing the 60 Path Repositories
In root `composer.json`:
* Remove the entire `repositories` block containing the 60 `{ "type": "path", "url": "app-modules/*" }` definitions.
* Remove all 60 `"tallpbx/module-*": "*"` requirements from the `require` block.
* Define direct PSR-4 mapping for all built-in modules under `autoload.psr-4`:
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
          "Modules\\Bridges\\": "app-modules/bridges/src/",
          "Modules\\CallBlocks\\": "app-modules/call-blocks/src/",
          "Modules\\CallBroadcast\\": "app-modules/call-broadcast/src/",
          "Modules\\CallCenterActive\\": "app-modules/call-center-active/src/",
          "Modules\\CallCenters\\": "app-modules/call-centers/src/",
          "Modules\\CallFlows\\": "app-modules/call-flows/src/",
          "Modules\\CallForwards\\": "app-modules/call-forwards/src/",
          "Modules\\CallRecordings\\": "app-modules/call-recordings/src/",
          "Modules\\Certificates\\": "app-modules/certificates/src/",
          "Modules\\ClickToCall\\": "app-modules/click-to-call/src/",
          "Modules\\ConferenceCenters\\": "app-modules/conference-centers/src/",
          "Modules\\Conferences\\": "app-modules/conferences/src/",
          "Modules\\Destinations\\": "app-modules/destinations/src/",
          "Modules\\Devices\\": "app-modules/devices/src/",
          "Modules\\Dialplans\\": "app-modules/dialplans/src/",
          "Modules\\DialplanTools\\": "app-modules/dialplan-tools/src/",
          "Modules\\EmailConnector\\": "app-modules/email-connector/src/",
          "Modules\\EmailQueue\\": "app-modules/email-queue/src/",
          "Modules\\EmailTemplates\\": "app-modules/email-templates/src/",
          "Modules\\Emergency\\": "app-modules/emergency/src/",
          "Modules\\EventRateLimits\\": "app-modules/event-rate-limits/src/",
          "Modules\\Extensions\\": "app-modules/extensions/src/",
          "Modules\\ExtensionSettings\\": "app-modules/extension-settings/src/",
          "Modules\\Fax\\": "app-modules/fax/src/",
          "Modules\\FeatureCodes\\": "app-modules/feature-codes/src/",
          "Modules\\FileStores\\": "app-modules/file-stores/src/",
          "Modules\\FollowMe\\": "app-modules/follow-me/src/",
          "Modules\\Gateways\\": "app-modules/gateways/src/",
          "Modules\\HotDesking\\": "app-modules/hot-desking/src/",
          "Modules\\InboundRoutes\\": "app-modules/inbound-routes/src/",
          "Modules\\IvrMenus\\": "app-modules/ivr-menus/src/",
          "Modules\\Logs\\": "app-modules/logs/src/",
          "Modules\\MusicOnHold\\": "app-modules/music-on-hold/src/",
          "Modules\\NumberTranslations\\": "app-modules/number-translations/src/",
          "Modules\\OperatorPanel\\": "app-modules/operator-panel/src/",
          "Modules\\OutboundRoutes\\": "app-modules/outbound-routes/src/",
          "Modules\\PinNumbers\\": "app-modules/pin-numbers/src/",
          "Modules\\Provision\\": "app-modules/provision/src/",
          "Modules\\Recordings\\": "app-modules/recordings/src/",
          "Modules\\Registrations\\": "app-modules/registrations/src/",
          "Modules\\RingGroups\\": "app-modules/ring-groups/src/",
          "Modules\\Security\\": "app-modules/security/src/",
          "Modules\\SipAccounts\\": "app-modules/sip-accounts/src/",
          "Modules\\SipProfiles\\": "app-modules/sip-profiles/src/",
          "Modules\\SipStatus\\": "app-modules/sip-status/src/",
          "Modules\\SipTrunks\\": "app-modules/sip-trunks/src/",
          "Modules\\Speech\\": "app-modules/speech/src/",
          "Modules\\Tenant\\": "app-modules/tenant/src/",
          "Modules\\TenantLimits\\": "app-modules/tenant-limits/src/",
          "Modules\\TimeConditions\\": "app-modules/time-conditions/src/",
          "Modules\\Transcribe\\": "app-modules/transcribe/src/",
          "Modules\\VoicemailMessages\\": "app-modules/voicemail-messages/src/",
          "Modules\\Voicemails\\": "app-modules/voicemails/src/",
          "Modules\\XmlCdr\\": "app-modules/xml-cdr/src/"
      }
  }
  ```

### 3.2 Dynamic Autoloading for Custom Modules (`custom-modules/`)
Custom extensions in `custom-modules/` are dynamically registered during application boot:
* `ModuleServiceProvider` inspects `custom-modules/*/module.json`.
* Each manifest declares `"namespace": "CustomModules\\{Name}"`.
* A lightweight runtime PSR-4 autoloader or Composer class-loader registration (`spl_autoload_register`) maps the namespace to `custom-modules/{name}/src/` without requiring changes to root `composer.json`.

---

## 4. Module Discovery, Registration & Boot Sequence

### 4.1 Discovery Engine (`App\Providers\ModuleServiceProvider`)
1. **Manifest Scanning**:
   * Scans `app-modules/*/module.json` &rarr; Source: `core`
   * Scans `custom-modules/*/module.json` &rarr; Source: `custom`
   * Scans `vendor/*/*/module.json` &rarr; Source: `vendor`
2. **Caching**:
   * Compiles the discovery manifest to `bootstrap/cache/modules.php`.
   * Cache is cleared on `php artisan optimize:clear` and rebuilt on `php artisan optimize` or `php artisan module:sync`.
3. **Provider Registration & Boot**:
   * For each discovered module:
     * Check `ModuleState::isEnabled($name)`.
     * If enabled: dynamically register its declared `ModuleServiceProvider` subclass with the Laravel application container:
       `$this->app->register($providerClass);`
     * If disabled: **do not register the provider**.

### 4.2 Module Lifecycle Execution (`App\Support\ModuleServiceProvider`)
When an enabled module's provider boots:
1. `registerViews()`: Registers views under `{module}::`.
2. `registerTranslations()`: Adds multi-path cascading translation loader.
3. `registerMigrations()`: Registers database migrations.
4. `registerConfig()`: Merges optional config files.
5. `registerRoutes()`: Loads module `routes/web.php` or invokes `autoRegisterRouteGroups()`.
6. `registerLivewire()`: Registers module Livewire components.
7. `registerMenu($menu)`: Submits menu items with parent hierarchy to `MenuService`.
8. `registerPermissions($permission)`: Registers permissions with `PermissionService`.
9. `registerListeners()`: Subscribes module event listeners.
10. `registerCommands()`: Exposes module Artisan commands.

---

## 5. System Superadmin Backups Architecture

### 5.1 System-Level Responsibility
The `backups` module (`app-modules/backups/`) is a **System Superadmin** utility responsible for the complete appliance:
* Complete database backup and restoration (system, PBX, and tenant tables).
* Storage, recordings, greetings, voicemail files, and call center logs.
* Configuration, TLS certificates, and FreeSWITCH runtime states.
* Encryption keys, environment secrets, and credentials.

### 5.2 Decoupled Route & Menu Registration
1. **Clean Route Ownership**:
   * Remove `/panel/backups/*` routes from `app-modules/admin/routes/web.php`.
   * Define backup routes directly in `app-modules/backups/routes/web.php` (or via standard auto-registration).
2. **Clean Menu Ownership**:
   * Remove the `'backups'` menu definition from `Modules\Admin\Providers\ModuleServiceProvider`.
   * Define the menu item in `Modules\Backups\Providers\ModuleServiceProvider::menuItems()`:
     ```php
     protected function menuItems(): array
     {
         return [
             [
                 'key' => 'backups',
                 'label' => 'admin.backups',
                 'route' => 'panel.backups.index',
                 'permission' => 'backups.view',
                 'icon' => 'heroicon-o-archive-box-arrow-down',
                 'parent' => null, // Displayed as Core Administration / System utility
                 'order' => 85,
             ],
         ];
     }
     ```
3. **Permissions Gating**:
   * Retain strict permission gating in `AdminSeeder`: Super Administrators have full backup & restore permissions; ordinary Administrators are excluded from `backups.restore`.

---

## 6. UI Navigation Parity on `/panel/modules`

### 6.1 Subcategory Mapping
The module management workbench (`/panel/modules`) organizes all built-in and custom modules into the exact subcategories matching the navigation sidebar:

1. **Core Administration** (No subcategories)
   * `admin`, `auth`, `tenant`, `backups`, `logs`
2. **Accounts** (`pbx.accounts`)
   * `extensions`, `devices`, `gateways`, `sip-accounts`
3. **Connectivity** (`pbx.connectivity`)
   * `sip-profiles`, `sip-trunks`
4. **Call Routing** (`pbx.routing`)
   * `inbound-routes`, `outbound-routes`, `dialplans`, `dialplan-tools`, `ring-groups`, `ivr-menus`, `time-conditions`, `call-flows`, `call-forwards`, `follow-me`, `call-blocks`, `bridges`, `feature-codes`, `number-translations`, `emergency`
5. **Features** (`pbx.features`)
   * `call-centers`, `call-broadcast`, `conferences`, `conference-centers`, `fax`, `hot-desking`, `operator-panel`, `provision`, `pin-numbers`, `voicemails`
6. **Media** (`pbx.media`)
   * `recordings`, `music-on-hold`, `call-recordings`, `speech`, `transcribe`
7. **Advanced & System** (`pbx.advanced`)
   * `security`, `certificates`, `file-stores`, `email-connector`, `email-queue`, `email-templates`, `acl`, `tenant-limits`, `event-rate-limits`
8. **Monitoring & Diagnostics** (`pbx.monitoring`)
   * `active-calls`, `active-conferences`, `registrations`, `sip-status`, `call-center-active`, `voicemail-messages`, `xml-cdr`
9. **Custom Extensions**
   * Extensions located in `custom-modules/` or 3rd-party vendor packages, bearing a `badge-info` "Custom" badge.

### 6.2 Standardized DaisyUI 5 Controls
* **Toggle Switches**: Every module row features a `toggle toggle-success toggle-sm` with Livewire reactive binding (`wire:click="toggleEnabled('{{ $module->id }}')"`).
* **Loading Micro-Interaction**: Displays an inline DaisyUI loading spinner and locks pointer-events during state updates.
* **Core Protection**: Required core modules (`admin`, `auth`, `tenant`) have the toggle locked in the ON position with an `<x-tooltip>`: *"Core system infrastructure required by the platform."*
* **Safety Lock on Deletion**: Core modules have **no delete button**. Custom modules show a dropdown menu with "Delete Extension" that safely removes only the untracked directory.

---

## 7. Migration & Telephony Observer Safety

1. **Schema Migrations**:
   * Running `php artisan migrate` executes migrations for all discovered core modules regardless of enabled/disabled state. This guarantees database schema integrity and prevents missing foreign-key relation errors.
2. **Polymorphic Relations (`Relation::enforceMorphMap`)**:
   * Modules can declare their own polymorphic morph maps within `ModuleServiceProvider::boot()` via `Relation::morphMap([...])`.
3. **Routing Cache Invalidation (`RoutingCacheObserver`)**:
   * Move telephony model registration out of the monolithic `AppServiceProvider` and allow telephony modules to attach `RoutingCacheObserver` directly in their own domain providers or via an interface.

---

## 8. Custom Module Collision Prevention & Validation Guards

To prevent untrusted or poorly written third-party extensions in `custom-modules/` or `vendor/` from crashing the appliance or hijacking core behavior, the 4.x architecture implements strict **Pre-flight & Runtime Collision Guards**:

### 8.1 Module Identifier & Namespace Collisions
* **Precedence Rule**: Core built-in modules (`app-modules/`) always take precedence.
* **Manifest Validation**: During discovery in `ModuleServiceProvider`, any custom module declaring a `"name"` that matches an existing core module (e.g. `extensions`, `admin`, `security`, `dialplans`) is rejected with a descriptive error: `Custom module [{name}] collides with a core appliance module and cannot be loaded.`

### 8.2 Route & URL Prefix Hijacking Prevention
* **Route Name Clashes**: When custom modules register routes, route names are checked against the core route registry. Duplicate names (e.g. attempting to register `panel.extensions.index` or `panel.dashboard`) are blocked.
* **URL Prefix Isolation**: Standard custom modules are scoped to `/panel/{module-name}/*`. Custom modules are strictly prohibited from binding routes that hijack reserved core paths:
  * Authentication: `/panel/login`, `/panel/setup`, `/panel/logout`
  * System Core: `/panel/dashboard`, `/panel/profile`, `/panel/settings`, `/panel/modules`, `/panel/git-update`
  * Core Telephony: Existing core endpoints (`/panel/extensions`, `/panel/gateways`, etc.)

### 8.3 Database Schema & Migration Collisions
* **Pre-Migration Safety Inspection**:
  * Before executing migrations from `custom-modules/{name}/database/migrations/`, the migration runner inspects table creation calls.
  * If a custom migration attempts to create a table that already exists in the database (`Schema::hasTable($table)`), or attempts to alter core appliance tables (`users`, `admins`, `tenants`, `groups`, `permissions`, `modules`, `menus`), the migration is halted with a `MigrationCollisionException`.
* **Migration Class Names**: Custom migrations must use standard anonymous class migrations (`return new class extends Migration`) to avoid global class name collisions.

### 8.4 Language & Translation Dictionary Collisions
* **Multi-Tier Cascade Protection**:
  * Level 1 (Root Protection): Extends `detectRootTranslationCollisions()` to ensure custom module keys never overwrite core strings in `lang/{locale}/admin.php`.
  * Level 2 (Inter-Module Protection): Custom module translation keys are verified against all active core module cascading keys.
* **Graceful Fallback**: If a collision is detected, rather than crashing the PBX in production, the custom module's global cascading path is revoked, and its translations are restricted strictly to its explicit namespace (`__('{module-name}::{group}.{key}')`).

### 8.5 Permission & Menu Collisions
* **Permission Ownership**: `PermissionService` enforces module ownership. A custom module cannot register or overwrite an existing permission owned by another module (e.g., a custom extension declaring `extensions.view` or `admin.users.delete`).
* **Menu Key Clashes**: `MenuService` rejects custom menu registrations that collide with existing core menu keys.

---

## 9. Custom Module Scaffolding Architecture (`make:module`)

In the Domain-Driven Modular Monolith, built-in modules (`app-modules/`) are part of the permanent appliance monorepo maintained directly in source code. To keep the developer experience clean and avoid fragile runtime mutations of root `composer.json`, the scaffolding command `php artisan make:module` is dedicated exclusively to generating **third-party and community extensions** in `custom-modules/`:

```bash
php artisan make:module my-crm-connector --category="Integrations"
```

### Key Behaviors:
* **Target Location**: Scaffolds directly into `custom-modules/{name}/` (untracked, git-ignored).
* **Manifest (`module.json`)**: Generates the standard manifest tagged with `source: "custom"`, setting up namespace (`CustomModules\{Studly}` or `Modules\{Studly}`), display name, category, and dependencies.
* **Service Provider**: Generates `src/Providers/ModuleServiceProvider.php` extending `App\Support\ModuleServiceProvider`.
* **Zero Root Mutation**: Leaves root `composer.json` completely untouched.
* **Instant Activation**: Immediately discovered and autoloaded at runtime via `CustomModuleAutoloader` and validated by pre-flight collision guards.
* **Component Stubs**: Pre-generates boilerplate Livewire component directories, Blade views, migration directories, and collision-safe translation files (`lang/{en,es,fr}/admin.php`).

---

## 10. Authoritative Documentation Updates (`docs/modular-architecture.md`)

Update `docs/modular-architecture.md` with:
1. **Modular Monolith Principles**: Explaining the Domain-Driven Design rationale, benefits, and bounded context encapsulation.
2. **Module Directory Anatomy**: Clean structure highlighting direct PSR-4 autoloading without path repositories.
3. **Core vs. Custom Developer Guide**:
   * How to develop a first-party module in `app-modules/`.
   * How to develop a drop-in 3rd-party extension in `custom-modules/`.
   * Manifest schema reference (`module.json`), routing conventions, and dialplan hooks.
4. **Lifecycle & Administration Guide**:
   * Explaining runtime state toggling, cache clearing, and zero-downtime appliance updates.
   * Superadmin system backups role and operation.

---

## 11. Verification & Pre-Commit Quality Gate

Every change in this modernization must satisfy the verification checklist before merging on `4.x`:
1. `php artisan optimize:clear`: Cleans caches without errors.
2. `./vendor/bin/pint --dirty`: Code style conforms 100% to Pint rules.
3. `php artisan test --compact --parallel`: Full Pest suite passes.
4. `./vendor/bin/pest tests/Browser`: Browser tests pass with Playwright.
5. `composer dump-autoload -o`: Dumps optimized autoload files in < 3 seconds with zero path-repository warnings.
