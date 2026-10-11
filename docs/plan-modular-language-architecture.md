# Implementation Plan: Modular Translation Architecture (`app-modules/*/lang`)

**Document Version:** 1.0  
**Target Branch:** `3.x`  
**Scope:** All 60 modules in `app-modules/` and root `lang/{locale}/`  
**Primary Goal:** Relocate module-specific administrative and client translations out of the monolithic root `lang/{en,es,fr}/admin.php` and `client.php` into standardized, encapsulated module directories (`app-modules/{module}/lang/{locale}/admin.php`).

---

## 1. Executive Summary & Problem Statement

Currently, TallPBX houses over 1,600 translation keys inside a single global file per language:
- `lang/en/admin.php` (~1,640 lines)
- `lang/es/admin.php` (~1,530 lines)
- `lang/fr/admin.php` (~1,530 lines)

### The Problems
1. **Breaks Module Encapsulation**: A self-contained module (e.g. `security`, `extensions`, `gateways`) cannot be inspected, maintained, or tested in isolation because its user-facing copy is scattered across massive global files.
2. **Developer & AI Agent Overhead**: Modifying, adding, or reviewing even a single UI button or status notice requires reading and editing three 1,600-line global files. This consumes tens of thousands of unnecessary context tokens, slows down tooling, and causes merge conflicts.
3. **Rigid Key Prefixes**: Keys in the root file are prefixed with module names (e.g. `security_threat_feeds_title`, `extensions_create_button`) to prevent collisions in the shared namespace.

---

## 2. Target Architecture & Standard Conventions

### 2.1 Uniform Module Structure
Every module in `app-modules/` will adopt the exact same directory layout:

```
app-modules/{module-name}/
└── lang/
    ├── en/
    │   ├── admin.php       # Admin panel strings for this module
    │   └── client.php      # Tenant/user portal strings (if applicable)
    ├── es/
    │   ├── admin.php
    │   └── client.php
    └── fr/
        ├── admin.php
        └── client.php
```

### 2.2 Global `lang/` Directory Boundary
The root `/lang` directory is reserved **strictly** for cross-module application shell and framework copy:
- `lang/{locale}/admin.php`: Generic actions (`save`, `saving`, `cancel`, `delete`, `edit`, `filter`, `actions`, `enabled`, `disabled`), global navigation menus, user/tenant shell, dashboard metrics.
- `lang/{locale}/client.php`: Core portal shell, global tenant dashboard labels.
- `lang/{locale}/auth.php`, `home.php`, `mail.php`.

### 2.3 Translation Access Pattern
Modules access their own translations using Laravel's standard package namespacing:
- Admin panel copy: `__('security::admin.threat_feeds_title')`
- Client portal copy: `__('extensions::client.voicemail_pin')`
- Global shared copy: `__('admin.save')`, `__('admin.cancel')`

---

## 3. Implementation Phases

### Phase 1: Base Provider Infrastructure & Fallback Engine
**File:** `app/Support/ModuleServiceProvider.php`

1. **Auto-Discovery of Module `lang`**:
   Update `ModuleServiceProvider` to automatically detect both `app-modules/{name}/lang` and `app-modules/{name}/resources/lang`, registering the module's namespace:
   ```php
   protected function translationPath(): ?string
   {
       if (is_dir($this->modulePath().'/lang')) {
           return $this->modulePath().'/lang';
       }

       if (is_dir($this->modulePath().'/resources/lang')) {
           return $this->modulePath().'/resources/lang';
       }

       return null;
   }

   private function registerTranslations(): void
   {
       if ($path = $this->translationPath()) {
           $this->loadTranslationsFrom($path, $this->moduleName());
       }
   }
   ```

2. **Zero-Regression Fallback Bridge**:
   To prevent runtime breaks while views are incrementally migrated from `__('admin.module_key')` to `__('{module}::admin.key')`, implement a fallback mechanism:
   - Allow the Laravel Translator or a custom loader to check the module namespace if an `admin.module_*` key is queried, OR maintain legacy aliases during the transition.

---

### Phase 2: Pilot Migration — The Security Module
**Target Module:** `app-modules/security/` (Accounts for 457 translation calls, ~30% of total module strings)

1. Create directory structure:
   - `app-modules/security/lang/en/admin.php`
   - `app-modules/security/lang/es/admin.php`
   - `app-modules/security/lang/fr/admin.php`
2. Extract all `// ─── Security Module` keys (lines ~1269–1475) from `lang/{en,es,fr}/admin.php` into the new module files.
3. Clean up keys to remove redundant `security_` prefixes where appropriate, or support both forms during transition.
4. Update `app-modules/security/resources/views/**/*.blade.php` and Livewire components to reference `__('security::admin.key')`.
5. Update `app-modules/security/tests/SecurityManagerThreatFeedTest.php` and related tests to test `security::admin` strings directly.
6. Verify: `php artisan test app-modules/security --compact --parallel`.

---

### Phase 3: Telephony Core Modules
Migrate the primary high-traffic telephony modules:
1. `app-modules/extensions/`
2. `app-modules/gateways/`
3. `app-modules/sip-profiles/`
4. `app-modules/sip-trunks/`
5. `app-modules/inbound-routes/`
6. `app-modules/outbound-routes/`
7. `app-modules/dialplans/`
8. `app-modules/voicemails/`

For each module:
- Create `lang/{en,es,fr}/admin.php`.
- Extract keys from root `admin.php`.
- Update Blade views to `__('{module}::admin.key')`.
- Run module-specific tests.

---

### Phase 4: Batch Migration of Remaining Modules
Automate extraction for the remaining ~50 modules using an Artisan migration script:
1. **Extraction Artisan Command** (`php artisan modules:extract-translations`):
   - Parse section headers (`// ─── Module Name ───`) in `lang/en/admin.php`, `lang/es/admin.php`, and `lang/fr/admin.php`.
   - Match headers to corresponding module kebab names in `app-modules/`.
   - Write `lang/{en,es,fr}/admin.php` inside each module.
   - Remove extracted keys from root `lang/*/admin.php`.
2. **Template Reference Transformer**:
   - Safely update `__('admin.<module_key>')` in module Blade views to `__('<module>::admin.<key>')`.

---

### Phase 5: Root Language Files Final Cleanup & Verification
1. Trim `lang/{en,es,fr}/admin.php` down to its core ~150 shared keys (Save, Actions, Auth, Global Navigation, Dashboard shell).
2. Ensure full parity across English, Spanish, and French for all shared keys.
3. Run `php artisan optimize:clear`.
4. Run Pint: `./vendor/bin/pint --dirty`.
5. Run full test suite: `php artisan test --compact --parallel`.

---

## 4. Verification & Testing Checklist

- [ ] `php artisan optimize:clear` clears all compiled views and translator caches cleanly.
- [ ] No raw translation keys (e.g. `security::admin.something`) leak into the rendered HTML of any panel page.
- [ ] Language switching (English, Spanish, French) functions identically across all modules.
- [ ] Module tests pass without requiring root `lang/en/admin.php` fixtures.
- [ ] Root `lang/en/admin.php` contains strictly generic, shared UI copy.
