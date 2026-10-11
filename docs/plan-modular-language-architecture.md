# Implementation Plan: Modular Translation Architecture (`app-modules/*/lang`)

**Document Version:** 2.0  
**Target Branch:** `3.x`  
**Scope:** All 60 modules in `app-modules/` and root `lang/{locale}/`  
**Primary Goal:** Relocate module-specific administrative and client translations out of the monolithic root `lang/{en,es,fr}/admin.php` and `client.php` into standardized, encapsulated module directories (`app-modules/{module}/lang/{locale}/admin.php`).

---

## 1. Executive Summary & Problem Statement

Currently, TallPBX houses 1,494 translation keys inside monolithic global files per language:
- `lang/en/admin.php` (1,494 keys, 1,661 lines)
- `lang/es/admin.php` (1,473 keys, 1,549 lines — 21 keys missing from English)
- `lang/fr/admin.php` (1,473 keys, 1,548 lines — 21 keys missing from English)
- `lang/{en,es,fr}/client.php` (~129 lines)

### Core Architectural Problems
1. **Breaks Module Encapsulation**: A self-contained module (e.g. `security`, `extensions`, `gateways`) cannot be inspected, maintained, or tested in isolation because its user-facing copy is scattered across massive global files.
2. **Developer & AI Agent Overhead**: Modifying, adding, or reviewing even a single UI button or status notice requires reading and editing three 1,600-line global files. This consumes tens of thousands of unnecessary context tokens, slows down tooling, and causes merge conflicts.
3. **Rigid Key Prefixes**: Keys in the root file are prefixed with module names (e.g. `security_threat_feeds_title`, `extensions_create_button`) to prevent collisions in the shared namespace.
4. **Locale Drift**: Because translations live in massive monolithic files, locale drift has accumulated: 21 keys currently exist in English that were never added to Spanish or French.
5. **No Transitional Shims on `3.x`**: In accordance with the repository's `AGENTS.md` guidelines (*"No Backwards Compatibility for Unreleased Series Branches"*), the migration must not introduce runtime fallback engines, legacy translation bridges, or dual-lookup shims. Migrations must be atomic per module: key extractions and template call-site updates land together in clean commits.

---

## 2. Target Architecture & Standard Conventions

### 2.1 Uniform Module Structure
Every module in `app-modules/` adopting translations will use the modern Laravel directory layout:

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

> [!NOTE]
> Files are only created when a module actually defines copy for that context. Modules without client portal features must NOT have empty `client.php` files created.

### 2.2 Global `lang/` Directory Boundary
An audit of the codebase confirms that only **45 keys** are shared across multiple modules or application shell components. The root `/lang` directory is reserved **strictly** for cross-module application shell and framework copy:
- `lang/{locale}/admin.php`: Generic actions (`save`, `saving`, `cancel`, `delete`, `edit`, `filter`, `actions`, `enabled`, `disabled`), global navigation menus, user/tenant shell, dashboard metrics, and universal tooltips (`tenant_select_tooltip`).
- `lang/{locale}/client.php`: Core portal shell, global tenant dashboard labels.
- `lang/{locale}/auth.php`, `home.php`, `mail.php`.

### 2.3 Translation Access Pattern & Prefix Normalization
Modules access their own translations using Laravel's standard package namespacing:
- Admin panel copy: `__('security::admin.threat_feeds_title')`
- Client portal copy: `__('extensions::client.voicemail_pin')`
- Global shared copy: `__('admin.save')`, `__('admin.cancel')`

**Prefix Stripping Rule**: When moving a module-specific key from the root `admin.php` into `app-modules/{module}/lang/{locale}/admin.php`, the redundant `{module}_` prefix is systematically stripped:
- Monolithic key: `__('admin.security_protect_my_ip')`
- Namespaced key: `__('security::admin.protect_my_ip')`

---

## 3. Implementation Phases

### Phase 1: Base Provider Infrastructure & Automated Parity Guards
**Files:** `app/Support/ModuleServiceProvider.php`, `tests/Feature/Architecture/TranslationParityTest.php`

1. **Standardize Module `lang` Path in `ModuleServiceProvider`**:
   Update `ModuleServiceProvider` to register `lang/` directly under the module root:
   ```php
   protected function translationPath(): ?string
   {
       $path = $this->modulePath().'/lang';

       return is_dir($path) ? $path : null;
   }

   private function registerTranslations(): void
   {
       if ($path = $this->translationPath()) {
           $this->loadTranslationsFrom($path, $this->moduleName());
       }
   }
   ```
   Remove any legacy custom loaders (e.g. in `app-modules/admin/src/Providers/ModuleServiceProvider.php`).

2. **Automated Locale Parity Test**:
   Create a Pest architecture test that scans all modules containing a `lang/` directory:
   - For every module, asserts that the keys in `es/admin.php` and `fr/admin.php` match `en/admin.php` exactly (`array_diff_key` is empty).
   - Prevents incomplete language files from being committed in any future module work.

3. **Missing Translation Fast-Fail in Tests**:
   Configure Laravel's translator in the testing bootstrap (`tests/TestCase.php` or `Pest.php`):
   ```php
   Lang::handleMissingKeysUsing(function (string $key, array $replace, ?string $locale) {
       throw new \RuntimeException("Missing translation key detected: [{$key}] for locale [{$locale}]");
   });
   ```
   This ensures that any forgotten translation key or broken namespace call immediately fails CI and feature tests rather than silently rendering the raw string in HTML.

