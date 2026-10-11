# Implementation Plan: Modular Translation Architecture (`app-modules/*/lang`)

**Document Version:** 3.1  
**Target Branch:** `3.x`  
**Architecture:** Option A — Native Path Cascading (`FileLoader::addPath`) with Automated Collision Prevention & Runtime Guard  
**Scope:** Modules in `app-modules/` and root `lang/{locale}/`  
**Primary Goal:** Relocate module-specific administrative and client translations out of the monolithic root `lang/{en,es,fr}/admin.php` and `client.php` into encapsulated module directories (`app-modules/{module}/lang/{locale}/admin.php`) using Laravel's native multi-path cascading loader, with **zero changes to UI presentation, zero changes to button identifiers, zero template rewrites, and strict collision prevention**.

---

## 1. Executive Summary & Core Invariants

### 1.1 Non-Negotiable Core Invariants
1. **Zero UI Changes:** Absolutely **nothing** changes in how language is presented in the user interface. If a button in the gateways module previously said "Save" or "Delete", it continues to say that in English, Spanish, and French.
2. **Identifier & Call Site Stability:** Button identifiers, translation keys, and view call sites are **never** renamed or modified. If a view calls `__('admin.gateway_host_tooltip')` or `__('admin.save')`, that call site remains untouched.
3. **Mandatory Module Name in Keys (Collision Prevention):** Every key defined in a module language file **must** include the module/entity name (e.g. `gateway_host_tooltip`, `no_gateways_found`, `modal_delete_gateway_title`, `sip_trunk_port`). We do **not** strip prefixes into generic words like `host`, `title`, or `create`.
4. **Global Generic Integrity:** Generic actions (`save`, `delete`, `cancel`, `edit`, `create`, `enabled`, `disabled`, `actions`, `status`) and shell navigation menu labels in `ModuleServiceProvider` remain in the root `lang/{locale}/admin.php` dictionary.
5. **Runtime Collision Guard:** `ModuleServiceProvider` actively inspects module language files upon boot and **refuses to load** any module language file that collides with keys in the root global dictionary.
6. **Mandatory File Header Comment:** Every module language file and newly scaffolded module language file must include a standardized header comment explaining the cascading collision rule and key-naming requirements.
7. **Complete Locale Parity:** All keys extracted to module language files must exist in all shipped locales (`en`, `es`, `fr`) with 100% key parity.

### 1.2 Core Architectural Benefits of Option A
- **Zero Template Churn:** Not a single Blade view or Livewire component needs to be rewritten.
- **Physical Module Encapsulation:** Module-specific keys physically move into `app-modules/{module}/lang/{en,es,fr}/admin.php`.
- **100% Native Laravel 13:** Leverages `Illuminate\Translation\FileLoader::addPath()`. Laravel natively merges module paths over root dictionaries via `array_replace_recursive()`.
- **Works in All Contexts:** Operates seamlessly across Blade views, Livewire components, Form Requests, Eloquent models, Artisan commands, and background FreeSWITCH ESL queue workers.

---

## 2. Target Architecture & Conventions

### 2.1 Uniform Module Structure
Modules owning translations define the standard Laravel directory layout:

```
app-modules/{module-name}/
└── lang/
    ├── en/
    │   ├── admin.php       # Admin panel strings for this module
    │   └── client.php      # Tenant/user portal strings (only if module has client UI)
    ├── es/
    │   ├── admin.php
    │   └── client.php
    └── fr/
        ├── admin.php
        └── client.php
```

### 2.2 Mandatory Module Language File Header Comment
Every module language file must begin with this standardized header docblock:

```php
<?php

/**
 * --------------------------------------------------------------------------
 * Module Language Lines: {module_name}
 * --------------------------------------------------------------------------
 *
 * COLLISION PREVENTION & KEY NAMING RULES:
 * Under TallPBX's multi-path cascading translation architecture, module
 * language files merge directly into the global group dictionary (e.g. 'admin')
 * at runtime.
 *
 * To prevent collisions across modules or with the root dictionary:
 * 1. Every key defined in this file MUST include the module/entity prefix
 *    (e.g. '{entity}_*').
 * 2. Generic keys (e.g. 'save', 'cancel', 'delete', 'enabled', 'disabled')
 *    belong strictly in root lang/{locale}/admin.php and must NEVER be
 *    defined in module language files.
 * 3. Module language files will be rejected at runtime by ModuleServiceProvider
 *    if any collision with root language keys is detected.
 */

return [
    // ...
];
```

