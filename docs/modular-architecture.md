# TallPBX Modular Architecture & Translation Guide

This document is the authoritative architectural and developer reference for TallPBX's modular architecture (`app-modules/`), service provider lifecycle, and modular translation system. It provides administrators and developers with the design rationale, conventions, runtime guards, and scaffolding workflows used throughout the project.

---

## 1. Domain-Driven Modular Monolith Architecture

TallPBX 4.x organizes its telecommunications, administrative, and PBX capabilities as a **Domain-Driven Modular Monolith**. Rather than maintaining all models, controllers, Livewire components, routes, migrations, and translation strings in a single monolithic `app/` directory or treating internal modules as fragile pseudo-packages, each domain feature (such as `gateways`, `extensions`, `sip-profiles`, `voicemails`, `security`, and `firewall`) is encapsulated into a cohesive **bounded context** in `app-modules/`.

### Core Architectural Principles
1. **Single Deployable Monolith**: All built-in modules belong to the unified core codebase and update atomically via Git. They are NOT independent microservices or separate Composer packages.
2. **Direct Root PSR-4 Autoloading**: All built-in modules are mapped directly in root `composer.json` via standard PSR-4 (`Modules\{Domain}\` => `app-modules/{domain}/src/`). Root `composer.json` contains 0 path repositories and 0 `tallpbx/module-*` package requirements.
3. **No Physical Deletion of Core Modules**: Built-in modules in `app-modules/` are permanent parts of the system and are never deleted from disk. Disabling a module is strictly logical (`enabled = false` in the database registry, suppressing route, view, and provider registration).
4. **Clean Extensibility Boundary (`custom-modules/` & `vendor/`)**: Third-party community extensions reside in the untracked, git-ignored `custom-modules/{name}/` directory or in standard Composer packages under `vendor/`. External extensions are guarded by strict Pre-flight Collision Guards (Route, Database Schema, Module Name, Language Keys, and Permissions).
5. **Standardized Base Service Provider**: All modules boot via a child class extending `App\Support\ModuleServiceProvider`, eliminating repetitive boilerplate for views, migrations, routes, Livewire components, permissions, and translations.
6. **Decoupled Cross-Domain Communication**: High cohesion within domains, low coupling between domains. Modules communicate through Laravel events, service contracts, or published interfaces rather than deep cross-domain database coupling.
7. **Zero-Friction Scaffolding**: The Artisan command `php artisan make:module <name>` scaffolds production-ready modules conforming strictly to project conventions.

---

## 2. Directory Layout of a Module

Each module under `app-modules/{module-name}/` follows a standardized layout:

```
app-modules/{module-name}/
├── module.json                 # TallPBX module manifest (schema, display name, category)
├── config/                     # Optional module configuration merged over defaults
│   └── {module-name}.php
├── database/
│   └── migrations/             # Database migrations auto-loaded by provider
├── lang/                       # Modular translation dictionaries (en, es, fr)
│   ├── en/
│   │   ├── admin.php           # Admin panel strings for this module
│   │   └── client.php          # Tenant portal strings (if module has client UI)
│   ├── es/
│   │   └── admin.php
│   └── fr/
│       └── admin.php
├── resources/
│   └── views/                  # Blade views auto-registered under {module-name}:: namespace
│       └── index.blade.php
├── routes/                     # Optional route definition (web.php / api.php)
├── src/
│   ├── Database/
│   │   └── Factories/          # Model factories for testing and seeding
│   ├── Livewire/               # Livewire 4 full-page workbenches and modals
│   ├── Models/                 # Eloquent models
│   ├── Providers/
│   │   └── ModuleServiceProvider.php # Module registration provider
│   └── Services/               # Domain business logic and FreeSWITCH interfaces
└── tests/                      # Automated Pest unit and feature tests
```

---

## 3. ModuleServiceProvider Lifecycle

All module service providers extend `App\Support\ModuleServiceProvider` (which extends `Illuminate\Support\ServiceProvider`). During application booting, the base provider executes a structured sequence:

```mermaid
flowchart TD
    classDef step fill:#eef0f3,stroke:#5b8fd9,stroke-width:2px,color:#1c2a40
    classDef guard fill:#fef9ec,stroke:#d29922,stroke-width:2px,color:#5a3e08
    classDef pass fill:#e8f5ec,stroke:#3fb950,stroke-width:2px,color:#1a6b32
    classDef reject fill:#fde8e8,stroke:#e05252,stroke-width:2px,color:#7a1010

    A["Boot ModuleServiceProvider"]:::step --> B["1. registerViews()<br/>resources/views -> moduleName::"]:::step
    B --> C["2. registerTranslations()<br/>lang/ or resources/lang/"]:::step
    
    C --> D{"Translations Exist?"}:::guard
    D -->|No| E["3. registerMigrations()"]:::step
    D -->|Yes| F["validateNoCollisions()<br/>Check against root lang/en/admin.php"]:::guard
    
    F -->|Collision Detected| G["❌ REJECT MODULE LANGUAGE FILE<br/>Throw LogicException in local/test<br/>Log critical error in production"]:::reject
    F -->|Zero Collisions| H["✅ Register Multi-Path<br/>loader->addPath(path)<br/>loadTranslationsFrom(path, module)"]:::pass
    
    H --> E
    E --> I["4. registerConfig()<br/>config/module.php"]:::step
    I --> J{"Module Enabled in ModuleState?"}:::guard
    J -->|No| K["Halt Further Registration"]:::step
    J -->|Yes| L["5. Register Routes, Livewire, Menu, Permissions, Listeners, Commands"]:::pass
```

---

## 4. Modular Translation Architecture (Native Path Cascading)

TallPBX uses **Option A: Native Path Cascading** via Laravel's translation `FileLoader::addPath()`. This design moves module-specific strings out of the monolithic root files while ensuring **zero UI presentation changes, zero template rewrites, and immutable button identifiers**.

### 4.1 Why Native Path Cascading?
- **Zero Template Churn**: Blade views, Livewire components, Form Requests, and models retain their existing `__('admin.key_name')` calls. No view has to be updated to a package namespace like `__('gateways::admin.key')`.
- **Immutable Button Identifiers**: Core button identifiers (`button_save`, `save`, `cancel`, `edit`, `delete`) remain completely stable. UI presentation in English, Spanish, and French is **100% frozen and identical**.
- **Physical Module Encapsulation**: Module strings live in `app-modules/{module}/lang/{locale}/admin.php`, allowing developers to inspect and maintain module translations right alongside module views and code.
- **Native Multi-Path Resolution**: In Laravel 13, `Illuminate\Translation\FileLoader` merges registered paths sequentially via `array_replace_recursive()`. Calling `__('admin.gateway_host')` resolves directly from `app-modules/gateways/lang/en/admin.php` without prefix rewriting.

### 4.2 The Collision Hazard & Strict Key Partitioning
Because cascading paths merge directly into the global group dictionary (`admin`), any key defined in a module language file that matches a root key would overwrite that root key globally.

To prevent dictionary corruption:
1. **Root Global Dictionary Sanctity (`lang/{locale}/admin.php`)**:
   - Generic actions (`save`, `cancel`, `delete`, `edit`, `filter`, `actions`, `enabled`, `disabled`, `status`, `confirm`, `back`) belong **strictly** to the root dictionary.
   - Global navigation menu labels (`admin.gateways`, `admin.extensions`, `admin.sip_profiles`) remain in the root dictionary so navigation renders regardless of module load states.
   - Shell banners, tenant context headers, and global system messages remain in root.
2. **Module Key Domain Relevance**:
   - Every key defined in a module language file **must** include the module or entity domain in its identifier (e.g. `gateway_host`, `gateway_name_tooltip`, `create_gateway`, `modal_delete_gateway_title`, `no_gateways_found`, `sip_trunk_port`).
   - Module language files must **never** define generic action keys like `save` or `delete`.

### 4.3 Mandatory Module Language File Header Comment
Every module language file (`app-modules/{module}/lang/{en,es,fr}/admin.php`) must begin with the standardized collision warning docblock:

```php
<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: {displayName} ({module_name})
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity name
 *    (e.g. '{entity}_host', 'create_{entity}', 'no_{entities}_found').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'edit', 'enabled', 'disabled',
 *    'status', 'actions') belong strictly in root lang/{locale}/admin.php and
 *    must NEVER be defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    // ...
];
```

### 4.4 Runtime Collision Guard
During module boot, `App\Support\ModuleServiceProvider` validates that module language files do not collide with root keys:
- The provider parses keys from the module's `lang/en/{group}.php`.
- It computes the intersection with the root `lang/en/{group}.php` keys.
- **Fail-Fast Enforcement**:
  - In `local` and `testing` environments: throws a `LogicException`:
    ```
    Module [gateways] language file [admin] collides with root dictionary for key(s): [save, delete]. Refusing to load.
    ```
  - In `production`: logs a critical alert to `Log::critical()` and skips adding the cascading path, protecting the integrity of the root dictionary.

### 4.5 Automated Architecture Test Suite (`TranslationParityTest`)
The test suite at `tests/Feature/Architecture/TranslationParityTest.php` runs on every CI run and local test cycle:
- **Zero Root Collisions**: Asserts that no module language file defines any key that exists in root `lang/{locale}/admin.php`.
- **Zero Cross-Module Collisions**: Asserts that no two modules define the same translation key.
- **Entity Domain Relevance**: Asserts that keys defined in a module include the module or entity domain identifier.
- **100% Locale Parity**: Asserts that every key defined in `en` exists with identical keys in `es` (Spanish) and `fr` (French).

---

## 5. Scaffolding Custom Extensions with Artisan (`make:module`)

In the Domain-Driven Modular Monolith, built-in modules (`app-modules/`) are part of the permanent appliance monorepo maintained directly in version control. To keep the developer experience clean and avoid fragile runtime mutations of root `composer.json`, the scaffolding command `php artisan make:module` is dedicated exclusively to generating **third-party community extensions and plugins** in `custom-modules/`:

```bash
php artisan make:module my-crm-connector \
    --display-name="My CRM Connector" \
    --description="Third-party CRM integration for customer lookups" \
    --category="Integrations"
