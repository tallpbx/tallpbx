# TallPBX Project Audit

**Date:** 2026-09-21 · **Version:** v1.1.2 · **Branch:** `main`

---

## Executive Summary

TallPBX is a well-architected, multi-tenant PBX management platform built on the TALL stack (Tailwind, Alpine, Livewire, Laravel) with a modular architecture spanning **59 modules**, **201 routes**, and **~53k lines of PHP** application code. The project demonstrates strong engineering discipline in several areas — particularly security architecture, permission enforcement, and modular conventions — while having notable gaps in test coverage, minor code duplication, and translation parity that merit attention.

| Metric | Value |
|---|---|
| PHP Version | ^8.3 (CI runs 8.5) |
| Laravel Version | 13.8 |
| Livewire Version | 4.0 |
| Total Modules | 59 |
| Routes | 201 |
| App Core PHP | ~16,900 lines |
| Module Source PHP | ~36,750 lines |
| Blade Views | ~13,860 lines (142 files) |
| Test Code | ~37,660 lines (256 test files) |
| Dusk Browser Tests | 7 files |
| Migrations | 72 files |
| Translations | EN: 1298 / ES: 1283 / FR: 1282 entries |

---

## 1. Architecture & Design ✅ Strong

### Strengths

- **Modular Architecture**: Each of the 59 functional domains lives under `app-modules/{name}/` with its own `composer.json`, service provider, views, migrations, and routes. This is a clean, maintainable separation.