---

### Phase 2: Root Locale Reconciliation & Pilot Migration
**Target Pilot:** `app-modules/certificates/` or `app-modules/hot-desking/` (Lightweight modules already containing empty `lang/` scaffolding)

1. **Reconcile 21 Missing Root Keys in `es` and `fr`**:
   Before extracting translations into module files, backfill the 21 missing translation keys in `lang/es/admin.php` and `lang/fr/admin.php` so child modules do not inherit dictionary gaps.
2. **Execute Pilot Module Migration**:
   - Extract `certificates` keys from root `lang/{en,es,fr}/admin.php` into `app-modules/certificates/lang/{en,es,fr}/admin.php`.
   - Strip `certificates_` prefix from keys.
   - Update `app-modules/certificates` Blade templates and components to `__('certificates::admin.key')`.
   - Update any tests in `app-modules/certificates/tests` asserting translation strings.
   - Run tests: `./vendor/bin/pest app-modules/certificates`.
   - Verify zero regression and clean test pass.

---

### Phase 3: Telephony Core Modules Atomic Migration
Migrate the primary high-traffic telephony modules in discrete, atomic commits:
1. `app-modules/extensions/`
2. `app-modules/gateways/`
3. `app-modules/sip-profiles/`
4. `app-modules/sip-trunks/`
5. `app-modules/inbound-routes/`
6. `app-modules/outbound-routes/`
7. `app-modules/dialplans/`
8. `app-modules/voicemails/`

For each module in its own commit:
- Create `lang/{en,es,fr}/admin.php`.
- Move keys from root `admin.php` into module files with normalized prefixes.
- Update module Blade views and Livewire components.
- Run module Pest tests: `php artisan test app-modules/{module} --compact`.

---

### Phase 4: Deterministic Usage-Based Automated Extraction for Remaining Modules
**Tool:** Scratch Artisan script or CLI command (`php artisan modules:extract-translations`)

> [!WARNING]
> Do NOT parse section header comments (`// ─── Module Name ───`). Root `lang/en/admin.php` has 76 section headers, whereas `es` and `fr` have only 33 headers, and several headers group keys by UI type rather than module name.

1. **Deterministic Classifier Algorithm**:
   - Parse all PHP and Blade files across `app-modules/{module}/`.
   - Scan for literal translation calls `__('admin.<key>')` or `trans('admin.<key>')`.
   - Handle dynamic string assembly explicitly (e.g. `'admin.security_threat_feed_status_'.$feedStatus`).
   - Audit unreferenced keys (up to 258 keys in root `admin.php` with no literal call site): distinguish between dynamically assembled string families vs truly orphaned dead keys.
   - If a key is referenced exclusively within one module: migrate to that module's `lang/{en,es,fr}/admin.php`.
   - If a key is referenced across multiple modules or in `app/`/`resources/`: retain in root `lang/`.
2. **Template Rewriter**:
   - Atomically replace `__('admin.<module_key>')` with `__('<module>::admin.<key>')` across all matching module templates.

---

### Phase 5: Client Portal (`client.php`), Root Cleanup & Final Verification
1. **Client Portal Translations (`client.php`)**:
   - Extract module-specific strings from `lang/{en,es,fr}/client.php` into corresponding `app-modules/{module}/lang/{locale}/client.php` files.
   - Retain core portal shell labels in root `client.php`.
2. **Root Language File Trim**:
   - Trim root `lang/{en,es,fr}/admin.php` down to its core ~45 shared actions and framework labels.
   - Ensure strict 100% key parity between English, Spanish, and French for all remaining shared root keys.
3. **Verification**:
   - `php artisan optimize:clear`
   - `./vendor/bin/pint --dirty --test`
   - `php artisan test --compact --parallel`
   - Run translation parity test: `./vendor/bin/pest tests/Feature/Architecture/TranslationParityTest.php`

---

### Phase 6: Security Module Migration (Sequencing with Security Refactor)
- The Security module contains 372 keys and 489 template call sites, with exactly 0 keys referenced outside `app-modules/security`.
- **Coordination Rule**: To prevent merge conflicts and Git churn with the Security Module architectural refactor (Plan B), the security translation migration must occur either:
  1. Fully **before** Plan B extracts Livewire child components, OR
  2. Fully **after** Plan B's new component hierarchy lands.
  It must NOT be performed concurrently while security component files are actively being split.

---

## 4. Verification & Success Criteria Checklist

- [ ] `php artisan optimize:clear` executes cleanly without stale view or provider artifacts.
- [ ] No raw translation strings (e.g. `module::admin.key`) leak into rendered panel HTML.
- [ ] Language switching across English, Spanish, and French works identically in all modules.
- [ ] `TranslationParityTest` passes: 100% key parity across `en`, `es`, and `fr` in every module.
- [ ] `Lang::handleMissingKeysUsing()` raises zero exceptions across the entire Pest test suite.
- [ ] Root `lang/{en,es,fr}/admin.php` contains strictly generic, cross-module shell copy.
- [ ] Pint code style passes cleanly: `./vendor/bin/pint --dirty --test`.
- [ ] Full test suite passes: `php artisan test --compact --parallel`.