```

### Key Behaviors:
- **Target Location**: Scaffolds directly into `custom-modules/{name}/` (untracked, git-ignored).
- **Zero Root Mutation**: Leaves root `composer.json` completely untouched.
- **Manifest (`module.json`)**: Generates the standard manifest tagged with `source: "custom"`, setting up namespace (`CustomModules\{Studly}`), display name, category, and dependencies.
- **Service Provider**: Generates `src/Providers/ModuleServiceProvider.php` extending `App\Support\ModuleServiceProvider` with `moduleNamespace()` returning `CustomModules\{Studly}`.
- **Instant Dynamic Autoloading**: Automatically discovered and autoloaded at runtime via `CustomModuleAutoloader` (`CustomModules\` namespace) without running `composer dump-autoload`.
- **Component Stubs**: Pre-generates boilerplate Livewire component directories, model factories, Blade views, migration directories, and collision-safe translation files (`lang/{en,es,fr}/admin.php`).
- **Core Collision Protection**: Rejects names that collide with existing core modules in `app-modules/` (e.g. `php artisan make:module extensions` will fail with an error).

---

## 6. Translation Authoring Guidelines

When creating translation keys for a module:
1. **Module Domain Prefixing**: Every key defined in `app-modules/{module}/lang/{en,es,fr}/admin.php` must include the entity or domain name (e.g. `gateway_*`, `create_gateway`, `modal_delete_gateway_title`).
2. **Root Action Keys**: Generic action labels (`save`, `delete`, `edit`, `cancel`, `enabled`, `disabled`, `status`, `actions`) belong strictly in root `lang/{locale}/admin.php` and must never be defined in module dictionaries.
3. **Locale Parity**: All module keys must be populated across all supported languages (`en`, `es`, `fr`).
4. **Header Docblock**: All module translation files must begin with the collision prevention docblock.
5. **Verification**: Verify that keys follow all collision and parity rules:
   ```bash
   php artisan optimize:clear
   ./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php
   ```