### 2.3 Global `lang/` Directory Boundary
The root `/lang` directory is reserved strictly for cross-cutting application shell, universal navigation, and generic actions:
- `lang/{locale}/admin.php`: Generic actions (`save`, `cancel`, `delete`, `edit`, `filter`, `actions`, `enabled`, `disabled`), global navigation menu labels (`admin.gateways`, `admin.extensions`, etc.), user/tenant shell, dashboard metrics, and universal tooltips (`tenant_select_tooltip`).
- `lang/{locale}/client.php`: Core portal shell, global tenant dashboard labels.
- `lang/{locale}/auth.php`, `home.php`, `mail.php`.

### 2.4 Runtime Collision Guard in `App\Support\ModuleServiceProvider`
During module booting, before registering any module translation path with the loader:
1. The provider loads the module's keys from `lang/en/{group}.php`.
2. It checks against the root `lang/en/{group}.php` keys.
3. If an intersection/collision is detected:
   - In `local` and `testing` environments: throws a `LogicException` (`"Module [{$module}] language file [{$group}] collides with root dictionary for key(s): [{$keys}]. Refusing to load."`).
   - In `production`: logs a critical error and skips adding the path, preventing corruption of global translations.
4. If no collision exists, it registers `app('translation.loader')->addPath($path)`.

---

## 3. Implementation Plan & Phases

### Phase 1: Base Provider Infrastructure, Generator Scaffolding & Collision Guards
1. **ModuleServiceProvider Runtime Collision Guard**:
   Implement `validateNoCollisions()` and register `$loader->addPath($path)` in `App\Support\ModuleServiceProvider`.
2. **Module Generator Scaffolding (`MakeModuleCommand`)**:
   Update `php artisan make:module` to automatically scaffold `lang/en/admin.php`, `lang/es/admin.php`, and `lang/fr/admin.php` containing the mandatory collision warning header docblock.
3. **Reconcile 21 Missing Root Keys in `es` and `fr`**:
   Ensure `lang/es/admin.php` and `lang/fr/admin.php` have 100% key parity with `lang/en/admin.php`.
4. **Automated Collision & Parity Test Suite**:
   Implement `tests/Feature/Architecture/TranslationParityTest.php`:
   - **Root Collision Check:** Asserts 0 module keys collide with root keys.
   - **Cross-Module Collision Check:** Asserts 0 keys collide between different modules.
   - **Module Name in Key Check:** Asserts that every key in a module's file includes the module/entity name.
   - **Locale Parity Check:** Asserts 100% key parity across `en`, `es`, and `fr`.

### Phase 2: Telephony Core Modules Atomic Migration
Migrate the primary telephony modules one by one in clean, atomic commits:
1. [x] `app-modules/gateways/` (19 keys migrated across `en`, `es`, `fr`; tests passing)
2. [x] `app-modules/sip-profiles/` (16 keys migrated across `en`, `es`, `fr`; tests passing)
3. [x] `app-modules/sip-trunks/` (14 keys migrated across `en`, `es`, `fr`; tests passing)
4. [x] `app-modules/extensions/` (19 keys migrated across `en`, `es`, `fr`; tests passing)
5. [x] `app-modules/inbound-routes/` (8 keys migrated across `en`, `es`, `fr`; tests passing)
6. [x] `app-modules/outbound-routes/` (8 keys migrated across `en`, `es`, `fr`; tests passing)
7. [x] `app-modules/dialplans/` (15 keys migrated across `en`, `es`, `fr`; tests passing)
8. [ ] `app-modules/voicemails/` (next up in queue)

**Per-Module Atomic Workflow:**
- Extract the module's entity-specific keys (keeping their exact key names intact) from root `admin.php` into `app-modules/{module}/lang/{en,es,fr}/admin.php` with the required header comment.
- Remove the extracted keys from root `lang/{en,es,fr}/admin.php`.
- Do **not** touch Blade templates, Livewire classes, or button identifiers.
- Run targeted module tests + architecture collision test.
- Commit atomically per module.

### Phase 3: Remaining Modules Migration
Migrate remaining modules following the exact same pattern:
- Extract entity-specific keys into module `lang/{en,es,fr}/admin.php`.
- Remove extracted keys from root files.
- Keep templates and identifiers untouched.
- Verify tests and commit.

### Phase 4: Final Verification & Clean Baseline
- `php artisan optimize:clear`
- `./vendor/bin/pint --dirty --test`
- `./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php`
- `php artisan test --compact --parallel`

---

## 4. Verification Checklist
- [x] Every module language file contains the required collision-prevention header docblock.
- [x] `php artisan make:module` generates `lang/{en,es,fr}/admin.php` with the collision header docblock.
- [x] Runtime collision guard in `ModuleServiceProvider` actively refuses colliding files.
- [x] Automated architecture test asserts zero root collisions, zero cross-module collisions, module names in keys, and 100% locale parity.
- [x] No button identifiers or labels were modified in any view template.
- [x] UI appearance and copy in English, Spanish, and French is 100% identical to baseline.
- [ ] All automated feature and architecture tests pass (in progress across remaining modules).