- **ModuleServiceProvider Base Class** ([ModuleServiceProvider.php](file:///var/www/tallpbx/app/Support/ModuleServiceProvider.php)): Excellent convention-over-configuration approach. Modules need only define `moduleName()` and `moduleNamespace()` — views, translations, migrations, routes, Livewire components, menu items, and permissions are auto-registered. Route auto-registration (Pattern 4) eliminates boilerplate routing.

- **CrudService Base Class** ([CrudService.php](file:///var/www/tallpbx/app/Support/CrudService.php)): Reduces duplication for simple CRUD services.

- **Unified Panel Architecture**: Single layout, single sidebar, single route tree under `/panel/`. Both Admin and User models share the same UI surface, with permission gating controlling visibility. Clean separation via dual auth guards (`admin`/`web`).

- **Livewire Action Permission Enforcement** ([EnforcePanelLivewireActionPermissions.php](file:///var/www/tallpbx/app/Http/Middleware/EnforcePanelLivewireActionPermissions.php)): Custom middleware gates every Livewire update request — not just page routes — preventing view-only users from invoking destructive actions. This is an advanced security measure many projects miss.

- **Cross-Tenant Mutation Guard**: [BaseEditComponent.php](file:///var/www/tallpbx/app/Support/BaseEditComponent.php#L77-L90) enforces tenant boundary isolation at the component level, aborting 403 on cross-tenant access attempts.

### Observations

- Only the `admin` module provider ([admin/ModuleServiceProvider.php](file:///var/www/tallpbx/app-modules/admin/src/Providers/ModuleServiceProvider.php)) doesn't extend the base `App\Support\ModuleServiceProvider` — documented and justified (289 lines of bespoke menu/permission setup). All other 58 modules properly extend the base class. ✅

- All modules have proper `composer.json` files. ✅

---

## 2. Code Quality

### Strengths ✅

- **`declare(strict_types=1)`** is present in all app core and module source PHP files. No violations found.

- **No `$guarded = []`** mass assignment vulnerabilities. All models use explicit `$fillable` arrays. ✅

- **No `DB::raw()` or `DB::unprepared()`** in application code (only in migration scripts where it's appropriate). ✅

- **No `env()` calls outside config files**. The few `getenv()` calls are in `SystemProcessRunner` (reading `PATH`), `TestCommand`, and `ConfigureInitialAdminCommand` (reading installer-provided credentials) — all appropriate. ✅

- **Consistent PHPDoc comments** on models, services, and middleware with clear, plain-language explanations. ✅

- **Good transaction usage**: 20+ `DB::transaction()` calls wrapping multi-step operations across services (`TenantService`, `UserService`, `ImpersonationService`, `GroupService`, etc.). ✅

- **Only 1 TODO/FIXME** in the entire codebase (a step comment in `SecurityConfigGenerator`). Very clean. ✅

### Issues ⚠️

#### DRY Violation: Duplicate `resolveViewName()` Method

[BaseListComponent.php](file:///var/www/tallpbx/app/Support/BaseListComponent.php#L69-L89) and [BaseEditComponent.php](file:///var/www/tallpbx/app/Support/BaseEditComponent.php#L138-L158) contain **identical** `resolveViewName()` implementations (21 lines each). This should be extracted to a shared trait like `Concerns\ResolvesModuleViews`.

#### `helpers.php` is Nearly Empty

[helpers.php](file:///var/www/tallpbx/app/helpers.php) contains only `declare(strict_types=1)` (32 bytes). If no helper functions are needed, the file and its `autoload.files` entry in `composer.json` should be removed to eliminate unnecessary autoload overhead.

#### Console Command Wrappers

[console.php](file:///var/www/tallpbx/routes/console.php) defines several `Artisan::command()` wrappers that simply `$this->call()` existing command classes (`ModuleCacheCommand`, `ModuleClearCommand`, etc.). Some have duplicate aliases (`module:cache` / `modules:cache`). Consider consolidating these.

---

## 3. Security ✅ Excellent

### Strengths

- **Two-Tier Privileged Execution**: Bounded sudoers helper pattern (`/usr/local/sbin/tallpbx-security`) with strict regex input validation, non-interactive execution, and atomic kernel ruleset compilation. No `sudo bash` or wildcard NOPASSWD. ✅

- **Dual Auth Guard Architecture**: Separate `admin` and `web` guards with `AuthPanelMiddleware` checking both. Impersonation properly handled. ✅

- **Livewire Action Gating**: The custom middleware prevents IDOR-style attacks via the shared update endpoint. ✅

- **Tenant Isolation**: `assertCanAccessTenantRecord()`, `ScopeTenant` middleware, and `TenantMutationGuard` enforce boundaries at multiple layers. ✅

- **Dusk Database Safety**: `DuskDatabaseSafety` refuses to boot against the primary database during test runs. ✅

- **Primary Database Safety**: `PrimaryDatabaseSafety` prevents accidental production data exposure. ✅

- **Security Module (4,585 LOC)**: Comprehensive intrusion detection, nftables firewall management, IPv6 dual-stack support, cryptographic ruleset verification, ban reconciliation, zero-lockout protection. Enterprise-grade for a PBX. ✅

- **Rate Limiting**: Login endpoints throttled at `10,1`, authenticated panel at `60,1`, tenant switch at `30,1`. ✅

### Observations

- `.env` is in `.gitignore`. ✅
- Admin password is hashed in the model cast. ✅
- Tenant users cannot acquire `admin.*` permissions even if malformed group assignments exist ([User.php:111](file:///var/www/tallpbx/app/Models/User.php#L111)). ✅

---

## 4. Testing ⚠️ Significant Gaps

> [!WARNING]
> Test coverage is the project's most significant weakness. While the framework infrastructure is solid (256 test files, ~37.6k lines), most modules have only skeleton coverage.

### Test Suite Status ✅ All Passing

| Metric | Value |
|---|---|
| Tests | **2,159** |
| Assertions | **9,171** |
| Failures | **0** |
| Duration | ~7.4 minutes |

### Test Distribution

| Category | Test Files |
|---|---|
| Feature/Modules/Security | 15 |
| Feature/Modules/FileStores | 9 |
| Feature/Modules/Backups | 5 |
| Feature/Modules/CallBroadcast | 4 |
| Feature/Livewire (admin/core) | 26 |
| Feature (cross-cutting) | ~23 |
| Unit tests | 4 directories |
| Browser (Dusk) tests | 7 |
| **Modules with only 1 test file** | **38 out of 48** |

### Key Gaps

1. **38 of 48 module test directories contain only 1 test file** — likely just a smoke/render test. Critical modules like `extensions`, `sip-trunks`, `inbound-routes`, `dialplans`, and `call-centers` have minimal coverage.

2. **No module has CRUD integration tests** beyond the Security, FileStores, Backups, and CallBroadcast modules. Most modules lack tests for create, update, delete, and tenant isolation operations.

3. **Browser (Dusk) tests are minimal** — only 7 files. For a complex UI-heavy application, this is very light.

4. **CI pipeline is manual-dispatch only** (`workflow_dispatch`). The changelog notes this was changed from automatic in v1.1.2. This means push/PR CI enforcement is effectively disabled — a significant regression from the stated policy in AGENTS.md.

### Recommendations

- Prioritize CRUD test coverage for high-risk modules: `extensions`, `sip-trunks`, `sip-profiles`, `inbound-routes`, `outbound-routes`, `dialplans`, `ring-groups`, `call-centers`
- Re-enable automatic CI triggers (at minimum for PRs to `main` and release branches)
- Add tenant isolation tests for every module that handles tenant-scoped data

---

## 5. Configuration & Environment

### .env vs .env.example Drift ⚠️

The active `.env` is **missing ~20 variables** that `.env.example` documents. Key missing entries:

| Category | Missing Variables |
|---|---|
| FreeSWITCH Database | `FREESWITCH_DATABASE_*` (9 vars) |
| FreeSWITCH Sofia | `FREESWITCH_SOFIA_*` (6 vars) |
| FreeSWITCH Misc | `FREESWITCH_SERVER`, `FREESWITCH_AUTO_CREATE_SCHEMAS`, etc. |
| Storage | `TALLPBX_BACKUP_ROOT`, `TALLPBX_MEDIA_ROOT` |
| Dev Mode | `FSPBX_DEVELOPMENT_MODE` |

The `.env` also has 2 variables not in `.env.example`:
- `FREESWITCH_XML_HANDLER_DIRECTORY_CACHE_STORE`
- `FREESWITCH_XML_HANDLER_LOG_TIMING`

> [!NOTE]
> Some of these may be intentional omissions for a development environment that isn't connected to live FreeSWITCH. The `.env.example` should be kept in sync regardless.

---

## 6. Internationalization ⚠️ Minor Gaps

Translation entry counts:
| Language | Entries | Delta from EN |
|---|---|---|
| English (en) | 1,298 | — |
| Spanish (es) | 1,283 | **-15** |
| French (fr) | 1,282 | **-16** |

There are 15–16 translation keys present in English but missing in Spanish and French. While this is a small gap (~1.2%), untranslated keys will fall back to English, creating a mixed-language UI for ES/FR users.

---

## 7. Documentation ✅ Strong

- **AGENTS.md** (54 KB): Exceptionally thorough — covers architecture, permission model, module creation patterns, CI policy, file permissions, security model, and coding standards.
- **CHANGELOG.md** (38 KB): Follows Keep a Changelog format, well-structured with detailed entries.
- **README.md** (27 KB), **INSTALL.md** (34 KB): Comprehensive user-facing documentation.
- **docs/security-architecture.md**: Detailed security architecture with Mermaid diagrams.
- **Skills directory** (`.agents/skills/`): 20+ skill files guiding AI agents on project conventions — unusual and forward-thinking.

---

## 8. Summary of Findings

### ✅ Excellent (No Action Required)

| Area | Status |
|---|---|
| Modular architecture | Clean, consistent, convention-driven |
| Security model | Enterprise-grade, multi-layer |
| Auth & tenant isolation | Properly enforced at middleware, component, and model layers |
| Code style | `strict_types`, PHPDoc, PSR-12, no mass assignment |
| Transaction safety | Consistently used in multi-step operations |
| Documentation | Comprehensive and well-maintained |
| Git hygiene | Clean working tree, proper tagging |

### ⚠️ Needs Attention

| Issue | Severity | Effort |
|---|---|---|
| **Module test coverage** — 38/48 modules have only 1 test file | High | Large |
| **CI pipeline disabled** — manual dispatch only | Medium | Small |
| **Translation parity** — 15-16 missing ES/FR keys | Low | Small |
| **Code duplication** — `resolveViewName()` in two base classes | Low | Small |
| **`.env` / `.env.example` drift** — ~20 missing variables | Low | Small |
| **Empty `helpers.php`** — unnecessary autoload entry | Low | Trivial |
| **Console command aliases** — duplicate `module:` / `modules:` wrappers | Low | Trivial |

---

## Recommended Priority Actions

1. ~~**CI triggers**~~: Resolved — `AGENTS.md` updated to reflect the intentional local-first testing workflow with on-demand CI dispatch.

2. **🟠 Medium**: Create CRUD + tenant isolation test suites for the top-10 highest-risk modules (extensions, SIP trunks, inbound/outbound routes, dialplans, ring groups, call centers, conferences, voicemails, gateways).

3. **🟡 Low**: Extract shared `resolveViewName()` to a trait, sync translation files, clean up `helpers.php` and console command aliases, sync `.env.example`.
